"""Provisioning order and fresh-consumer dependencies, without runtime authority."""

import os
import py_compile
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

CHECKOUT = Path(__file__).resolve().parents[1]
ROOT = Path(os.environ.get('PR_BOOTSTRAP_TEST_ROOT', CHECKOUT))
HELPERS = ('guest_capacity', 'public_recipe_selection', 'baseline_budget', 'request_model', 'runner_setup')
BASH = shutil.which('bash')
sys.path.insert(0, str(CHECKOUT / '.github/scripts/local-repo-jit'))
import request_model as model
import runner_setup as setup


class RunnerBootstrap(unittest.TestCase):
    def setUp(self):
        temporary = self.enterContext(tempfile.TemporaryDirectory(prefix='vi-bootstrap-'))
        self.directory = Path(temporary)
        self.helpers = self.directory / '.github/scripts/local-repo-jit'
        self.helpers.mkdir(parents=True)
        for name in HELPERS:
            shutil.copyfile(CHECKOUT / '.github/scripts/local-repo-jit' / (name + '.py'),
                            self.helpers / (name + '.py'))
        shutil.copytree(ROOT / '.github/workflows', self.directory / '.github/workflows')
        gitdir = subprocess.check_output(['git', '-c', 'safe.directory=' + str(CHECKOUT), '-C', str(CHECKOUT),
                                         'rev-parse', '--absolute-git-dir'],
                                        env=dict(os.environ, GIT_OPTIONAL_LOCKS='0'), text=True).strip()
        (self.directory / '.git').write_text('gitdir: ' + gitdir + '\n')
        self.sha = subprocess.check_output(['git', '-c', 'safe.directory=' + str(CHECKOUT), '-C', str(CHECKOUT),
                                           'rev-parse', 'HEAD'],
                                          env=dict(os.environ, GIT_OPTIONAL_LOCKS='0'), text=True).strip()
        self.request = {
            'schema': model.SCHEMA, 'mode': 'qualified-actual',
            'repository': model.REPOSITORY, 'candidate_sha': self.sha,
            'run_id': 312, 'run_attempt': 1, 'job_id': model.MAX_JOB_ID, 'runner_name': '',
            'guest': {'id': 'b' * 64, 'boot_id': '12345678-1234-1234-1234-123456789abc'},
            'pins': {key: ('sha256:' if key == 'runtime_image' else '') + 'c' * 64
                     for key in model.PIN_FIELDS},
            'qualification_id': 'd' * 64, 'admission_id': 'e' * 64,
        }
        self.request['runner_name'] = model.runner_name(self.request)
        self.slot = self.directory / 'slot'
        self.publish(setup.slot_metadata(self.request))
        self.environment = dict(os.environ, GIT_OPTIONAL_LOCKS='0',
                                RUNNER_ENVIRONMENT='self-hosted', GITHUB_EVENT_NAME='pull_request',
                                RUNNER_NAME=self.request['runner_name'], GITHUB_REPOSITORY=model.REPOSITORY,
                                GITHUB_RUN_ID='312', GITHUB_RUN_ATTEMPT='1',
                                PR_RUNNER_EXPECTED_SHA=self.sha, PR_RUNNER_SLOT_FILE=str(self.slot),
                                PR_RUNNER_WORKFLOW_DIR=str(self.directory / '.github/workflows'),
                                PATH='/usr/bin:/bin')

    def publish(self, text):
        source = self.directory / 'source'
        source.write_text(text)
        subprocess.run([*(['sudo', '-n'] if os.geteuid() else []), 'install', '-o', '0', '-g', '0', '-m', '0444',
                        str(source), str(self.slot)], check=True, capture_output=True)

    def guard(self, **environment):
        return subprocess.run([BASH, str(ROOT / '.github/scripts/assert-pr-runner-isolation.sh')],
                              cwd=self.directory, env=self.environment | environment,
                              capture_output=True, text=True, timeout=20, check=False)

    def assert_bootstrap_rejected(self, result):
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('requires Python 3 with pathlib and maintained consumer helpers', result.stderr)
        self.assertNotIn('Published slot request', result.stderr)
        self.assertNotIn('All pull-request jobs use authorized disposable runner placement', result.stdout)

    def test_every_php_job_provisions_python_before_checkout_and_guard(self):
        found = []
        for filename in ('ci.yml', 'regression.yml', 'static-analysis.yml'):
            workflow = (ROOT / '.github/workflows' / filename).read_text()
            for block in re.split(r'(?m)^  (?=[a-z][a-z0-9-]*:\n)', workflow)[1:]:
                if '    container:\n' not in block or 'mirror.gcr.io/library/php@sha256:' not in block:
                    continue
                with self.subTest(workflow=filename, job=block.splitlines()[0]):
                    bootstrap = block.index('apt-get update && apt-get install --yes git python3')
                    checkout = block.index('uses: actions/checkout@')
                    guard = block.index('run: bash .github/scripts/assert-pr-runner-isolation.sh')
                    self.assertLess(bootstrap, checkout)
                    self.assertLess(checkout, guard)
                    found.append((filename, block.splitlines()[0]))
        self.assertEqual(len(found), 8, found)

    def test_fresh_consumer_positive_and_binding_boundary(self):
        result = self.guard()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('All pull-request jobs use authorized disposable runner placement', result.stdout)
        result = self.guard(GITHUB_RUN_ATTEMPT='01')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('does not match this runner/workflow', result.stderr)

    def test_missing_and_shadowed_interpreter_fail_before_consumer_use(self):
        tools = self.directory / 'tools'
        tools.mkdir()
        (tools / 'stat').symlink_to(shutil.which('stat'))
        self.assert_bootstrap_rejected(self.guard(PATH=str(tools)))
        marker = self.directory / 'executed-shadow'
        interpreter = tools / 'python3'
        interpreter.write_text('#!/bin/sh\n: >"' + str(marker) + '"\nexit 0\n')
        interpreter.chmod(0o755)
        result = self.guard(PATH=str(tools))
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('refuses a shadowed Python interpreter', result.stderr)
        self.assertFalse(marker.exists(), 'shadow interpreter ran before precheck')

    def test_missing_nonregular_and_symlinked_helpers_fail_before_import(self):
        marker = self.directory / 'imported-helper'
        original = {name: (self.helpers / (name + '.py')).read_bytes() for name in HELPERS}
        # The first helper must never import if any closure member is invalid.
        first = self.helpers / (HELPERS[0] + '.py')
        first.write_text('from pathlib import Path\nPath(' + repr(str(marker)) + ').touch()\n')
        for name in HELPERS:
            target = self.helpers / (name + '.py')
            content = target.read_bytes()
            for mode in ('missing', 'directory', 'symlink'):
                with self.subTest(helper=name, mode=mode):
                    target.unlink()
                    if mode == 'directory':
                        target.mkdir()
                    elif mode == 'symlink':
                        shadow = self.directory / 'shadow.py'
                        shadow.write_bytes(content)
                        target.symlink_to(shadow)
                    try:
                        self.assert_bootstrap_rejected(self.guard())
                        self.assertFalse(marker.exists(), 'helper executed before closure precheck')
                    finally:
                        if target.is_dir():
                            target.rmdir()
                        elif target.is_symlink():
                            target.unlink()
                        target.write_bytes(content)
        for name, content in original.items():
            (self.helpers / (name + '.py')).write_bytes(content)
        # Canonical helper directory aliases must fail too.
        moved = self.directory / 'moved'
        self.helpers.rename(moved)
        self.helpers.symlink_to(moved, target_is_directory=True)
        self.assert_bootstrap_rejected(self.guard())

    def test_import_error_and_wrong_origin_fail_before_slot_validation(self):
        target = self.helpers / 'runner_setup.py'
        for source in ('raise ImportError("missing stdlib")\n', 'invalid syntax !\n',
                       '__file__ = "/tmp/foreign.py"\n'):
            with self.subTest(source=source):
                target.write_text(source)
                self.assert_bootstrap_rejected(self.guard())

    def test_cwd_and_pythonpath_cannot_shadow_helpers_or_pathlib(self):
        marker = self.directory / 'imported-shadow'
        shadow = 'from pathlib import Path\nPath(' + repr(str(marker)) + ').touch()\nraise RuntimeError("shadow")\n'
        for name in (*HELPERS, 'pathlib'):
            (self.directory / (name + '.py')).write_text(shadow)
        result = self.guard(PYTHONPATH=str(self.directory), PYTHONHOME=str(self.directory))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(marker.exists())

    def test_inherited_bytecode_cannot_replace_maintained_source(self):
        target = self.helpers / 'runner_setup.py'
        original = target.read_bytes()
        info = target.stat()
        marker = self.directory / 'imported-bytecode'
        poison = ('from pathlib import Path\nPath(' + repr(str(marker)) + ').touch()\n').encode()
        target.write_bytes(poison + b'#' * (len(original) - len(poison)))
        os.utime(target, ns=(info.st_atime_ns, info.st_mtime_ns))
        py_compile.compile(str(target), doraise=True)
        target.write_bytes(original)
        os.utime(target, ns=(info.st_atime_ns, info.st_mtime_ns))
        result = self.guard()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(marker.exists(), 'inherited bytecode executed instead of maintained source')

    def test_legacy_runner_still_needs_no_python(self):
        self.publish(setup.slot_metadata())
        for path in self.helpers.iterdir():
            path.unlink()
        tools = self.directory / 'legacy-tools'
        tools.mkdir()
        for name in ('stat', 'tail', 'od', 'tr', 'git', 'grep', 'awk'):
            (tools / name).symlink_to(shutil.which(name))
        result = self.guard(RUNNER_NAME=setup.LEGACY_RUNNER, PATH=str(tools))
        self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == '__main__':
    unittest.main()
