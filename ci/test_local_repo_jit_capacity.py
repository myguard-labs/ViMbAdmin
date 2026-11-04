"""Tiny owned public-data fixtures only. No Docker/QEMU/native/network transport."""
import hashlib
import io
import json
import os
import shutil
import subprocess
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1] / '.github/scripts/local-repo-jit'
sys.path.insert(0, str(ROOT))
import guest_capacity as guest
import owned_overlay as overlay


class Capacity(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.archive = self.root / 'public.tar'
        self.target = self.root / 'runner'
        with tarfile.open(self.archive, 'w') as stream:
            item = tarfile.TarInfo('member'); item.size = 3
            stream.addfile(item, io.BytesIO(b'abc'))
        for name, value in {'ARCHIVE_SHA': hashlib.sha256(self.archive.read_bytes()).hexdigest(),
                            'PAYLOAD_BYTES': 3, 'ENTRIES': 1, 'RESERVE_BYTES': 2, 'RESERVE_INODES': 2}.items():
            context = patch.object(guest, name, value); context.start(); self.addCleanup(context.stop)
        self.fs = SimpleNamespace(f_bavail=5, f_frsize=1, f_favail=4, f_blocks=guest.MIN_ROOT_BYTES)

    def admit(self):
        return guest.admit(self.archive, self.target, lambda _: self.fs)

    def test_exact_boundary_and_root_growth(self):
        self.assertEqual(self.admit()['available_bytes'], 5)
        guest.root_ready(self.root, lambda _: self.fs)
        self.fs.f_blocks -= 1
        with self.assertRaisesRegex(ValueError, 'growth unproved'):
            guest.root_ready(self.root, lambda _: self.fs)

    def test_insufficient_bytes(self):
        self.fs.f_bavail = 4
        with self.assertRaisesRegex(ValueError, 'available bytes'):
            self.admit()
        self.assertFalse(self.target.exists())

    def test_insufficient_inodes(self):
        self.fs.f_favail = 3
        with self.assertRaisesRegex(ValueError, 'available inodes'):
            self.admit()

    def test_truncated_archive(self):
        self.archive.write_bytes(self.archive.read_bytes()[:512])
        with self.assertRaisesRegex(ValueError, 'hash/truncation'):
            self.admit()

    def test_wellformed_same_inventory_corruption(self):
        before_size = self.archive.stat().st_size
        with tarfile.open(self.archive, 'w') as stream:
            item = tarfile.TarInfo('member'); item.size = 3
            stream.addfile(item, io.BytesIO(b'xyz'))
        self.assertEqual(self.archive.stat().st_size, before_size)
        with tarfile.open(self.archive) as stream:
            members = stream.getmembers()
            self.assertEqual([(item.name, item.size) for item in members], [('member', 3)])
            self.assertEqual(stream.extractfile(members[0]).read(), b'xyz')
        with self.assertRaisesRegex(ValueError, 'hash/truncation'):
            self.admit()

    def test_guest_root_accepts_pinned_actual_host_owned_iso_archive(self):
        actual = SimpleNamespace(st_uid=1000, st_gid=1000, st_mode=0o664)  # Explicit public ISO ownership boundary fixture.
        original = Path.lstat
        def guest_metadata(path):
            info = original(path)
            values = list(info)
            if path == self.root:
                values[4] = 0
            elif path == self.archive:
                values[4], values[5] = actual.st_uid, actual.st_gid
            return os.stat_result(values)
        with patch.object(guest.os, 'geteuid', return_value=0), patch.object(Path, 'lstat', guest_metadata):
            try:
                proof = self.admit()
            except ValueError as error:
                self.fail('content-pinned public archive must admit independently of host UID: ' + str(error))
            self.assertEqual(proof['payload_bytes'], 3)

    def test_inventory_mismatch(self):
        with patch.object(guest, 'ENTRIES', 2), self.assertRaisesRegex(ValueError, 'header inventory'):
            self.admit()

    def test_preexisting_and_symlink(self):
        self.target.symlink_to(self.root / 'absent')
        with self.assertRaisesRegex(ValueError, 'preexists'):
            self.admit()
        self.target.unlink(); self.target.mkdir()
        with self.assertRaisesRegex(ValueError, 'preexists'):
            self.admit()

    def test_foreign_and_archive_symlink(self):
        with patch.object(guest.os, 'geteuid', return_value=os.geteuid() + 1), self.assertRaisesRegex(ValueError, 'foreign'):
            self.admit()
        other = self.root / 'other'; self.archive.rename(other); self.archive.symlink_to(other)
        with self.assertRaisesRegex(ValueError, 'archive'):
            self.admit()

    def test_partial_extraction(self):
        self.target.mkdir(); (self.target / 'member').write_bytes(b'a')
        with self.assertRaisesRegex(ValueError, 'incomplete'):
            guest.verify_extracted(self.archive, self.target)
        (self.target / 'member').write_bytes(b'abc')
        guest.verify_extracted(self.archive, self.target)
        with tarfile.open(self.archive, 'a') as payload:
            directory = tarfile.TarInfo('directory'); directory.type = tarfile.DIRTYPE
            payload.addfile(directory)
        (self.target / 'directory').mkdir()
        with patch.object(guest, 'ENTRIES', 2):
            guest.verify_extracted(self.archive, self.target)

    def fixture(self, entries):
        with tarfile.open(self.archive, 'w') as payload:
            for name, kind, link in entries:
                item = tarfile.TarInfo(name); item.type = kind; item.mode = 0o755
                item.linkname = link
                if item.isfile():
                    item.size = 3
                payload.addfile(item, io.BytesIO(b'abc') if item.isfile() else None)
        return patch.object(guest, 'ARCHIVE_SHA', hashlib.sha256(self.archive.read_bytes()).hexdigest())

    def test_root_entry_inventory_and_safe_extraction(self):
        for root in ('.', './'):
            with self.subTest(root=root), self.fixture([(root, tarfile.DIRTYPE, ''), ('./member', tarfile.REGTYPE, '')]):
                try:
                    proof = self.admit()
                except ValueError as error:
                    self.fail('root directory must be excluded from canonical archive inventory: ' + str(error))
                self.assertEqual(proof['entries'], 1, 'root directory must be excluded from canonical archive inventory')
                self.target.mkdir()
                with tarfile.open(self.archive) as payload:
                    payload.extractall(self.target, filter='data')
                guest.verify_extracted(self.archive, self.target)
                shutil.rmtree(self.target)

    def test_duplicate_root_paths_special_and_unsafe_links_refused(self):
        for entries, message in (
                ([('.', tarfile.DIRTYPE, ''), ('./', tarfile.DIRTYPE, ''), ('member', tarfile.REGTYPE, '')], 'root entry'),
                ([('.', tarfile.REGTYPE, '')], 'root entry'),
                ([('member', tarfile.REGTYPE, ''), ('./member', tarfile.REGTYPE, '')], 'duplicate'),
                ([('../member', tarfile.REGTYPE, '')], 'path'),
                ([('/member', tarfile.REGTYPE, '')], 'path'),
                ([('dir//member', tarfile.REGTYPE, '')], 'path'),
                ([('member', tarfile.FIFOTYPE, '')], 'special'),
                ([('member', tarfile.LNKTYPE, 'other')], 'special'),
                ([('member', tarfile.SYMTYPE, '../outside')], 'link')):
            with self.subTest(entries=entries), self.fixture(entries), self.assertRaisesRegex(ValueError, message):
                self.admit()

    def test_directory_and_symlink_extraction_identity(self):
        entries = [('.', tarfile.DIRTYPE, ''), ('directory', tarfile.DIRTYPE, ''),
                   ('directory/member', tarfile.REGTYPE, ''), ('link', tarfile.SYMTYPE, 'directory/member')]
        with self.fixture(entries), patch.object(guest, 'ENTRIES', 3):
            self.fs.f_favail = 6
            self.admit()
            self.target.mkdir()
            with tarfile.open(self.archive) as payload:
                payload.extractall(self.target, filter='data')
            guest.verify_extracted(self.archive, self.target)
            (self.target / 'link').unlink(); (self.target / 'link').symlink_to('directory')
            with self.assertRaisesRegex(ValueError, 'symlink extraction'):
                guest.verify_extracted(self.archive, self.target)

    def test_filesystem_error_aborts(self):
        with self.assertRaises(OSError):
            guest.admit(self.archive, self.target, lambda _: (_ for _ in ()).throw(OSError('fixture')))
        self.assertFalse(self.target.exists())


class Overlay(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.base = self.root / 'immutable.qcow2'; self.base.write_bytes(b'immutable tiny fixture')
        self.expected = overlay.sha(self.base)
        context = patch.object(overlay.budget, 'BASE_SHA', self.expected)
        context.start(); self.addCleanup(context.stop)
        self.work = self.root / 'work'; self.work.mkdir(mode=0o700)
        self.calls = []

    def command(self, args, **kwargs):
        self.calls.append((args, kwargs))
        if args[1] == 'create':
            self.assertEqual(args[-1], str(5368709120))
            fd = kwargs['pass_fds'][0]
            self.assertEqual(args[-2], '/proc/self/fd/' + str(fd))
            os.write(fd, b'owned qcow fixture')
            return SimpleNamespace(returncode=0)
        is_base = args[-1] == str(self.base)
        value = {'format': 'qcow2', 'virtual-size': 3000000000 if is_base else 5368709120}
        if not is_base:
            value['backing-filename'] = str(self.base)
        return SimpleNamespace(stdout=json.dumps(value))

    def create(self, command=None):
        return overlay.fresh_overlay(self.base, self.expected, self.work, command or self.command)

    def test_grown_fresh_overlay_original_unchanged(self):
        os.link(self.base, self.root / 'public-hardlink')
        before = (self.base.stat(), self.base.read_bytes())
        result = self.create()
        self.assertEqual(result['overlay_virtual_bytes'], 5368709120)
        self.assertEqual(result['scope'], 'baseline-install-only')
        self.assertEqual(result['project'], 'vimbadmin')
        self.assertEqual((self.base.stat(), self.base.read_bytes()), before)
        self.assertNotEqual(self.base.stat().st_ino, (self.work / 'owned.qcow2').stat().st_ino)
        self.assertTrue((self.work / 'overlay-origin.json').is_file())

    def test_hardlinked_destination_refused_before_format(self):
        os.link(self.base, self.work / 'owned.qcow2')
        with self.assertRaises(FileExistsError):
            self.create()
        self.assertFalse(any(args[1] == 'create' for args, _ in self.calls))
        self.assertEqual(overlay.sha(self.base), self.expected)

    def test_symlink_and_foreign_refusal(self):
        self.work.chmod(0o755)
        with self.assertRaisesRegex(ValueError, 'private owned'):
            self.create()
        self.work.chmod(0o700)
        with patch.object(overlay.os, 'geteuid', return_value=os.geteuid() + 1), self.assertRaisesRegex(ValueError, 'private owned'):
            self.create()
        moved = self.root / 'real'; self.base.rename(moved); self.base.symlink_to(moved)
        with self.assertRaisesRegex(ValueError, 'base identity'):
            self.create()

    def test_bad_base_hash_and_backing_chain(self):
        self.expected = '0' * 64
        with self.assertRaisesRegex(ValueError, 'base identity'):
            self.create()
        self.expected = overlay.sha(self.base)
        def chained(args, **kwargs):
            return SimpleNamespace(stdout=json.dumps({'format': 'qcow2', 'virtual-size': 3, 'backing-filename': 'foreign'}))
        with self.assertRaisesRegex(ValueError, 'backing chain'):
            self.create(chained)

    def test_receipt_preexistence(self):
        (self.work / 'overlay-origin.json').write_text('old')
        with self.assertRaisesRegex(ValueError, 'receipt preexists'):
            self.create()

    def test_format_error_keeps_receipt_and_source(self):
        def failed(args, **kwargs):
            if args[1] == 'create':
                self.assertTrue((self.work / 'overlay-origin.json').exists())
                raise subprocess.CalledProcessError(1, args)
            return self.command(args, **kwargs)
        with self.assertRaises(subprocess.CalledProcessError):
            self.create(failed)
        self.assertTrue((self.work / 'overlay-origin.json').exists())
        self.assertEqual(overlay.sha(self.base), self.expected)

    def test_receipt_sync_error_before_format(self):
        with patch.object(overlay, 'sync_directory', side_effect=OSError('fixture')), self.assertRaises(OSError):
            self.create()
        self.assertFalse(any(args[1] == 'create' for args, _ in self.calls))

    def test_same_path_replacement_cannot_redirect_format(self):
        foreign = self.root / 'foreign'; foreign.write_bytes(b'foreign')
        def replacement(args, **kwargs):
            if args[1] == 'create':
                path = self.work / 'owned.qcow2'; path.rename(self.work / 'retained')
                os.link(foreign, path)
            return self.command(args, **kwargs)
        with self.assertRaisesRegex(ValueError, 'replacement/hardlink'):
            self.create(replacement)
        self.assertEqual(foreign.read_bytes(), b'foreign')
        self.assertEqual((self.work / 'retained').read_bytes(), b'owned qcow fixture')

    def test_post_format_hardlink_refusal(self):
        def hardlink(args, **kwargs):
            result = self.command(args, **kwargs)
            if args[1] == 'create':
                os.link(self.work / 'owned.qcow2', self.work / 'extra-link')
            return result
        with self.assertRaisesRegex(ValueError, 'replacement/hardlink'):
            self.create(hardlink)

    def test_geometry_and_original_mutation_refusal(self):
        def bad(args, **kwargs):
            result = self.command(args, **kwargs)
            if args[1] == 'info' and args[-1] != str(self.base):
                result.stdout = '{}'
            return result
        with self.assertRaises(KeyError):
            self.create(bad)
        # A distinct fresh owned work purpose is used for the independent corruption fixture.
        self.work = self.root / 'second'; self.work.mkdir(mode=0o700)
        def changed(args, **kwargs):
            result = self.command(args, **kwargs)
            if args[1] == 'create':
                self.base.write_bytes(b'changed fixture')
            return result
        with self.assertRaisesRegex(ValueError, 'base changed'):
            self.create(changed)

    def test_flattened_export_required(self):
        good = lambda *a, **k: SimpleNamespace(stdout=json.dumps({'format': 'qcow2', 'virtual-size': 5368709120}))
        overlay.standalone(self.work / 'sealed.qcow2', good)
        with self.assertRaisesRegex(ValueError, 'standalone'):
            overlay.standalone(self.work / 'sealed.qcow2', self.command)



if __name__ == '__main__':
    unittest.main()
