"""Finite public-bank materialization and refusal controls; no backend execution."""
from __future__ import annotations

import copy
import io
import json
import os
import runpy
import subprocess
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1] / '.github/scripts/local-repo-jit'
sys.path.insert(0, str(ROOT))
import public_bank as bank
import public_recipe


def deb(files, extra=()):
    stream = io.BytesIO()
    with tarfile.open(fileobj=stream, mode='w:xz') as archive:
        for name, data in [*files.items(), *extra]:
            member = tarfile.TarInfo('./' + name)
            member.size = len(data)
            archive.addfile(member, io.BytesIO(data))
    data = stream.getvalue()
    header = ('data.tar.xz/'.ljust(16) + '0'.ljust(12) + '0'.ljust(6) + '0'.ljust(6)
              + '100644'.ljust(8) + str(len(data)).ljust(10) + '`\n').encode()
    return b'!<arch>\n' + header + data + (b'\n' if len(data) % 2 else b'')


class PublicBankTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.sources = self.root / 'source'
        self.sources.mkdir()
        self.selected = {}
        self.generated = {'Dockerfile': b'FROM pinned\n', 'iso/provision.py': b'pass\n', 'outer.py': b'pass\n'}
        self.plan = {'recipe_sha256': bank.public_recipe_selection.select(bank.SELECTION)['recipe_sha256'],
                     'dependencies': {}, 'inputs': {}, 'generated': {}, 'contracts': {}, 'templates': {},
                     'docker_copy_inputs': ['outer.py', 'seed.iso']}
        for package, wanted in bank.TOOL_MEMBERS.items():
            path = self.sources / (package + '.deb')
            path.write_bytes(deb({name: b'ELF-' + target.encode() for name, target in wanted.items()}))
            if package != 'supplemental-libfaketime':
                self.selected[package] = public_recipe.RetainedFile(str(path), 'https://example.org/' + package)
                self.plan['dependencies']['toolchain/' + package + '.deb'] = {
                    'artifact': package, 'sha256': bank.runner_setup.sha(path)}
                self.plan['inputs'][package] = {'kind': 'toolchain', 'sha256': bank.runner_setup.sha(path)}
        runtime = self.sources / 'runtime'
        runtime.write_bytes(b'runtime')
        self.selected['runtime-image'] = public_recipe.RetainedFile(str(runtime), 'https://example.org/runtime')
        self.plan['inputs']['runtime-image'] = {'kind': 'runtime-image', 'sha256': bank.runner_setup.sha(runtime)}
        fixture = self.sources / 'alpine.tar'
        fixture.write_bytes(b'fixture')
        self.fixture = public_recipe.RetainedFile(str(fixture), 'https://example.org/fixture')
        self.plan['dependencies']['iso/native_policy.py'] = {'source': 'native_policy.py'}
        self.output = self.root / 'bank'
        private = self.root / 'private'
        private.mkdir(mode=0o700)
        for name in ('home', 'gnupg', 'tmp'):
            (private / name).mkdir(mode=0o700)
        self.wrapper = {'private_root': str(private),
                        'environment': {'HOME': '/private/home', 'GNUPGHOME': '/private/gnupg', 'TMPDIR': '/private/tmp'},
                        'overlays': [{'target': '/private', 'source': str(private), 'read_only': False}]}
        self.addCleanup(mock.patch.stopall)
        profile = bank.public_recipe_selection.select(bank.SELECTION)
        profile['alpine_sha256'] = bank.runner_setup.sha(fixture)
        mock.patch.object(bank.public_recipe_selection, 'select', return_value=profile).start()
        self.generator = mock.patch.object(bank.runner_setup, 'public_recipe_preflight',
                                           side_effect=lambda *a, **k: (copy.deepcopy(self.generated), copy.deepcopy(self.plan))).start()
        mock.patch.object(bank.time, 'sleep').start()
        mock.patch.object(bank, 'normalize_iso', return_value={'normalized': 'fixture'}).start()
        self.process = mock.patch.object(bank.subprocess, 'run', side_effect=self.iso).start()

    def iso(self, command, *, cwd, env, **kwargs):
        self.assertEqual(command[1:3], ['--inhibit-cache', '--library-path'])
        self.assertEqual(set(env), {'HOME', 'GNUPGHOME', 'TMPDIR', 'GENISOIMAGERC', 'LC_ALL', 'TZ'})
        self.assertEqual(kwargs['timeout'], 90)
        path = Path(cwd) / command[command.index('-output') + 1]
        path.write_bytes(b'iso-fixture')
        return subprocess.CompletedProcess(command, 0, b'iso generated\n')

    def prepare(self):
        return bank.prepare(b'descriptor', self.selected, self.fixture)

    def materialize(self):
        return bank.materialize(self.output, b'descriptor', self.selected, self.fixture, self.wrapper)

    def preflight(self, identity):
        return bank.preflight(self.output, expected_manifest_sha256=identity['manifest_sha256'],
                              raw=b'descriptor', selected=self.selected, fixture=self.fixture,
                              wrapper=self.wrapper)

    def test_actual_pipeline_seals_copies_and_refuses_manifest_drift(self):
        identity = self.materialize()
        receipt = self.preflight(identity)
        self.assertTrue(receipt['materialization_ready'])
        self.assertFalse(receipt['execution_allowed'])
        self.assertFalse(receipt['runtime_qualified'])
        self.assertEqual(self.process.call_count, 2)
        self.assertFalse(receipt['native_admission']['matches'])
        self.assertEqual(receipt['native_admission']['required_count'], 50)
        self.assertEqual(receipt['context_inputs'], receipt['native_admission']['proposed_count'])
        self.assertEqual(len(bank.tree_seal(self.output / 'runtime')), 1)
        self.assertFalse((self.output / 'repeat.iso').exists())
        for path in (self.output / 'context').rglob('*'):
            if path.is_file():
                self.assertEqual(path.stat().st_nlink, 1)
                self.assertEqual(path.stat().st_mode & 0o777, 0o444)
        with self.assertRaisesRegex(ValueError, 'manifest changed'):
            self.preflight({**identity, 'manifest_sha256': '0' * 64})

    def test_refuses_non_ascii_iso_path(self):
        self.generated['iso/café'] = b'bad'
        with self.assertRaisesRegex(ValueError, 'ASCII ISO path'):
            self.prepare()

    def test_tree_refuses_directory_alias(self):
        alias = self.root / 'alias'
        alias.symlink_to(self.sources, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'canonical bank directory'):
            bank.tree_seal(alias)

    def test_staged_tool_drift_and_host_preload_refused(self):
        original = bank.finish_staging
        with mock.patch.object(bank, 'finish_staging'):
            self.materialize()
        target = self.output / 'tools/genisoimage'
        saved = target.read_bytes()
        target.chmod(0o600)
        target.write_bytes(b'changed')
        with self.assertRaisesRegex(ValueError, 'staged closure differs'):
            original(self.output, b'descriptor', self.selected, self.fixture, self.wrapper)
        target.write_bytes(saved)
        target.chmod(0o555)
        with mock.patch.object(bank.os.path, 'lexists', return_value=True), self.assertRaisesRegex(ValueError, 'host loader configuration'):
            original(self.output, b'descriptor', self.selected, self.fixture, self.wrapper)

    def test_prepare_refuses_copy_collision(self):
        self.plan['dependencies']['outer.py'] = next(iter(self.plan['dependencies'].values()))
        with self.assertRaisesRegex(ValueError, 'copy collision'):
            self.prepare()

    def test_prepare_refuses_parent_shadow_and_reserved_seed(self):
        for name in ('outer.py/child', 'seed.iso'):
            with self.subTest(name=name):
                self.generated[name] = b'bad'
                with self.assertRaisesRegex(ValueError, 'path shadowed'):
                    self.prepare()
                self.generated.pop(name)

    def test_prepare_refuses_unresolved_copy(self):
        self.plan['docker_copy_inputs'].append('absent')
        with self.assertRaisesRegex(ValueError, 'COPY closure missing'):
            self.prepare()

    def test_regular_refuses_symlink_and_hardlink(self):
        path = self.sources / 'linked'
        path.symlink_to(self.sources / 'runtime')
        with self.assertRaises(ValueError):
            bank.regular(path)
        path.unlink()
        os.link(self.sources / 'runtime', path)
        with self.assertRaisesRegex(ValueError, 'linked input'):
            bank.regular(path)

    def test_materialize_refuses_existing_and_source_output(self):
        for path in (self.sources, self.sources / 'tool-libc6.deb' / 'child'):
            with self.subTest(path=path), self.assertRaises(ValueError):
                bank.materialize(path, b'descriptor', self.selected, self.fixture, self.wrapper)

    def test_materialize_refuses_noncanonical_output(self):
        for path in (Path('relative'), self.root / 'foo' / '..' / 'bank', ROOT / 'bank'):
            with self.subTest(path=path), self.assertRaisesRegex(ValueError, 'isolated'):
                bank.materialize(path, b'descriptor', self.selected, self.fixture, self.wrapper)

    def test_repeat_must_be_actual_equal_outputs(self):
        counter = 0
        def differs(command, **kwargs):
            nonlocal counter
            result = self.iso(command, **kwargs)
            counter += 1
            if counter == 2:
                (Path(kwargs['cwd']) / '../../repeat.iso').write_bytes(b'different')
            return result
        self.process.side_effect = differs
        with self.assertRaisesRegex(ValueError, 'repeated ISO generation differs'):
            self.materialize()

    def test_process_failure_retains_failure(self):
        self.process.side_effect = subprocess.CalledProcessError(2, 'genisoimage', output=b'fail')
        with self.assertRaises(subprocess.CalledProcessError):
            self.materialize()
        self.assertFalse((self.output / 'manifest.json').exists())

    def test_context_drift_during_generation(self):
        def drift(command, **kwargs):
            result = self.iso(command, **kwargs)
            (self.output / 'context/extra').write_bytes(b'shadow')
            return result
        self.process.side_effect = drift
        with self.assertRaisesRegex(ValueError, 'actual context differs'):
            self.materialize()

    def test_source_config_changes_during_staging(self):
        def drift(command, **kwargs):
            result = self.iso(command, **kwargs)
            self.plan['contracts']['changed'] = 'different'
            return result
        self.process.side_effect = drift
        with self.assertRaisesRegex(ValueError, 'changed while staging'):
            self.materialize()

    def test_missing_extra_symlink_and_content_drift_refused(self):
        identity = self.materialize()
        path = self.output / 'context/outer.py'
        content = path.read_bytes()
        for control in ('missing', 'shadowed', 'symlink', 'drift'):
            with self.subTest(control=control):
                if control == 'missing':
                    path.unlink()
                elif control == 'shadowed':
                    (self.output / 'context/shadow').write_bytes(b'extra')
                elif control == 'symlink':
                    path.unlink()
                    path.symlink_to(self.sources / 'runtime')
                else:
                    path.chmod(0o600)
                    path.write_bytes(b'drift')
                with self.assertRaises((ValueError, FileNotFoundError)):
                    self.preflight(identity)
                if path.is_symlink() or not path.exists():
                    path.unlink(missing_ok=True)
                    path.write_bytes(content)
                else:
                    path.chmod(0o600)
                    path.write_bytes(content)
                path.chmod(0o444)
                (self.output / 'context/shadow').unlink(missing_ok=True)
        self.assertTrue(self.preflight(identity)['materialization_ready'])

    def test_missing_shadowed_readonly_private_state_refused(self):
        identity = self.materialize()
        for overlays in ([], [dict(self.wrapper['overlays'][0], read_only=True)],
                         [*self.wrapper['overlays'], {'target': '/private', 'source': str(self.sources), 'read_only': True}]):
            with self.subTest(overlays=overlays):
                original = self.wrapper['overlays']
                self.wrapper['overlays'] = overlays
                with self.assertRaisesRegex(ValueError, 'private state missing or readonly'):
                    self.preflight(identity)
                self.wrapper['overlays'] = original

    def test_selected_config_drift_refused(self):
        identity = self.materialize()
        self.plan['contracts']['new'] = 'changed'
        with self.assertRaisesRegex(ValueError, 'selected source/config/tool drift'):
            self.preflight(identity)

    def test_forged_context_binding_refused_even_with_reselected_manifest(self):
        identity = self.materialize()
        manifest_path = self.output / 'manifest.json'
        manifest = json.loads(manifest_path.read_bytes())
        manifest['context_seal_sha256'] = '0' * 64
        manifest_path.chmod(0o600)
        manifest_path.write_bytes(bank.canonical(manifest))
        with self.assertRaisesRegex(ValueError, 'input drift|actual context/ISO seal changed'):
            self.preflight({**identity, 'manifest_sha256': bank.runner_setup.sha(manifest_path)})

    def test_reselected_manifest_cannot_launder_source_or_tool_drift(self):
        identity = self.materialize()
        for relative in ('context/outer.py', 'tools/genisoimage', 'runtime/blobs/sha256/' + bank.digest(b'runtime')):
            with self.subTest(relative=relative):
                target = self.output / relative
                original = target.read_bytes()
                target.chmod(0o600)
                target.write_bytes(b'forged')
                path = self.output / 'manifest.json'
                manifest = json.loads(path.read_bytes())
                manifest['files'][relative] = bank.digest(b'forged')
                path.chmod(0o600)
                path.write_bytes(bank.canonical(manifest))
                with self.assertRaisesRegex(ValueError, 'input drift'):
                    self.preflight({**identity, 'manifest_sha256': bank.runner_setup.sha(path)})
                target.write_bytes(original)
                target.chmod(0o444)
                manifest['files'][relative] = bank.digest(original)
                path.write_bytes(bank.canonical(manifest))

    def test_repeat_count_and_closure_seal_refusals(self):
        self.materialize()
        path = self.output / 'manifest.json'
        original = json.loads(path.read_bytes())
        for control in ('repeat-count', 'closure-seal'):
            manifest = copy.deepcopy(original)
            if control == 'repeat-count':
                manifest['iso_repeat']['count'] = 1
            else:
                seal = self.output / 'context/context-seal.json'
                record = json.loads(seal.read_bytes())
                record['outer.py'] = '0' * 64
                seal.chmod(0o600)
                seal.write_bytes(bank.canonical(record))
                manifest['context_seal_sha256'] = bank.runner_setup.sha(seal)
                manifest['files']['context/context-seal.json'] = bank.runner_setup.sha(seal)
            path.chmod(0o600)
            path.write_bytes(bank.canonical(manifest))
            with self.assertRaisesRegex(ValueError, 'actual context/ISO seal changed'):
                self.preflight({'manifest_sha256': bank.runner_setup.sha(path)})

    def test_tree_refuses_special_file(self):
        tree = self.root / 'tree'
        tree.mkdir()
        (tree / 'regular').write_bytes(b'ok')
        os.mkfifo(tree / 'pipe')
        with self.assertRaisesRegex(ValueError, 'shadowed input'):
            bank.tree_seal(tree)

    def test_deb_member_bounds_and_aliases(self):
        for value, wanted in ((b'!<arch>\n', {'wanted'}),
                              (deb({'wanted': b'yes'}).replace(b'data.tar.xz/', b'other.tar.x/'), {'wanted'}), (b'bad', {'wanted'}), (b'!<arch>\nshort', {'wanted'}),
                              (deb({'wanted': b'data'})[:-10], {'wanted'}),
                              (deb({'different': b'data'}), {'wanted'}),
                              (deb({'wanted': b'data'}, [('wanted', b'duplicate')]), {'wanted'}),
                              (deb({'wanted': b''}), {'wanted'})):
            with self.subTest(value=value[:20]):
                path = self.root / 'bad.deb'
                path.write_bytes(value)
                with self.assertRaises(ValueError):
                    bank.deb_members(path, wanted)

    def test_cli_materialize_and_preflight(self):
        retained = self.root / 'retained.json'
        retained.write_bytes(bank.canonical({name: {'path': row.path, 'origin': row.origin}
                                             for name, row in self.selected.items()}))
        wrapper = self.root / 'wrapper.json'
        wrapper.write_bytes(bank.canonical(self.wrapper))
        descriptor = self.root / 'descriptor.json'
        descriptor.write_bytes(b'descriptor')
        arguments = ['--descriptor', str(descriptor), '--retained', str(retained), '--fixture', self.fixture.path,
                     '--wrapper-state', str(wrapper), '--bank', str(self.output)]
        with mock.patch.object(sys, 'argv', ['public_bank', 'materialize', *arguments]), mock.patch('builtins.print') as output:
            bank.main()
        identity = json.loads(output.call_args.args[0])
        for action, extra, error in [('preflight', [], 'selected manifest'),
                                     ('materialize', ['--manifest-sha256', 'f' * 64], 'NEW proposed')]:
            with mock.patch.object(sys, 'argv', ['public_bank', action, *arguments, *extra]), self.assertRaisesRegex(ValueError, error):
                bank.main()
        with mock.patch.object(sys, 'argv', ['public_bank', 'preflight', *arguments, '--manifest-sha256', identity['manifest_sha256']]), mock.patch('builtins.print') as output:
            bank.main()
        self.assertTrue(json.loads(output.call_args.args[0])['materialization_ready'])
        with mock.patch.object(sys, 'argv', ['public_bank', '--help']), mock.patch('sys.stdout', new=io.StringIO()), self.assertRaises(SystemExit) as stopped:
            runpy.run_path(str(ROOT / 'public_bank.py'), run_name='__main__')
        self.assertEqual(stopped.exception.code, 0)


def both(value):
    return value.to_bytes(4, 'little') + value.to_bytes(4, 'big')


def iso_fixture():
    """Small ISO9660+Joliet image with explicit RR short/long timestamps."""
    data = bytearray(24 * 2048)
    stamp = bytes((126, 11, 9, 20, 0, 0, 0))
    long_stamp = b'2026110920000000\0'
    def record(name, extent, size, directory=False, system=b''):
        count = 33 + len(name) + (len(name) % 2 == 0)
        value = bytearray(count + len(system))
        value[0] = len(value)
        value[2:10], value[10:18] = both(extent), both(size)
        value[18:25], value[25], value[32] = stamp, 2 if directory else 0, len(name)
        value[33:33 + len(name)] = name
        value[count:] = system
        return value
    continuation = b'TF' + bytes((22, 1, 130)) + long_stamp
    data[23 * 2048:23 * 2048 + len(continuation)] = continuation
    ce = b'CE' + bytes((28, 1)) + both(23) + both(0) + both(len(continuation))
    tf = b'TF' + bytes((12, 1, 2)) + stamp
    for descriptor, kind, block in ((16, 1, 20), (17, 2, 21)):
        offset = descriptor * 2048
        data[offset:offset + 7] = bytes((kind,)) + b'CD001\1'
        if kind == 2:
            data[offset + 88:offset + 91] = b'%/E'
        data[offset + 156:offset + 190] = record(b'\0', block, 2048, True)
        for field in (813, 830):
            data[offset + field:offset + field + 17] = long_stamp
        for field in (847, 864):
            data[offset + field:offset + field + 17] = b'0' * 16 + b'\0'
        name = 'note.txt;1'.encode('utf-16-be') if kind == 2 else b'NOTE.TXT;1'
        records = (record(b'\0', block, 2048, True, ce if kind == 1 else b''),
                   record(b'\1', block, 2048, True), record(name, 22, 4, system=tf if kind == 1 else b''))
        position = block * 2048
        for entry in records:
            data[position:position + len(entry)] = entry
            position += len(entry)
    data[18 * 2048:18 * 2048 + 7] = b'\xffCD001\1'
    data[22 * 2048:22 * 2048 + 4] = b'one\n'
    return data


class IsoNormalizationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / 'source'
        self.source.mkdir()
        (self.source / 'note.txt').write_bytes(b'one\n')
        self.image = self.root / 'image.iso'
        self.data = iso_fixture()

    def normalize(self, data=None):
        self.image.write_bytes(self.data if data is None else data)
        return bank.normalize_iso(self.image, self.source)

    def test_normalization_is_exact_and_idempotent(self):
        result = self.normalize()
        normalized = self.image.read_bytes()
        self.assertNotEqual(result['raw_sha256'], result['normalized_sha256'])
        self.assertEqual(result['member_count'], 1)
        self.assertEqual(result['timestamp_fields'], 14)
        readonly = bank.normalize_iso(self.image, self.source, apply=False)
        self.assertEqual(readonly['raw_sha256'], result['normalized_sha256'])
        other = self.normalize(normalized)
        self.assertEqual(other['raw_sha256'], other['normalized_sha256'])
        self.assertEqual(other['normalized_sha256'], result['normalized_sha256'])
        self.assertEqual(other['field_map_sha256'], result['field_map_sha256'])
        # Every changed byte belongs to a defined metadata timestamp, never file content.
        self.assertEqual(normalized[22 * 2048:23 * 2048], self.data[22 * 2048:23 * 2048])

    def test_refuses_out_of_bounds_and_mismatched_extents(self):
        for value in (both(999), b'\1\0\0\0\0\0\0\2'):
            data = bytearray(self.data)
            data[16 * 2048 + 158:16 * 2048 + 166] = value
            with self.subTest(value=value), self.assertRaisesRegex(ValueError, 'extent|integer'):
                self.normalize(data)

    def test_readonly_inspection_refuses_unnormalized_time(self):
        self.image.write_bytes(self.data)
        with self.assertRaisesRegex(ValueError, 'canonical timestamp drift'):
            bank.normalize_iso(self.image, self.source, apply=False)
        self.assertEqual(self.image.read_bytes(), self.data)

    def test_timestamp_overlap_refuses_before_any_write(self):
        stream = io.BytesIO(b'original')
        with self.assertRaisesRegex(ValueError, 'fields overlap'):
            bank.apply_timestamps(stream, {1: b'123', 2: b'123'})
        self.assertEqual(stream.getvalue(), b'original')

    def test_short_read_refused(self):
        self.image.write_bytes(self.data)
        class ShortRead(io.BytesIO):
            def read(self, count=-1):
                return super().read(max(count - 1, 0))
        original = Path.open
        def open_path(path, mode='r', *args, **kwargs):
            return ShortRead(self.data) if mode == 'r+b' else original(path, mode, *args, **kwargs)
        with mock.patch.object(Path, 'open', open_path), self.assertRaisesRegex(ValueError, 'short read'):
            bank.normalize_iso(self.image, self.source)

    def test_continuation_cycles_refused(self):
        self.data[20 * 2048 + 34 + 20:20 * 2048 + 34 + 28] = both(28)
        self.data[23 * 2048:23 * 2048 + 28] = b'CE' + bytes((28, 1)) + both(23) + both(0) + both(28)
        with self.assertRaisesRegex(ValueError, 'continuation cycle'):
            self.normalize()

    def test_directory_and_descriptor_bounds_refused(self):
        for attribute, value, message in [('MAX_ISO_DIRECTORIES', 1, 'directory bound'),
                                          ('MAX_DESCRIPTOR_SECTORS', 18, 'descriptor set')]:
            with mock.patch.object(bank, attribute, value), self.assertRaisesRegex(ValueError, message):
                self.normalize()

    def test_system_use_padding_and_unknown_record(self):
        for value in (b'\0' * 4, b'ZZ\x1c\x01'):
            data = bytearray(self.data)
            data[20 * 2048 + 34:20 * 2048 + 38] = value
            self.assertEqual(self.normalize(data)['member_count'], 1)

    def test_directory_alias_cycle_and_duplicate_member(self):
        for duplicate in (False, True):
            data = bytearray(self.data)
            for block in (20, 21):
                cursor = block * 2048
                starts = []
                while data[cursor]:
                    starts.append(cursor)
                    cursor += data[cursor]
                base = starts[-1] if duplicate else starts[1]
                record = bytearray(data[base:base + data[base]])
                if not duplicate:
                    record[33] = ord('X') if block == 20 else 0
                    if block == 21:
                        record[32] = 2
                        record[0] = 36
                        record.extend(b'X\0')
                data[cursor:cursor + len(record)] = record
            if duplicate:
                with self.assertRaisesRegex(ValueError, 'duplicate member'):
                    self.normalize(data)
            else:
                self.assertEqual(self.normalize(data)['member_count'], 1)

    def test_refuses_member_content_drift(self):
        self.data[22 * 2048] ^= 1
        with self.assertRaisesRegex(ValueError, 'exact ISO members/content'):
            self.normalize()

    def test_refuses_descriptor_and_timestamp_malformations(self):
        for offset, value, message in ((16 * 2048, b'\3', 'volume descriptor'),
                                       (17 * 2048 + 88, b'bad', 'Joliet'),
                                       (16 * 2048 + 813, b'x', 'long timestamp'),
                                       (20 * 2048 + 19, b'\0', 'short timestamp'),
                                       (20 * 2048, b'\3', 'directory record')):
            data = bytearray(self.data)
            data[offset:offset + len(value)] = value
            with self.subTest(message=message), self.assertRaisesRegex(ValueError, message):
                self.normalize(data)

    def test_refuses_continuation_and_time_record_malformations(self):
        for offset, value, message in ((20 * 2048 + 36, b'\3', 'system-use'),
                                       (20 * 2048 + 36, b'\x1b', 'continuation length'),
                                       (23 * 2048 + 2, b'\4', 'time flags'),
                                       (23 * 2048 + 4, b'\x83', 'time length')):
            data = bytearray(self.data)
            data[offset:offset + len(value)] = value
            with self.subTest(message=message), self.assertRaisesRegex(ValueError, message):
                self.normalize(data)


if __name__ == '__main__':
    unittest.main()
