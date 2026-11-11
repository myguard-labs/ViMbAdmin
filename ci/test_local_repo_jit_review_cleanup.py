"""Regression controls for nested fixture and README shell lifetimes."""
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import test_local_repo_jit_capacity_context as capacity
import test_local_repo_jit_consumer_binding as consumer
import test_local_repo_jit_docker as docker
import test_local_repo_jit_native_phase as native
import test_local_repo_jit_observation_readiness as observation


class CleanupFailure(unittest.TestCase):
    def test_nested_setup_failure_restores_guards_and_temp(self):
        cases = [
            (capacity.Context, 'test_host_budget_boundary', docker.PublicSeed),
            (consumer.ConsumerBinding, 'test_stage_threads_validated_request_into_sealed_installer', docker.PublicSeed),
            *((native.Phase, name, native.Phase) for name in (
                'test_serial_export_errors_short_copy_digest_and_mode_fail_closed',
                'test_serial_host_fsync_error_or_interruption_blocks_acceptance',
                'test_cleanup_already_stopped_crash_exit_codes',
                'test_cleanup_unknown_stopped_state_refuses_removal')),
            (observation.Readiness, 'test_missing_root_or_provision_marker_keeps_failed_owned_resource', native.Phase),
        ]
        for owner, name, fixture in cases:
            with self.subTest(name=name):
                original_setup = fixture.setUp
                original_guard = docker.s.Q2 if fixture is docker.PublicSeed else native.p.OUTPUT
                saved_run = subprocess.run
                saved_seed = {key: getattr(docker.s, key) for key in ('Q2', 'Q2_MANIFEST', 'ARCHIVE_SHA', 'FILES')}
                saved_output = native.p.OUTPUT
                created = []
                def inject_failure(setup, test_owner, instances):
                    calls = 0

                    def fail_after_setup(instance):
                        nonlocal calls
                        calls += 1
                        setup(instance)
                        if test_owner is native.Phase and calls == 1:
                            return
                        instances.append(instance)
                        raise RuntimeError('injected nested setup failure')

                    return fail_after_setup

                try:
                    with patch.object(fixture, 'setUp', inject_failure(original_setup, owner, created)):
                        result = unittest.TestResult()
                        owner(name).run(result)
                    self.assertGreater(len(result.errors), 0)
                    self.assertIn('injected nested setup failure', result.errors[0][1])
                    self.assertGreater(len(created), 0)
                    with self.subTest(check='temporary directories'):
                        self.assertTrue(all(not Path(item.tmp.name).exists() for item in created), 'nested temporary directory leaked')
                    with self.subTest(check='patches'):
                        guard = docker.s.Q2 if fixture is docker.PublicSeed else native.p.OUTPUT
                        self.assertIs(guard, original_guard, 'nested patch leaked')
                        self.assertIs(subprocess.run, saved_run, 'nested subprocess guard leaked')
                finally:
                    for nested in reversed(created):
                        nested.doCleanups()
                    subprocess.run = saved_run
                    native.p.OUTPUT = saved_output
                    for key, value in saved_seed.items():
                        setattr(docker.s, key, value)


class ReadmeSnippet(unittest.TestCase):
    def run_snippet(self, mode):
        readme = (Path(__file__).resolve().parents[1] / 'README.md').read_text()
        snippet = readme.split('selected by setup in the calling shell, use its GitHub environment-file output:', 1)[1].split('```bash\n', 1)[1].split('\n```', 1)[0]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            scripts = root / '.github/scripts'
            scripts.mkdir(parents=True)
            launcher = root / 'selected launcher'
            launcher.write_text('#!/bin/bash\nprintf "LAUNCHER_RAN\\n"\n')
            launcher.chmod(0o755)
            setup = scripts / 'setup-php-container.sh'
            setup.write_text('''#!/bin/bash
case "$MODE" in
  fail) exit 42 ;;
  empty) : ;;
  duplicate) printf 'PSALM_BIN=%s\\nPSALM_BIN=%s\\n' "$LAUNCHER" "$LAUNCHER" >> "$GITHUB_ENV" ;;
  *) printf 'PSALM_BIN=%s\\n' "$LAUNCHER" >> "$GITHUB_ENV" ;;
esac
''')
            (scripts / 'assert-psalm-optional-models.sh').write_text('exit 0\n')
            tmp = root / 'tmp'
            tmp.mkdir()
            env = dict(os.environ, TMPDIR=str(tmp), MODE=mode, LAUNCHER=str(launcher))
            script = snippet + '\nstatus=$?\nprintf "CALLER_STATUS=%s\\n" "$status"\nprintf "EXPORTED=%s\\n" "${PSALM_BIN-unset}"\nenv | grep -F "PSALM_BIN=$LAUNCHER" >/dev/null && printf "EXPORTED_ENV=yes\\n"\nexit 0\n'
            result = subprocess.run(['bash', '-c', script], cwd=root, env=env, text=True, capture_output=True, check=False)
            return result.stdout, list(tmp.iterdir()), str(launcher)

    def test_success_exports_exact_launcher_and_cleans(self):
        output, remaining, launcher = self.run_snippet('success')
        self.assertIn('CALLER_STATUS=0\n', output)
        self.assertIn('LAUNCHER_RAN\n', output)
        self.assertIn('EXPORTED=' + launcher + '\nEXPORTED_ENV=yes\n', output)
        self.assertEqual(remaining, [])

    def test_failures_return_to_caller_and_clean(self):
        for mode in ('fail', 'empty', 'duplicate'):
            with self.subTest(mode=mode):
                output, remaining, _ = self.run_snippet(mode)
                self.assertRegex(output, r'CALLER_STATUS=[1-9][0-9]*\n')
                self.assertNotIn('LAUNCHER_RAN', output)
                self.assertEqual(remaining, [])


if __name__ == '__main__':
    unittest.main()
