"""Maintained capacity context consumer, injected ISO only; no runtime transport."""
import contextlib
import hashlib
import io
import json
import os
import tarfile
import unittest
from pathlib import Path
from unittest.mock import patch

import test_local_repo_jit_docker as fixtures


class Context(unittest.TestCase):
    def setUp(self):
        self.case = fixtures.PublicSeed()
        self.addCleanup(self.case.doCleanups)
        self.case.setUp()
        self.output = self.case.root / 'prepared'

    def stage(self):
        with patch.object(fixtures.s.subprocess, 'run', side_effect=self.case.iso):
            fixtures.s.stage(self.case.archive, self.output)

    def test_coupled_growth_serial_marker_helpers_and_source_preservation(self):
        source = self.case.source
        before = {str(p.relative_to(source)): fixtures.s.sha(p) for p in source.rglob('*') if p.is_file()}
        self.stage()
        self.assertEqual(before, {str(p.relative_to(source)): fixtures.s.sha(p) for p in source.rglob('*') if p.is_file()})
        config = json.loads((self.output / 'iso/user-data').read_text().split('\n', 1)[1])
        self.assertEqual(config['growpart'], {'mode': 'auto', 'devices': ['/'], 'ignore_growroot_disabled': False})
        self.assertIs(config['resize_rootfs'], True)
        outer = (self.output / 'outer.py').read_text()
        self.assertIn('ringbuf,id=ci-serial,size=65536', outer)
        self.assertIn('fresh_overlay(', outer)
        self.assertIn('standalone(', outer)
        provision = (self.output / 'iso/provision.py').read_text()
        self.assertIn('MYGUARD_CI_BOUNDARY:', provision)
        self.assertLess(provision.index('guest_capacity.root_ready()'), provision.index('/mnt/install-runner.py'))
        installer = (self.output / 'iso/install-runner.py').read_text()
        self.assertLess(installer.index('guest_capacity.admit('), installer.index('target.mkdir'))
        self.assertLess(installer.index('guest_capacity.verify_extracted'), installer.index('native_policy.verify'))
        for name in ['owned_overlay.py', 'baseline_budget.py', 'guest_capacity.py', 'failure_observation.py']:
            self.assertEqual((self.output / name).read_bytes(), (fixtures.ROOT / name).read_bytes())
            self.assertIn(name, (self.output / 'Dockerfile').read_text())
        fixtures.s.verify_materialized(self.output)
        (self.output / 'guest_capacity.py').write_text('drift')
        with self.assertRaisesRegex(ValueError, 'drift'):
            fixtures.s.verify_materialized(self.output)

    def test_midstage_source_drift_refuses_before_seal(self):
        def changed(argv, **kwargs):
            self.case.iso(argv, **kwargs)
            (self.case.source / 'public-disk').write_bytes(b'changed')
        with patch.object(fixtures.s.subprocess, 'run', side_effect=changed), self.assertRaisesRegex(ValueError, 'public input changed'):
            fixtures.s.stage(self.case.archive, self.output)
        self.assertFalse((self.output / 'context-seal.json').exists())

    def test_host_budget_boundary(self):
        from types import SimpleNamespace
        required = 3 * fixtures.s.budget.VIRTUAL_BYTES + fixtures.s.GIB + 14
        self.assertEqual(fixtures.s.host_ready({'source_logical_bytes': 7}, lambda _: SimpleNamespace(f_bavail=required, f_frsize=1)), required)
        with self.assertRaisesRegex(ValueError, 'host capacity'):
            fixtures.s.host_ready({'source_logical_bytes': 7}, lambda _: SimpleNamespace(f_bavail=required - 1, f_frsize=1))

    def test_transform_refuses_missing_or_duplicate_preimage(self):
        for text in ('absent', 'old old'):
            with self.subTest(text=text), self.assertRaisesRegex(ValueError, 'preimage changed'):
                fixtures.s.replace(text, 'old', 'new')

    def test_resealed_helper_drift_still_refused(self):
        self.stage()
        seal_path = self.output / 'context-seal.json'
        original_seal = seal_path.read_text()
        for name, message in [('owned_overlay.py', 'maintained capacity helper drift'),
                              ('iso/guest_capacity.py', 'guest capacity helper drift')]:
            target = self.output / name
            original = target.read_bytes()
            try:
                target.write_bytes(original + b'\n# drift\n')
                seal = json.loads(original_seal)
                seal[name] = fixtures.s.sha(target)
                seal_path.write_text(json.dumps(seal))
                with self.subTest(name=name), self.assertRaisesRegex(ValueError, message):
                    fixtures.s.verify_materialized(self.output)
            finally:
                target.write_bytes(original)
                seal_path.write_text(original_seal)
        fixtures.s.verify_materialized(self.output)

    def reseal_source(self):
        self.case.seal()
        item = patch.object(fixtures.s, 'Q2_MANIFEST', fixtures.s.sha(self.case.q2 / 'manifest.json'))
        item.start()
        self.addCleanup(item.stop)

    def preflight(self, **kwargs):
        return fixtures.s.materialization_preflight(self.case.q2, self.case.archive, **kwargs)

    def wrapper(self):
        private = self.case.root / 'private'
        private.mkdir(mode=0o700)
        for name in ('home', 'gpg', 'tmp'):
            (private / name).mkdir(mode=0o700)
        return {'private_root': str(private),
                'environment': {'HOME': '/private/home', 'GNUPGHOME': '/private/gpg', 'TMPDIR': '/private/tmp'},
                'overlays': [{'target': '/private', 'source': str(private), 'read_only': False}]}

    def test_preflight_no_staging_exact_generated_closure_and_wrapper(self):
        wrapper = self.wrapper()
        with patch.object(fixtures.s.subprocess, 'run', side_effect=AssertionError('execution forbidden')):
            source, seal, generated, receipt = self.preflight(wrapper=wrapper)
        self.assertEqual(source, self.case.source)
        self.assertEqual(seal, json.loads((source / 'context-seal.json').read_text()))
        self.assertFalse(self.output.exists())
        self.assertFalse(receipt['runtime_qualified'])
        self.assertFalse(receipt['execution_allowed'])
        self.assertEqual(set(receipt['wrapper_state']), {'HOME', 'GNUPGHOME', 'TMPDIR'})
        self.assertEqual(receipt['candidate_setup_sha256'], fixtures.s.sha(Path(fixtures.s.__file__)))
        self.assertIn('seed.iso', receipt['docker_copy_inputs'])
        self.assertIn('guest_capacity', receipt['python_imports']['iso/install-runner.py'])
        self.assertEqual(receipt['closure']['iso/' + fixtures.s.ARCHIVE], fixtures.s.ARCHIVE_SHA)
        for name, body in generated.items():
            self.assertEqual(receipt['closure'][name], hashlib.sha256(body).hexdigest())
        self.assertIsNone(self.preflight()[3]['wrapper_state'])

    def test_source_copy_is_independent_and_mutation_cannot_reach_bank(self):
        self.stage()
        for name in ['public-disk', 'base.qcow2', 'base.sha256']:
            target, source = self.output / name, self.case.source / name
            self.assertNotEqual(target.stat().st_ino, source.stat().st_ino)
            before = source.read_bytes()
            target.write_bytes(b'mutated private output')
            self.assertEqual(source.read_bytes(), before)

    def test_resealed_generated_outer_and_plan_drift_are_refused(self):
        self.stage()
        for name in ['outer.py', 'qga.py', 'Dockerfile', 'iso/install-runner.py', 'iso/native_policy.py', 'source-preflight.json']:
            target = self.output / name
            original = target.read_bytes()
            original_seal = (self.output / 'context-seal.json').read_bytes()
            try:
                if name == 'source-preflight.json':
                    data = json.loads(original)
                    data['closure']['outer.py'] = '0' * 64
                    target.write_text(json.dumps(data))
                else:
                    target.write_bytes(original + b'\n# resealed drift\n')
                seal = json.loads(original_seal)
                seal[name] = fixtures.s.sha(target)
                (self.output / 'context-seal.json').write_text(json.dumps(seal))
                with self.subTest(name=name), self.assertRaisesRegex(ValueError, 'source-bound generated'):
                    fixtures.s.verify_materialized(self.output)
            finally:
                target.write_bytes(original)
                (self.output / 'context-seal.json').write_bytes(original_seal)
        fixtures.s.verify_materialized(self.output)

    def test_missing_bank_and_archive_refuse_before_staging(self):
        with self.assertRaisesRegex(ValueError, 'explicit public input'):
            fixtures.s.materialization_preflight(None, self.case.archive)
        for bank, archive in [(self.case.root / 'absent', self.case.archive),
                              (self.case.q2, self.case.root / 'missing-archive')]:
            with self.subTest(bank=bank, archive=archive), self.assertRaises(FileNotFoundError):
                fixtures.s.materialization_preflight(bank, archive)
        self.assertFalse(self.output.exists())

    def test_regular_input_refuses_relative_directory_and_symlink(self):
        alias = self.case.root / 'alias'
        alias.symlink_to(self.case.archive)
        for path in [Path('relative'), self.case.root, alias]:
            with self.subTest(path=path), self.assertRaises((ValueError, FileNotFoundError)):
                fixtures.s.regular_input(path)

    def test_public_record_size_duplicate_shape_and_path_boundaries(self):
        record = self.case.root / 'record'
        for body in ['{}', '[]', '{"a":1,"a":2}', 'x' * (1024 * 1024 + 1)]:
            record.write_text(body)
            with self.subTest(size=len(body)), self.assertRaises(ValueError):
                fixtures.s.public_record(record)
        for name in ['', '/absolute', '../escape', 'a/../escape', 'a//b', './a', 'a\\b', 1]:
            with self.subTest(name=name), self.assertRaises(ValueError):
                fixtures.s.relative_input(name)
        self.assertEqual(fixtures.s.relative_input('iso/leaf'), 'iso/leaf')

    def test_bad_manifest_archive_seal_and_digest(self):
        with patch.object(fixtures.s, 'Q2_MANIFEST', '0' * 64), self.assertRaisesRegex(ValueError, 'pinned runner'):
            self.preflight()
        with patch.object(fixtures.s, 'ARCHIVE_SHA', '0' * 64), self.assertRaisesRegex(ValueError, 'pinned runner'):
            self.preflight()
        seal = self.case.source / 'context-seal.json'
        original = seal.read_text()
        seal.write_text('{}')
        with self.assertRaisesRegex(ValueError, 'context seal'):
            self.preflight()
        for digest in [None, 'not-hex']:
            data = json.loads(original)
            data['public-disk'] = digest
            seal.write_text(json.dumps(data))
            manifest = self.case.q2 / 'manifest.json'
            manifest.write_text(json.dumps({'build-provision-q1/context-seal.json': {'sha256': fixtures.s.sha(seal)}}))
            with patch.object(fixtures.s, 'Q2_MANIFEST', fixtures.s.sha(manifest)), self.assertRaisesRegex(ValueError, 'fingerprint refused'):
                self.preflight()

    def test_unsealed_special_missing_and_symlink_inputs_refused(self):
        extra = self.case.source / 'extra'
        extra.write_bytes(b'unsealed')
        with self.assertRaisesRegex(ValueError, 'missing or shadowed'):
            self.preflight()
        extra.unlink()
        os.mkfifo(extra)
        with self.assertRaisesRegex(ValueError, 'missing or shadowed'):
            self.preflight()
        extra.unlink()
        extra.symlink_to(self.case.archive)
        with self.assertRaisesRegex(ValueError, 'missing or shadowed'):
            self.preflight()
        extra.unlink()
        (self.case.source / 'public-disk').unlink()
        with self.assertRaises(FileNotFoundError):
            self.preflight()

    def test_context_drift_refused_before_copy_or_iso(self):
        (self.case.source / 'public-disk').write_bytes(b'drift')
        with (patch.object(fixtures.s, 'copy_public') as copied,
              patch.object(fixtures.s.subprocess, 'run') as iso,
              self.assertRaisesRegex(ValueError, 'context changed')):
            fixtures.s.stage(self.case.archive, self.output)
        copied.assert_not_called()
        iso.assert_not_called()
        self.assertFalse(self.output.exists())

    def archive_variant(self, members):
        with tarfile.open(self.case.archive, 'w:gz') as payload:
            for name, kind, body in members:
                member = tarfile.TarInfo(name)
                member.type = kind
                member.size = len(body)
                payload.addfile(member, io.BytesIO(body) if kind == tarfile.REGTYPE else None)
        item = patch.object(fixtures.s, 'ARCHIVE_SHA', fixtures.s.sha(self.case.archive))
        item.start()
        self.addCleanup(item.stop)

    def test_archive_unsafe_duplicate_link_and_missing_payload_refused(self):
        for members, message in [([('../escape', tarfile.REGTYPE, b'x')], 'relative path'),
                                 ([('run.sh', tarfile.SYMTYPE, b'')], 'alias or special'),
                                 ([('run.sh', tarfile.REGTYPE, b'x')] * 2, 'alias or special'),
                                 ([('other', tarfile.REGTYPE, b'x')], 'member missing'),
                                 ([('run.sh', tarfile.DIRTYPE, b'')], 'member missing'),
                                 ([('run.sh', tarfile.REGTYPE, b'x')], 'fingerprint changed')]:
            self.archive_variant(members)
            with self.subTest(message=message), self.assertRaisesRegex(ValueError, message):
                self.preflight()

    def test_archive_root_directory_and_dot_prefix_supported(self):
        self.archive_variant([('./', tarfile.DIRTYPE, b'')] + [('./' + name, tarfile.REGTYPE, body) for name, body in self.case.files.items()])
        self.preflight()

    def test_generated_missing_shadowed_syntax_docker_controls_before_copy(self):
        docker = self.case.source / 'Dockerfile'
        original = docker.read_text()
        for line, message in [('COPY missing /opt/vm/\n', 'Dockerfile source missing'),
                              ('ADD remote /opt/vm/\n', 'unresolved Dockerfile'),
                              ('COPY one\n', 'unresolved Dockerfile')]:
            docker.write_text(original + line)
            self.reseal_source()
            with self.subTest(line=line), self.assertRaisesRegex(ValueError, message):
                self.preflight()
        docker.write_text(original)
        (self.case.source / 'iso/install-runner.py').mkdir()
        (self.case.source / 'iso/install-runner.py/child').write_text('shadow')
        self.reseal_source()
        with self.assertRaisesRegex(ValueError, 'path shadowed'):
            self.preflight()

    def test_generated_syntax_and_missing_preimage_refuse_before_output(self):
        outer = self.case.source / 'outer.py'
        original = outer.read_text()
        outer.write_text(original + '\ninvalid syntax ?\n')
        self.reseal_source()
        with self.assertRaises(SyntaxError):
            self.preflight()
        outer.write_text(original.replace("'-serial', 'null'", "'changed'"))
        self.reseal_source()
        with self.assertRaisesRegex(ValueError, 'preimage changed'):
            self.preflight()
        self.assertFalse(self.output.exists())

    def test_wrapper_missing_shadowed_readonly_private_controls(self):
        wrapper = self.wrapper()
        with self.assertRaisesRegex(ValueError, 'explicit final wrapper'):
            fixtures.s.validate_wrapper_state({}, source_roots=(self.case.q2,))
        original = wrapper['overlays'][:]
        for overlays in [[], [dict(original[0], read_only=True)],
                         original + [{'target': '/private', 'source': str(self.case.q2), 'read_only': True}],
                         original + [{'target': '/private/home/shadow', 'source': str(self.case.q2), 'read_only': True}]]:
            wrapper['overlays'] = overlays
            with self.subTest(overlays=overlays), self.assertRaisesRegex(ValueError, 'missing or readonly|subtree shadowed'):
                self.preflight(wrapper=wrapper)
        wrapper['overlays'] = original
        home = Path(wrapper['private_root']) / 'home'
        home.chmod(0o500)
        with self.assertRaisesRegex(ValueError, 'shadowed or nonprivate'):
            self.preflight(wrapper=wrapper)
        home.chmod(0o700)
        home.rmdir()
        with self.assertRaises(FileNotFoundError):
            self.preflight(wrapper=wrapper)

    def test_wrapper_alias_overlay_environment_and_isolation_errors(self):
        wrapper = self.wrapper()
        private = Path(wrapper['private_root'])
        private.chmod(0o755)
        with self.assertRaisesRegex(ValueError, 'private wrapper root'):
            self.preflight(wrapper=wrapper)
        private.chmod(0o700)
        for item in [{'target': 'relative', 'source': str(private), 'read_only': False},
                     {'target': '/private//bad', 'source': str(private), 'read_only': False},
                     {'target': '/private/../bad', 'source': str(private), 'read_only': False},
                     {'target': '/private', 'source': str(private), 'read_only': 1},
                     {'target': '/private', 'source': str(self.case.q2), 'read_only': False}]:
            wrapper['overlays'] = [item]
            with self.subTest(item=item), self.assertRaisesRegex(ValueError, 'overlay refused|source alias'):
                self.preflight(wrapper=wrapper)
        alias = self.case.root / 'private-alias'
        alias.symlink_to(private)
        wrapper['overlays'] = [{'target': '/private', 'source': str(alias), 'read_only': False}]
        with self.assertRaisesRegex(ValueError, 'source alias'):
            self.preflight(wrapper=wrapper)
        wrapper['overlays'][0]['source'] = str(private)
        wrapper['environment'].pop('HOME')
        with self.assertRaisesRegex(ValueError, 'HOME/GNUPGHOME/TMPDIR'):
            self.preflight(wrapper=wrapper)
        for value in ['relative', '/private//home', '/private/../home']:
            wrapper['environment']['HOME'] = value
            with self.subTest(value=value), self.assertRaisesRegex(ValueError, 'environment path'):
                self.preflight(wrapper=wrapper)
        wrapper['environment']['HOME'] = '/private/tmp'
        with self.assertRaisesRegex(ValueError, 'must be separate'):
            self.preflight(wrapper=wrapper)
        wrapper['private_root'] = str(self.case.q2)
        self.case.q2.chmod(0o700)
        with self.assertRaisesRegex(ValueError, 'private wrapper root'):
            self.preflight(wrapper=wrapper)

    def test_wrapper_later_parent_overlay_hides_earlier_child(self):
        wrapper = self.wrapper()
        wrapper['overlays'].insert(0, {'target': '/private/home/hidden', 'source': str(self.case.q2), 'read_only': True})
        wrapper['overlays'].append({'target': '/unrelated', 'source': str(self.case.q2), 'read_only': True})
        self.preflight(wrapper=wrapper)

    def test_preflight_cli_no_stage_and_invalid_combinations(self):
        argv = ['setup', '--preflight-public-seed', '--public-input-dir', str(self.case.q2), '--archive', str(self.case.archive)]
        with patch('sys.argv', argv), contextlib.redirect_stdout(io.StringIO()) as output:
            fixtures.s.main()
        self.assertEqual(json.loads(output.getvalue())['schema'], 'vimbadmin-materialization-preflight-v1')
        record = self.case.root / 'wrapper.json'
        record.write_text(json.dumps(self.wrapper()))
        with patch('sys.argv', argv + ['--wrapper-state', str(record)]), contextlib.redirect_stdout(io.StringIO()) as output:
            fixtures.s.main()
        self.assertIsNotNone(json.loads(output.getvalue())['wrapper_state'])
        for args in [['setup', '--preflight-public-seed'], argv + ['--output-dir', str(self.output)],
                     argv + ['--stage-verified-public-seed'], argv + ['--build-verified-public-seed'],
                     ['setup', '--wrapper-state', str(record)]]:
            with patch('sys.argv', args), self.subTest(args=args), self.assertRaisesRegex(ValueError, 'preflight'):
                fixtures.s.main()

    def test_staging_output_alias_source_and_repository_are_refused(self):
        alias = self.case.root / 'source-alias'
        alias.symlink_to(self.case.source)
        for output in [Path('relative'), self.case.source / 'new', alias / 'new', fixtures.CHECKOUT / 'new']:
            with self.subTest(output=output), self.assertRaisesRegex(ValueError, 'isolated materialization'):
                fixtures.s.stage(self.case.archive, output)
        self.assertFalse((self.case.source / 'new').exists())

    def test_reserved_receipt_cannot_shadow_generated_receipt(self):
        (self.case.source / 'source-preflight.json').write_text('{}')
        self.reseal_source()
        with self.assertRaisesRegex(ValueError, 'reserved generated receipt'):
            self.preflight()

    def test_staging_candidate_change_refuses_sealing(self):
        real_sha = fixtures.s.sha
        setup_calls = 0
        def changed(path):
            nonlocal setup_calls
            if path == Path(fixtures.s.__file__):
                setup_calls += 1
                if setup_calls > 1:
                    return '0' * 64
            return real_sha(path)
        with patch.object(fixtures.s, 'sha', side_effect=changed), self.assertRaisesRegex(ValueError, 'candidate setup changed'):
            self.stage()
        self.assertFalse((self.output / 'context-seal.json').exists())

    def test_staging_generated_drift_refuses_sealing(self):
        real = fixtures.s.copy_public
        def corrupt(source, destination, digest):
            real(source, destination, digest)
            if destination.name == fixtures.s.ARCHIVE:
                (self.output / 'outer.py').write_bytes(b'drift')
        with patch.object(fixtures.s, 'copy_public', side_effect=corrupt), self.assertRaisesRegex(ValueError, 'materialization drift'):
            self.stage()
        self.assertFalse((self.output / 'context-seal.json').exists())





class ArchiveLinks(unittest.TestCase):
    """Exercise the real parser and extraction filter with inert local payloads."""

    def setUp(self):
        import tempfile
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.archive = self.root / 'payload.tar'
        self.links = dict(fixtures.s.ARCHIVE_LINKS)

    def write(self, members):
        with tarfile.open(self.archive, 'w') as payload:
            for name, kind, value in members:
                member = tarfile.TarInfo(name)
                member.type = kind
                if kind in (tarfile.SYMTYPE, tarfile.LNKTYPE):
                    member.linkname = value
                else:
                    member.size = len(value)
                payload.addfile(member, io.BytesIO(value) if kind == tarfile.REGTYPE else None)

    def valid_members(self):
        import posixpath
        result = [('./', tarfile.DIRTYPE, b'')]
        for name, link in self.links.items():
            target = posixpath.normpath(posixpath.join(posixpath.dirname(name), link))
            result.extend([(name, tarfile.SYMTYPE, link), (target, tarfile.REGTYPE, b'inert-target')])
        return result

    def parse(self):
        with tarfile.open(self.archive) as payload:
            return fixtures.s.archive_members(payload)

    def test_all_six_links_resolve_after_real_filtered_extraction(self):
        import guest_capacity
        self.assertEqual(len(self.links), 6)
        members = self.valid_members()
        self.write(members)
        inventory = self.parse()
        self.assertEqual(len(inventory), 12)
        target = self.root / 'extracted'
        target.mkdir()
        # The generated installer uses this same extraction API and data filter;
        # guest_capacity revalidates the tree before and after extraction.
        with tarfile.open(self.archive) as payload:
            payload.extractall(target, filter='data')
        with patch.object(guest_capacity, 'ENTRIES', 12), patch.object(guest_capacity, 'PAYLOAD_BYTES', 6 * len(b'inert-target')):
            guest_capacity.verify_extracted(self.archive, target)
        for name, link in self.links.items():
            path = target / name
            self.assertTrue(path.is_symlink())
            self.assertEqual(os.readlink(path), link)
            self.assertTrue(path.resolve(strict=True).is_relative_to(target))
            self.assertEqual(path.read_bytes(), b'inert-target')
        self.assertIn("payload.extractall(target,filter='data')", fixtures.s.INSTALL)
        self.assertFalse((self.root / 'escape').exists())

    def test_absolute_escaping_unknown_and_cyclic_links_are_refused(self):
        name = next(iter(self.links))
        for link in ('/tmp/escape', '../../../../escape', name, '', '../lib/node_modules/npm/bin/other.js'):
            with self.subTest(link=link):
                self.write([(name, tarfile.SYMTYPE, link)])
                with self.assertRaisesRegex(ValueError, 'alias or special'):
                    self.parse()
        self.write([('unexpected', tarfile.SYMTYPE, next(iter(self.links.values())))])
        with self.assertRaisesRegex(ValueError, 'alias or special'):
            self.parse()

    def test_dangling_directory_and_link_targets_are_refused(self):
        import posixpath
        name, link = next(iter(self.links.items()))
        target = posixpath.normpath(posixpath.join(posixpath.dirname(name), link))
        variants = [[], [(target, tarfile.DIRTYPE, b'')], [(target, tarfile.SYMTYPE, name)]]
        for extra in variants:
            with self.subTest(extra=extra):
                self.write([(name, tarfile.SYMTYPE, link)] + extra)
                with self.assertRaisesRegex(ValueError, 'link target|alias or special'):
                    self.parse()

    def test_duplicate_hardlink_and_special_members_are_refused(self):
        name, link = next(iter(self.links.items()))
        for members in ([('regular', tarfile.REGTYPE, b'x')] * 2,
                        [(name, tarfile.LNKTYPE, link)], [('device', tarfile.CHRTYPE, b'')],
                        [(name, tarfile.SYMTYPE, link)] * 2):
            with self.subTest(members=members):
                self.write(members)
                with self.assertRaisesRegex(ValueError, 'alias or special'):
                    self.parse()

    def test_nonempty_link_header_is_refused(self):
        self.write(self.valid_members())
        with tarfile.open(self.archive) as payload:
            members = payload.getmembers()
        next(member for member in members if member.issym()).size = 1
        with self.assertRaisesRegex(ValueError, 'alias or special'):
            fixtures.s.archive_members(members)

    def test_parent_files_and_link_descendants_are_refused_in_any_order(self):
        for members in ([('parent/child', tarfile.REGTYPE, b'x'), ('parent', tarfile.REGTYPE, b'x')],
                        [('parent', tarfile.REGTYPE, b'x'), ('parent/child', tarfile.REGTYPE, b'x')],
                        self.valid_members() + [(next(iter(self.links)) + '/child', tarfile.REGTYPE, b'x')]):
            with self.subTest(members=members):
                self.write(members)
                with self.assertRaisesRegex(ValueError, 'parent shadowed'):
                    self.parse()
        self.write([('parent', tarfile.DIRTYPE, b''), ('parent/child', tarfile.REGTYPE, b'x')])
        self.assertEqual(len(self.parse()), 2)

    def test_root_duplicate_non_directory_and_size_are_refused(self):
        for members in ([('.', tarfile.DIRTYPE, b''), ('./', tarfile.DIRTYPE, b'')],
                        [('.', tarfile.REGTYPE, b'')], [('.', tarfile.DIRTYPE, b'x')]):
            with self.subTest(members=members):
                self.write(members)
                with self.assertRaisesRegex(ValueError, 'root entry'):
                    self.parse()

    def test_archive_path_aliases_are_refused(self):
        for name in ('../escape', '/absolute', 'a/../b', 'a//b', '././run.sh', 'a\\b'):
            with self.subTest(name=name):
                self.write([(name, tarfile.REGTYPE, b'x')])
                with self.assertRaisesRegex(ValueError, 'relative path'):
                    self.parse()

    def test_unlisted_internal_link_with_real_target_is_refused(self):
        self.write([('unexpected-link', tarfile.SYMTYPE, 'existing'), ('existing', tarfile.REGTYPE, b'x')])
        with self.assertRaisesRegex(ValueError, 'alias or special'):
            self.parse()

    def test_preflight_accepts_reviewed_links_without_staging(self):
        case = fixtures.PublicSeed()
        self.addCleanup(case.doCleanups)
        case.setUp()
        self.write(self.valid_members() + [(name, tarfile.REGTYPE, body) for name, body in case.files.items()])
        with patch.object(fixtures.s, 'ARCHIVE_SHA', fixtures.s.sha(self.archive)):
            _, _, _, receipt = fixtures.s.materialization_preflight(case.q2, self.archive)
        self.assertEqual(receipt['archive_sha256'], fixtures.s.sha(self.archive))
        self.assertIs(receipt['execution_allowed'], False)
        self.assertIs(receipt['materialization_ready'], False)
        self.assertFalse((self.root / 'prepared').exists())

    def test_direct_entrypoint_collects_archive_links(self):
        import runpy
        import sys

        observed = []

        def collect():
            case = getattr(sys.modules['__main__'], 'ArchiveLinks', None)
            if case is not None:
                observed.extend(unittest.defaultTestLoader.getTestCaseNames(case))

        with patch.object(unittest, 'main', side_effect=collect):
            runpy.run_path(__file__, run_name='__main__')
        self.assertEqual(observed, unittest.defaultTestLoader.getTestCaseNames(ArchiveLinks))


if __name__ == '__main__':
    unittest.main()
