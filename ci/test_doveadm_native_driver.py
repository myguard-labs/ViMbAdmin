"""Run the exact canonical Bash driver with a strict, daemon-free CLI fake."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DRIVER = ROOT / '.github/scripts/run-doveadm-native.sh'
FAKE = ROOT / 'ci/support/doveadm-native-docker.py'
CID, IID = 'b' * 64, 'sha256:' + 'c' * 64


class NativeDriver(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workspace = tempfile.TemporaryDirectory(prefix='native-driver-')
        cls.addClassCleanup(cls.workspace.cleanup)
        cls.repo = Path(cls.workspace.name)
        # A real, uncommitted Git tree proves archive/mode/source selection.
        cls.git('init', '--quiet')
        required = ('.github/scripts/doveadm-native.Dockerfile', '.github/scripts/setup-php-container.sh',
                    'composer.json', 'composer.lock', 'library/ViMbAdmin/Doveadm.php',
                    'ci/test-refactor-doveadm-contract.php', 'ci/support/doveadm-curl-init-failure.c')
        for name in required:
            dest = cls.repo / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / name, dest)
        marker = cls.repo / 'source-executable'
        marker.write_text('archive mode marker\n')
        marker.chmod(0o755)
        cls.git('add', '--', *required, marker.name)
        cls.tree = cls.git('write-tree').strip()
        cls.bin = cls.repo / 'fake-bin'
        cls.bin.mkdir()
        shutil.copyfile(FAKE, cls.bin / 'docker')
        (cls.bin / 'docker').chmod(0o700)
        wrapper = cls.bin / 'python3'
        wrapper.write_text((ROOT / 'ci/support/doveadm-native-python.py').read_text().replace(
            '#!/usr/bin/env python3', '#!' + sys.executable, 1))
        wrapper.chmod(0o700)

    @classmethod
    def git(cls, *args):
        # Isolate every fixture operation, including init/add/write-tree.
        env = {key: value for key, value in os.environ.items()
               if not key.startswith('GIT_')}
        env.update(GIT_OPTIONAL_LOCKS='0', GIT_CONFIG_NOSYSTEM='1',
                   GIT_CONFIG_GLOBAL=os.devnull)
        return subprocess.check_output(
            ['git', '-c', 'safe.directory=' + str(cls.repo), *args],
            cwd=cls.repo, env=env, text=True)

    def run_driver(self, scenario='normal', minor='8.3', extra=None):
        temp = tempfile.TemporaryDirectory(prefix='case-', dir=self.repo)
        self.addCleanup(temp.cleanup)
        directory = Path(temp.name)
        state_path = directory / 'docker.json'
        state_path.write_text(json.dumps({'calls': [], 'scenario': scenario}))
        env = {**os.environ, 'PATH': str(self.bin) + os.pathsep + os.environ['PATH'],
               'TMPDIR': str(directory), 'NATIVE_DOCKER_STATE': str(state_path),
               'RUNNER_ENVIRONMENT': 'github-hosted', 'GITHUB_RUN_ID': '307', 'GITHUB_RUN_ATTEMPT': '1',
               'DOVEADM_PHP_MINOR': minor, 'DOVEADM_SOURCE_TREE': self.tree, 'DOVEADM_CONTEXT_ONLY': '0',
               'DOVEADM_BASE_IMAGE': 'mirror.gcr.io/library/php@sha256:' + 'a' * 64,
               'DOVEADM_BUILD_ESSENTIAL_VERSION': '12.12', 'DOVEADM_LIBCURL_DEV_VERSION': '8.14.1-2+deb13u7'}
        env.update(extra or {})
        result = subprocess.run(['bash', str(DRIVER)], cwd=self.repo, env=env, text=True, capture_output=True, check=False, umask=0o022)
        state = json.loads(state_path.read_text())
        self.assertNotIn('Traceback', result.stderr, result.stderr)
        return result, state, directory

    def test_full_driver_all_minors_preserves_native_contract_and_receipts(self):
        for minor in ('8.3', '8.4', '8.5', '8.6'):
            with self.subTest(minor=minor):
                result, state, _ = self.run_driver(minor=minor)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(state['started'], CID)
                self.assertEqual(state['removed'], [CID, IID])
                build = next(call for call in state['calls'] if call[0] == 'build')
                create = next(call for call in state['calls'] if call[0] == 'create')
                self.assertNotIn('--tag', build, 'build must not overwrite a shared tag')
                self.assertEqual(create[-4], IID, 'create must use the recorded immutable image ID')
                for call in state['calls']:
                    if call[0] == 'inspect' or call[:2] in (['image', 'inspect'], ['image', 'rm']):
                        self.assertIn(call[-1], (CID, IID), 'inspect and cleanup require immutable IDs')
                self.assertEqual(Path(state['container_receipt']).read_text(), CID)
                self.assertEqual(Path(state['image_receipt']).read_text(), IID)
                receipt_dir = Path(state['image_receipt']).parent
                self.assertEqual(json.loads((receipt_dir / 'source.json').read_text())['source_tree'], self.tree)
                self.assertFalse(list(receipt_dir.glob('context.*')), 'archive context must be cleaned')
                self.assertIn('Native cleanup: check=0 cleanup=0', result.stdout)

    def test_name_and_tag_replacements_survive_immutable_cleanup(self):
        result, state, _ = self.run_driver('replace')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(state['removed'], [CID, IID])
        self.assertEqual(state['foreign_container'], 'd' * 64, 'same-name replacement must survive')
        self.assertEqual(state['foreign_image'], 'sha256:' + 'e' * 64, 'same-tag replacement must survive')
        self.assertEqual(state['started'], CID)

    def test_durable_receipt_ordering_before_build_identity_use_and_cleanup(self):
        result, state, _ = self.run_driver()
        self.assertEqual(result.returncode, 0, result.stderr)
        events = state['events']
        for receipt, operation in (('source.json', 'build'), ('image.id', 'image-inspect'), ('container.id', 'inspect')):
            use = next(i for i, event in enumerate(events) if event[:2] == ['docker', operation])
            self.assertIn(['fsync', receipt, 'file'], events[:use], 'receipt file must be fsynced before first resource use')
            self.assertIn(['fsync', receipt, 'directory'], events[:use], 'receipt parent must be fsynced before first resource use')

    def test_receipt_privacy_preserves_original_archive_executable_mode(self):
        result, state, _ = self.run_driver(extra={'DOVEADM_CONTEXT_ONLY': '1'})
        self.assertEqual(result.returncode, 0, result.stderr)
        line = next(line for line in result.stdout.splitlines() if line.startswith('Prepared native context: '))
        context = Path(line.split(': ', 1)[1])
        self.assertEqual((context / 'source-executable').stat().st_mode & 0o777, 0o755,
                         'receipt hardening must preserve the original archive executable mode')
        self.assertEqual((context.parent / 'source.json').stat().st_mode & 0o777, 0o600)
        self.assertEqual(state['calls'], [])

    def test_persistence_error_and_interruption_fail_closed_including_recovery(self):
        for scenario, receipt, forbidden in (('normal', 'source.json', 'build'), ('normal', 'image.id', 'image-inspect'),
                                             ('normal', 'container.id', 'inspect'), ('build-no-receipt', 'image.id', 'image-inspect'),
                                             ('create-no-receipt', 'container.id', 'inspect')):
            for kind in ('file', 'directory'):
                for fault in ('NATIVE_SYNC_FAIL', 'NATIVE_SYNC_TERM'):
                    with self.subTest(scenario=scenario, receipt=receipt, kind=kind, fault=fault):
                        result, state, _ = self.run_driver(scenario, extra={fault: receipt + ':' + kind})
                        self.assertNotEqual(result.returncode, 0)
                        if fault == 'NATIVE_SYNC_TERM':
                            expected = 43 if scenario == 'build-no-receipt' else 47 if scenario == 'create-no-receipt' else 143
                            self.assertEqual(result.returncode, expected, 'cleanup must retain the original operation failure')
                        self.assertFalse(any(event[:2] == ['docker', forbidden] for event in state['events']),
                                         'failed persistence must prevent resource use and cleanup')

    def test_failed_build_creation_and_signals_recover_owned_ids(self):
        for scenario, status, removed in (
                ('build-failure', 43, [IID]), ('build-no-receipt', 43, [IID]),
                ('build-term-no-receipt', 143, [IID]),
                ('create-failure', 47, [CID, IID]), ('create-no-receipt', 47, [CID, IID]),
                ('create-term-no-receipt', 143, [CID, IID]), ('create-int-no-receipt', 130, [CID, IID]),
                ('build-fails-before-resource', 43, []), ('create-missing', 47, [IID]),
                ('native-failure', 37, [CID, IID])):
            with self.subTest(scenario=scenario):
                result, state, _ = self.run_driver(scenario)
                self.assertEqual(result.returncode, status, result.stderr)
                self.assertEqual(state.get('removed', []), removed)

    def test_foreign_absent_and_malformed_ids_are_never_used_or_removed(self):
        for kind in ('container', 'image'):
            for prefix in ('foreign-', 'absent-', 'malformed-'):
                with self.subTest(kind=kind, prefix=prefix):
                    result, state, _ = self.run_driver(prefix + kind)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertNotIn('started', state, 'identity mismatch must fail before start')
                    self.assertNotIn(CID if kind == 'container' else IID, state.get('removed', []))

    def test_cleanup_checks_receipts_and_ownership_again(self):
        for scenario, preserved in (('cleanup-foreign-container', [CID]), ('cleanup-foreign-image', [IID]),
                                    ('receipt-tamper', [CID, IID])):
            with self.subTest(scenario=scenario):
                result, state, _ = self.run_driver(scenario)
                self.assertEqual(result.returncode, 1, result.stderr)
                self.assertEqual(state['started'], CID)
                for identity in preserved:
                    self.assertNotIn(identity, state.get('removed', []))
                self.assertIn('ownership mismatch; preserved', result.stderr)

    def test_cleanup_failure_and_ambiguous_recovery_are_not_success(self):
        for scenario in ('cleanup-failure-container', 'cleanup-failure-image', 'recovery-query-failure', 'ambiguous-recovery'):
            with self.subTest(scenario=scenario):
                result, state, _ = self.run_driver(scenario)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn('cleanup=1', result.stdout)
                if scenario in ('recovery-query-failure', 'ambiguous-recovery'):
                    self.assertEqual(state.get('removed', []), [])

    def test_preexisting_resources_refuse_without_mutation(self):
        for kind in ('container', 'image'):
            with self.subTest(kind=kind):
                result, state, _ = self.run_driver('preexisting-' + kind)
                self.assertNotEqual(result.returncode, 0)
                self.assertTrue(state['calls'])
                self.assertTrue(all(call[:2] in (['container', 'ls'], ['image', 'ls']) for call in state['calls']))

    def test_context_only_and_invalid_inputs_do_not_call_docker(self):
        result, state, _ = self.run_driver(extra={'DOVEADM_CONTEXT_ONLY': '1'})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('Prepared native context:', result.stdout)
        self.assertEqual(state['calls'], [])
        for key, value in (('DOVEADM_PHP_MINOR', '8.2'), ('DOVEADM_BASE_IMAGE', 'untrusted:latest'),
                           ('DOVEADM_SOURCE_TREE', '--help'), ('DOVEADM_CONTEXT_ONLY', '2')):
            with self.subTest(key=key):
                result, state, _ = self.run_driver(extra={key: value})
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(state['calls'], [])

    def test_actual_workflow_enrolls_full_driver_contract(self):
        workflow = (ROOT / '.github/workflows/regression.yml').read_text()
        native = workflow.split('\n  doveadm-native:\n', 1)[1]
        self.assertIn('python3 -m unittest discover -s ci -p test_doveadm_native_driver.py -v', native)
        self.assertIn('python3 ci/doveadm_native_negative_controls.py', native)


class SetupCleanupControls(unittest.TestCase):
    def test_class_setup_failure_removes_allocated_workspace(self):
        from unittest.mock import patch

        original = tempfile.TemporaryDirectory
        created = []

        def allocate(*args, **kwargs):
            workspace = original(*args, **kwargs)
            created.append(workspace)
            return workspace

        class Probe(NativeDriver):
            def runTest(self):
                raise AssertionError('failed class setup must not run test')

        try:
            with patch.object(tempfile, 'TemporaryDirectory', side_effect=allocate), \
                 patch.object(Probe, 'git', side_effect=RuntimeError('fixture git failure')):
                result = unittest.TestResult()
                unittest.TestSuite([Probe()]).run(result)
            self.assertEqual(len(result.errors), 1)
            self.assertEqual(len(created), 1)
            self.assertFalse(Path(created[0].name).exists(), 'class setup leaked its workspace')
        finally:
            for workspace in created:
                workspace.cleanup()


if __name__ == '__main__':
    unittest.main()
