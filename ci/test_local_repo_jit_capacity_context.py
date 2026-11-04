"""Maintained capacity context consumer, injected ISO only; no runtime transport."""
import json
import unittest
from unittest.mock import patch

import test_local_repo_jit_docker as fixtures


class Context(unittest.TestCase):
    def setUp(self):
        self.case = fixtures.PublicSeed()
        self.case.setUp()
        self.addCleanup(self.case.doCleanups)
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


if __name__ == '__main__':
    unittest.main()
