"""Fresh public placement binding; no runtime, API, listener or credential use."""

import ast
import copy
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

CHECKOUT = Path(__file__).resolve().parents[1]
ROOT = CHECKOUT / '.github/scripts/local-repo-jit'
LEGACY_SELECTOR = '    runs-on: ["self-hosted-run-${{ github.run_id }}-${{ github.run_attempt }}", "vimbadmin-owned-local-v1-run-${{ github.run_id }}-${{ github.run_attempt }}", "docker-run-${{ github.run_id }}-${{ github.run_attempt }}", "jit-env-input-run-${{ github.run_id }}-${{ github.run_attempt }}", "vimbadmin-pristine-jit-v1-run-${{ github.run_id }}-${{ github.run_attempt }}"]'
sys.path.insert(0, str(ROOT))
import request_model as model
import runner_setup as setup


def checkout_head():
    # Fixture identity must not follow an inherited Git repository or config.
    env = {key: value for key, value in os.environ.items()
           if not key.startswith('GIT_')}
    env.update(GIT_OPTIONAL_LOCKS='0', GIT_CONFIG_NOSYSTEM='1',
               GIT_CONFIG_GLOBAL=os.devnull)
    return subprocess.check_output(
        ['git', '-c', 'safe.directory=' + str(CHECKOUT), '-C', str(CHECKOUT),
         'rev-parse', 'HEAD'],
        env=env, text=True).strip()


def request_fixture():
    request = {
        'schema': model.SCHEMA, 'mode': 'qualified-actual',
        'repository': model.REPOSITORY, 'candidate_sha': 'a' * 40,
        'run_id': 312, 'run_attempt': 2, 'job_id': 17, 'runner_name': '',
        'guest': {'id': 'b' * 64, 'boot_id': '12345678-1234-1234-1234-123456789abc'},
        'pins': {key: ('sha256:' if key == 'runtime_image' else '') + 'c' * 64
                 for key in model.PIN_FIELDS},
        'qualification_id': 'd' * 64, 'admission_id': 'e' * 64,
    }
    request['runner_name'] = model.runner_name(request)
    return request


class ConsumerBinding(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='vi-q4-consumer-')
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.slot = self.directory / 'slot'
        workflows = self.directory / '.github/workflows'
        workflows.mkdir(parents=True)
        for source in (CHECKOUT / '.github/workflows').glob('*.yml'):
            text = source.read_text().replace('    runs-on: ubuntu-24.04', LEGACY_SELECTOR)
            text = text.replace('    container:\n', '    container:\n      volumes:\n        - /run/myguard-ci-slot:/run/myguard-ci-slot:ro\n')
            (workflows / source.name).write_text(text)
        self.request = request_fixture()
        self.expected = {
            'runner_name': self.request['runner_name'], 'candidate_sha': self.request['candidate_sha'],
            'repository': model.REPOSITORY, 'run_id': '312', 'run_attempt': '2',
        }
        self.publish(setup.slot_metadata(self.request))

    def publish(self, data):
        source = self.directory / 'source'
        source.write_bytes(data.encode() if isinstance(data, str) else data)
        subprocess.run(['sudo', '-n', 'install', '-o', '0', '-g', '0', '-m', '0444',
                        str(source), str(self.slot)], check=True, capture_output=True)

    def validate(self, path=None, **changes):
        return setup.validate_slot(self.slot if path is None else path, **(self.expected | changes))

    def guard(self, **changes):
        env = dict(os.environ, RUNNER_ENVIRONMENT='self-hosted', GITHUB_EVENT_NAME='pull_request',
                   RUNNER_NAME=self.request['runner_name'], GITHUB_REPOSITORY=model.REPOSITORY,
                   GITHUB_RUN_ID='312', GITHUB_RUN_ATTEMPT='2', PR_RUNNER_SLOT_FILE=str(self.slot),
                   PR_RUNNER_WORKFLOW_DIR=str(self.directory / '.github/workflows'),
                   PR_RUNNER_EXPECTED_SHA=self.request['candidate_sha'])
        env.update(changes)
        return subprocess.run(['bash', str(CHECKOUT / '.github/scripts/assert-pr-runner-isolation.sh')],
                              cwd=CHECKOUT, env=env, capture_output=True, text=True, timeout=20, check=False)

    def test_same_request_producer_installer_consumer_and_canonical_order(self):
        raw = setup.slot_metadata(self.request)
        self.assertEqual(self.validate(), model.request_digest(self.request))
        self.assertEqual(raw, setup.slot_metadata(dict(reversed(list(self.request.items())))))
        installer = setup.installer_for(self.request)
        ast.parse(installer)
        self.assertIn(repr(raw), installer)
        self.assertIn("'runner_name':" + repr(self.request['runner_name']), installer)
        self.assertNotIn('__SLOT_METADATA__', installer)
        self.assertNotIn('__RUNNER_NAME__', installer)
        # Supplied records prove consistency only, including this producer-side seam.
        binding = {key: copy.deepcopy(self.request[key]) for key in model.BINDING_FIELDS | {'runner_name'}}
        digest = model.request_digest(self.request)
        result = model.validate_request(
            self.request,
            qualification=dict(binding, schema='vimbadmin-local-jit-qualification-v1', state='qualified', request_sha256=digest),
            admission=dict(binding, schema='vimbadmin-local-jit-admission-v1', state='unused', request_sha256=digest),
            maintained_pins={key: value for key, value in self.request['pins'].items()
                             if key not in {'runtime_image', 'baseline_context_seal'}})
        self.assertEqual(result['runner_name'], raw.splitlines()[0])
        self.assertFalse(result['execution_allowed'])
        self.assertEqual(setup.INSTALL, setup.installer_for())
        self.assertEqual(setup.slot_metadata(), 'vi-local-ci-208c2d13-01\n/opt/actions-runner\n')

    def test_stage_threads_validated_request_into_sealed_installer(self):
        import test_local_repo_jit_docker as fixtures
        case = fixtures.PublicSeed()
        self.addCleanup(case.doCleanups)
        case.setUp()
        for request, name in [(None, setup.LEGACY_RUNNER), (self.request, self.request['runner_name'])]:
            output = case.root / name
            with patch.object(fixtures.s.subprocess, 'run', side_effect=case.iso):
                fixtures.s.stage(case.archive, output, request=request)
            installer = (output / 'iso/install-runner.py').read_text()
            self.assertIn(repr(setup.slot_metadata(request)), installer)
            seal = json.loads((output / 'context-seal.json').read_text())
            self.assertEqual(seal['iso/install-runner.py'], setup.sha(output / 'iso/install-runner.py'))
            self.assertIn("'runner_name':" + repr(name), installer)

    def test_all_binding_fields_change_runner_and_old_slot_is_refused(self):
        for key, value in [('candidate_sha', 'f' * 40), ('run_id', 313), ('run_attempt', 3),
                           ('job_id', 18), ('qualification_id', 'f' * 64), ('admission_id', 'f' * 64),
                           ('guest', dict(self.request['guest'], id='f' * 64)),
                           ('pins', dict(self.request['pins'], baseline_context_seal='f' * 64))]:
            changed = dict(self.request, **{key: value})
            changed['runner_name'] = model.runner_name(changed)
            self.assertNotEqual(changed['runner_name'], self.request['runner_name'])
            with self.subTest(key=key), self.assertRaisesRegex(ValueError, 'workflow mismatch'):
                self.validate(runner_name=changed['runner_name'])

    def test_forged_request_name_and_fields_refused_before_staging(self):
        for changes in [{'runner_name': 'vi-local-ci-' + 'a' * 12 + '-' + '0' * 24},
                        {'repository': 'foreign/repo'}, {'run_attempt': True}, {'extra': 'forbidden'},
                        {'mode': 'fixture-proof'}]:
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                setup.installer_for(dict(self.request, **changes))
        import test_local_repo_jit_docker as fixtures
        case = fixtures.PublicSeed()
        self.addCleanup(case.doCleanups)
        case.setUp()
        with self.assertRaisesRegex(ValueError, 'fresh bound runner'):
            fixtures.s.stage(case.archive, self.directory / 'never-created', request=dict(self.request, runner_name='forged'))
        self.assertFalse((self.directory / 'never-created').exists())

    def test_workflow_missing_forged_and_numeric_noncanonical_values(self):
        for key in self.expected:
            for value in ['', 'forged']:
                with self.subTest(key=key, value=value), self.assertRaisesRegex(ValueError, 'workflow mismatch'):
                    self.validate(**{key: value})
        for key in ('run_id', 'run_attempt'):
            for value in ['0312', '+312', '312\n', '2.0']:
                with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                    self.validate(**{key: value})

    def test_metadata_path_type_owner_mode_and_size_refused(self):
        link = self.directory / 'link'
        link.symlink_to(self.slot)
        alias = self.directory / 'alias'
        alias.symlink_to(self.directory, target_is_directory=True)
        fifo = self.directory / 'fifo'
        os.mkfifo(fifo)
        for path in [self.directory / 'absent', Path('relative'), link, alias / 'slot',
                     self.directory / 'missing' / '..' / 'slot', self.directory, fifo]:
            with self.subTest(path=path), self.assertRaises((OSError, ValueError)):
                self.validate(path)
        real = os.stat(self.slot)
        for changes in [{'st_uid': 1000}, {'st_gid': 1000}, {'st_mode': 0o100644},
                        {'st_mode': 0o104444}, {'st_mode': 0o40755},
                        {'st_size': setup.MAX_SLOT_BYTES + 1}]:
            info = {key: getattr(real, key) for key in ('st_uid', 'st_gid', 'st_mode', 'st_size')}
            with patch.object(setup.os, 'fstat', return_value=SimpleNamespace(**(info | changes))), self.assertRaisesRegex(ValueError, 'metadata refused'):
                self.validate()
        self.slot = self.directory / 'user-slot'
        self.slot.write_text(setup.slot_metadata(self.request))
        self.slot.chmod(0o444)
        with self.assertRaisesRegex(ValueError, 'metadata refused'):
            self.validate()

    def test_malformed_truncated_duplicate_unknown_noncanonical_records(self):
        raw = setup.slot_metadata(self.request)
        lines = raw.splitlines()
        record = json.loads(lines[2])
        records = [dict(record, schema='wrong'), dict(record, extra=1),
                   dict(record, request_sha256='0' * 64), dict(record, request=None),
                   dict(record, request=dict(self.request, runner_name='forged'))]
        bad = [raw[:-1], raw + '\n', raw.replace('/opt/actions-runner', '/tmp/actions-runner'),
               raw.replace(lines[0], 'forged', 1), lines[0] + '\n/opt/actions-runner\n',
               '\n'.join(lines[:2]) + '\n{bad}\n',
               raw.replace('"request":', '"request":{},"request":', 1),
               raw.replace('"schema":', '"schema" :', 1),
               raw + 'x' * setup.MAX_SLOT_BYTES]
        bad += ['\n'.join(lines[:2]) + '\n' + json.dumps(r) + '\n' for r in records]
        for value in bad:
            self.publish(value)
            with self.subTest(size=len(value)), self.assertRaises(ValueError):
                self.validate()

    def test_canonical_slot_and_request_size_boundaries(self):
        raw = setup.slot_metadata(self.request)
        with patch.object(setup, 'MAX_SLOT_BYTES', len(raw)):
            self.assertEqual(setup.slot_metadata(self.request), raw)
            self.assertEqual(self.validate(), model.request_digest(self.request))
        with patch.object(setup, 'MAX_SLOT_BYTES', len(raw) - 1), self.assertRaisesRegex(ValueError, 'size refused'):
            setup.slot_metadata(self.request)
        for value in (1, model.MAX_JOB_ID):
            request = dict(self.request, run_id=value, run_attempt=value, job_id=value)
            request['runner_name'] = model.runner_name(request)
            self.publish(setup.slot_metadata(request))
            self.assertEqual(self.validate(runner_name=request['runner_name'], run_id=str(value), run_attempt=str(value)), model.request_digest(request))

    def test_workflow_accepts_exact_head_semantics_and_rejects_cross_binding(self):
        sha = checkout_head()
        self.request['candidate_sha'] = sha
        self.request['runner_name'] = model.runner_name(self.request)
        self.publish(setup.slot_metadata(self.request))
        # Ordinary PR GITHUB_SHA may identify a merge commit; maintained guard uses exact checkout head.
        result = self.guard(GITHUB_SHA='0' * 40)
        self.assertEqual(result.returncode, 0, result.stderr)
        for key in ('RUNNER_NAME', 'GITHUB_REPOSITORY', 'GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT', 'PR_RUNNER_EXPECTED_SHA'):
            for value in ['', 'forged']:
                result = self.guard(**{key: value})
                with self.subTest(key=key, value=value):
                    self.assertNotEqual(result.returncode, 0)
        forged = self.request['runner_name'][:-1] + ('0' if self.request['runner_name'][-1] != '0' else '1')
        result = self.guard(RUNNER_NAME=forged)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('does not match this runner/workflow', result.stderr)
        result = self.guard(PR_RUNNER_EXPECTED_SHA='0' * 40)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('does not match this runner/workflow', result.stderr)
        self.publish(setup.slot_metadata())
        result = self.guard()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('does not match this runner/workflow', result.stderr)

    def test_installer_writes_exact_metadata_exclusively_and_root_readonly(self):
        # Execute only the actual generated publication block against a disposable fixture path.
        # Archive extraction, native probing and guest execution are deliberately absent.
        installer = setup.installer_for(self.request)
        begin = installer.index("slot=pathlib.Path('/run/myguard-ci-slot')")
        end = installer.index("pathlib.Path('/var/lib/local-ci/runner-inputs-ready')")
        script = 'import os,pathlib\n' + installer[begin:end].replace("'/run/myguard-ci-slot'", repr(str(self.directory / 'installed')))
        script_path = self.directory / 'install-slot.py'
        script_path.write_text(script)
        command = ['sudo', '-n', sys.executable, '-O', '-I', str(script_path)]
        result = subprocess.run(command, capture_output=True, text=True, timeout=10, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        installed = self.directory / 'installed'
        self.assertEqual(installed.read_text(), setup.slot_metadata(self.request))
        self.assertEqual((installed.stat().st_uid, installed.stat().st_gid, installed.stat().st_mode & 0o7777), (0, 0, 0o444))
        self.assertEqual(self.validate(installed), model.request_digest(self.request))
        result = subprocess.run(command, capture_output=True, text=True, timeout=10, check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('FileExistsError', result.stderr)
        installed_alias = self.directory / 'installed-link'
        installed_alias.symlink_to(installed)
        script_path.write_text(script.replace(repr(str(installed)), repr(str(installed_alias))))
        result = subprocess.run(command, capture_output=True, text=True, timeout=10, check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('FileExistsError', result.stderr)
        self.assertEqual(installed.read_text(), setup.slot_metadata(self.request))
        parent_alias = self.directory / 'parent-alias'
        parent_alias.symlink_to(self.directory, target_is_directory=True)
        script_path.write_text(script.replace(repr(str(installed)), repr(str(parent_alias / 'never-created'))))
        result = subprocess.run(command, capture_output=True, text=True, timeout=10, check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('slot parent path refused', result.stderr)
        self.assertFalse((self.directory / 'never-created').exists())
        # These controls execute the generated checks with optimization enabled;
        # fchown/fchmod/readback failures must never publish a successful readiness path.
        prefixes = [
            ("real=os.fchown;os.fchown=lambda fd,uid,gid:real(fd,1000,gid)\n", 'slot metadata refused'),
            ("real=os.fchown;os.fchown=lambda fd,uid,gid:real(fd,uid,1000)\n", 'slot metadata refused'),
            ("real=os.fchmod;os.fchmod=lambda fd,mode:real(fd,0o644)\n", 'slot metadata refused'),
            ("pathlib.Path.read_text=lambda path:'changed'\n", 'slot content changed'),
        ]
        for index, (prefix, message) in enumerate(prefixes):
            destination = self.directory / ('rejected-' + str(index))
            injected = 'import os,pathlib\n' + prefix + script.removeprefix('import os,pathlib\n')
            script_path.write_text(injected.replace(repr(str(installed)), repr(str(destination))))
            result = subprocess.run(command, capture_output=True, text=True, timeout=10, check=False)
            with self.subTest(message=message, index=index):
                self.assertNotEqual(result.returncode, 0)
                self.assertIn(message, result.stderr)


if __name__ == '__main__':
    unittest.main()
