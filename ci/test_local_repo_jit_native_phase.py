"""Injected lifecycle controls; never calls Docker or supplied native executables."""
import contextlib
import importlib.util
import io
import json
import runpy
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from typing import TYPE_CHECKING
from unittest.mock import patch

CHECKOUT = Path(__file__).resolve().parents[1]
ROOT = CHECKOUT / '.github/scripts/local-repo-jit'
sys.path.insert(0, str(ROOT))


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


p = load('native_phase', ROOT / 'native_phase.py')
fixtures = load('frozen_v4_fixtures', CHECKOUT / 'ci/test_local_repo_jit_docker.py')
fixtures.m.q2 = p.backend
IMAGE = fixtures.IMAGE
ERRORS = (p.Refused, p.backend.Refused)


def native():
    return {'runtime': '8.0.28', 'elf_hashes': 21,
            'unselected_alpine': ['externals/node20_alpine/bin/node', 'externals/node24_alpine/bin/node'],
            'optional_tracing_unavailable': [{'member': 'bin/libcoreclrtraceptprovider.so', 'unavailable': ['liblttng-ust.so.0']}],
            'active_runner_version': '2.336.0', 'actual_jit_qualified': False}


if TYPE_CHECKING:
    from ci.test_local_repo_jit_docker import Docker as DockerBase
else:
    DockerBase = fixtures.Docker


class Engine(DockerBase):
    def __init__(self, directory):
        super().__init__()
        self.directory = directory
        self.owner = None
        self.native_fail, self.inspect_fail, self.durable_seen = False, False, False
        self.replace_on_rm = False
        self.capacity_fail = False
        self.observation_fail = False
        self.serial_export_fault = ''
        self.serial_spooled_before_cleanup = False

    def command(self, args, data=None, check=True):
        if args[0] == 'inspect' and self.owner.receipts:
            saved = json.loads((self.directory / 'receipts.json').read_text())
            assert saved[0]['id'] == self.owner.receipts[0]['id']
            self.durable_seen = True
            if self.inspect_fail:
                self.containers[saved[0]['id']]['Config']['Labels']['myguard.project'] = 'foreign'
        if args[0] == 'exec' and args[-1] == '/opt/vm/failure_observation.py':
            import failure_observation
            raw = b'MYGUARD_CI_BOUNDARY:root_ready:pass:none\nMYGUARD_CI_BOUNDARY:provision:pass:none\nTOKEN=PRIVATE_CANARY'
            serial = self.directory / 'fake-outer-serial'
            failure_observation.private_spool(serial)
            receipt = failure_observation.spool(raw, serial)
            value = failure_observation.parse_console(raw)
            value.update(raw_retained=True, raw_receipt=receipt)
            if self.observation_fail:
                value['extra'] = 'PRIVATE_CANARY'
            self.argv.append(args)
            return subprocess.CompletedProcess(args, 0, json.dumps(value), '')
        if args[:2] == ['exec', '-i']:
            request = json.loads(data)
            if request.get('script') == 'cat /var/lib/local-ci/runner-capacity.json':
                proof = {'payload_bytes': 675809636, 'entries': 11432, 'reserve_bytes': 268435456,
                         'reserve_inodes': 1024, 'available_bytes': 0 if self.capacity_fail else 944245092, 'available_inodes': 12457}
                return subprocess.CompletedProcess(args, 0, json.dumps({'exitcode': 0, 'out-data': json.dumps(proof)}), '')
            if request.get('stdin') == p.NATIVE_COMMAND:
                proof = native()
                value = json.dumps(proof) + '\n' + json.dumps({'versions': {'runner': '2.336.0', 'node20': 'v20.20.0', 'node24': 'v24.14.0'}})
                reply = {'exitcode': 1 if self.native_fail else 0, 'signal': None,
                         'out-data': value, 'err-data': 'SYNTHETIC-UNTRUSTED-TRANSCRIPT'}
                self.argv.append(args)
                return subprocess.CompletedProcess(args, 0, json.dumps(reply), '')
            if request.get('stdin') == p.backend.FRESH:
                reply = {'exitcode': 0, 'signal': None, 'out-data': json.dumps({'fresh': True,
                         'boot_id': '11111111-2222-3333-4444-555555555555', 'docker_server': '29.1.3', 'env_names': ['PATH']})}
                return subprocess.CompletedProcess(args, 0, json.dumps(reply), '')
        if args[0] == 'cp':
            identity = args[1].split(':', 1)[0]
            assert len(identity) == 64 and identity in self.containers
            self.argv.append(args)
            if '/serial-observations/' in args[1]:
                if self.serial_export_fault == 'error':
                    raise OSError('private copy fixture')
                source = self.directory / 'fake-outer-serial' / args[1].rsplit('/', 1)[1]
                raw = source.read_bytes()
                if self.serial_export_fault == 'short':
                    raw = raw[:-1]
                if self.serial_export_fault == 'digest':
                    raw = b'x' + raw[1:]
                Path(args[2]).write_bytes(raw)
                Path(args[2]).chmod(0o644 if self.serial_export_fault == 'mode' else 0o600)
                return subprocess.CompletedProcess(args, 0, '', '')
            Path(args[2]).write_bytes(b'owned-public-native-baseline-fixture')
            return subprocess.CompletedProcess(args, 0, '', '')
        if args[0] == 'stop':
            self.argv.append(args)
            self.containers[args[-1]]['State'].update(Running=False, Pid=0, ExitCode=143)
            return subprocess.CompletedProcess(args, 0, '', '')
        if args[0] == 'rm' and self.replace_on_rm:
            identity = next((key for key, value in self.containers.items() if value['Name'] == '/' + args[1]), args[1])
            original = self.containers.pop(identity)
            original['Id'] = 'f' * 64
            self.containers['f' * 64] = original
        if args[0] == 'rm':
            serial = self.directory / 'serial-observations'
            self.serial_spooled_before_cleanup = serial.is_dir() and bool(list(serial.glob('*.bin.json')))
        result = super().command(args, data, check)
        if args[:2] == ['image', 'inspect']:
            value = json.loads(result.stdout)
            value[0]['Config']['Labels']['myguard.run'] = 'vi-local-ci-208c2d13-v4-capacity-r3-image-q1'
            result.stdout = json.dumps(value)
        if args[:2] == ['container', 'ls'] and self.preexisting:
            result.stdout += '\n' + p.NAME
        return result


class Phase(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.directory = Path(self.tmp.name) / 'native-baseline-q1'
        self.output = patch.object(p, 'OUTPUT', self.directory)
        self.output.start(); self.addCleanup(self.output.stop)
        self.engine = Engine(self.directory)
        self.events = []
        self.value = {'image': IMAGE, 'runlabel': 'vi-local-ci-208c2d13-v4-capacity-r3-image-q1'}
        self.owner = None
        self.no_subprocess = patch.object(subprocess, 'run', side_effect=AssertionError('live subprocess forbidden'))
        self.no_subprocess.start(); self.addCleanup(self.no_subprocess.stop)

    def factory(self, directory):
        self.events.append(('factory', 'owner'))
        self.owner = p.Owner(directory)
        command = self.engine.command
        def traced(args, data=None, check=True):
            self.events.append(('command', args[0]))
            if args[0] == 'cp':
                self.events.append(('copy', args[-1]))
            return command(args, data, check)
        self.owner.command = traced
        self.engine.owner = self.owner
        return self.owner

    def run_phase(self):
        return p.execute(self.value, self.directory, self.factory)

    def create(self):
        self.directory.mkdir(mode=0o700)
        owner = self.factory(self.directory)
        return owner.create(IMAGE, 'provision', 'provision-q1')

    def test_positive_native_export_pid0_nonforce_remove_absence(self):
        result = self.run_phase()
        self.assertEqual(result['status'], 'PASS')
        self.assertEqual(result['created']['name'], p.NAME)
        self.assertEqual(result['created']['runlabel'], p.NAME)
        self.assertEqual(result['created']['phase'], 'removed')
        self.assertFalse(result['jit_qualified'])
        self.assertTrue(self.engine.durable_seen)
        self.assertTrue(self.engine.serial_spooled_before_cleanup, 'raw serial export and seal must precede cleanup')
        self.assertEqual(self.engine.containers, {})
        self.assertEqual((self.directory / 'baseline.sha256').read_text().strip(), result['baseline_sha256'])
        self.assertEqual(len([a for a in self.engine.argv if a[0] == 'rm']), 1)
        self.assertFalse(any('--force' in a for a in self.engine.argv))
        self.assertNotIn('SYNTHETIC-UNTRUSTED', ''.join(x.read_text() for x in self.directory.glob('*.json')))

    def test_capacity_refusal_before_native_export_or_cleanup(self):
        self.engine.capacity_fail = True
        with self.assertRaises(ERRORS):
            self.run_phase()
        self.assertFalse((self.directory / 'native-proof.json').exists())
        self.assertFalse(any(a[0] == 'rm' or (a[0] == 'cp' and a[-1].endswith('.qcow2')) for a in self.engine.argv))
        self.assertEqual(len(self.engine.containers), 1)

    def test_native_error_redacted_running_resource_retained_then_owned_cleanup(self):
        self.engine.native_fail = True
        with self.assertRaises(ERRORS):
            self.run_phase()
        failure = (self.directory / 'failure.json').read_text()
        self.assertNotIn('SYNTHETIC-UNTRUSTED', failure)
        self.assertEqual(len(self.engine.containers), 1)
        self.assertFalse(any(a[0] == 'rm' for a in self.engine.argv))
        receipt = self.owner.receipts[0]
        p.cleanup(self.owner, receipt)
        self.assertEqual(self.engine.containers, {})
        self.assertEqual([a for a in self.engine.argv if a[0] == 'stop'], [['stop', '--time', '10', receipt['id']]])

    def test_agent_independent_failure_observation_is_redacted(self):
        self.engine.native_fail = True
        with self.assertRaises(ERRORS):
            self.run_phase()
        text = (self.directory / 'failure-observation.json').read_text()
        self.assertNotIn('PRIVATE_CANARY', text)
        self.assertEqual(json.loads(text)['status'], 'observed')
        self.assertEqual(json.loads(text)['marker_events'][0]['boundary'], 'root_ready')
        self.assertTrue(any(a[0] == 'exec' and a[-1].endswith('failure_observation.py') for a in self.engine.argv))
        files = list((self.directory / 'serial-observations').glob('*.bin'))
        self.assertEqual(len(files), 2, 'first and failure reads must both remain available')
        for path in files:
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            self.assertIn(b'PRIVATE_CANARY', path.read_bytes())
            self.assertTrue(path.with_name(path.name + '.json').is_file())

    def test_serial_export_errors_short_copy_digest_and_mode_fail_closed(self):
        for fault in ('error', 'short', 'digest', 'mode'):
            with self.subTest(fault=fault):
                case = Phase()
                try:
                    case.setUp()
                    case.engine.serial_export_fault = fault
                    with self.assertRaises(ERRORS):
                        case.run_phase()
                    self.assertFalse((case.directory / 'result.json').exists())
                    self.assertFalse(any(args[0] in ('rm', 'stop') for args in case.engine.argv))
                    self.assertEqual(len(case.engine.containers), 1)
                finally:
                    case.doCleanups()

    def test_serial_host_file_and_parent_sync_before_acceptance_and_cleanup(self):
        with self.trace_fsync():
            self.run_phase()
        raw = next((self.directory / 'serial-observations').glob('*.bin'))
        event = ('fsync', str(raw))
        self.assertIn(event, self.events, 'host raw serial file must be fsynced before acceptance')
        start = self.events.index(event)
        next_use = self.events.index(('command', 'inspect'), start)
        self.assertIn(('fsync', str(raw.parent)), self.events[start + 1:next_use],
                      'host raw serial parent must be fsynced before acceptance')
        self.assertLess(next_use, self.events.index(('command', 'rm')))

    def test_serial_host_fsync_error_or_interruption_blocks_acceptance(self):
        for directory, error in ((False, OSError), (True, OSError), (False, InterruptedError), (True, InterruptedError)):
            with self.subTest(directory=directory, error=error):
                case = Phase()
                try:
                    case.setUp()
                    def boundary(target, case=case, directory=directory):
                        root = str(case.directory / 'serial-observations')
                        return target == root if directory else target.startswith(root + '/') and target.endswith('.bin')
                    with case.trace_fsync(boundary, error=error), self.assertRaises(ERRORS):
                        case.run_phase()
                    self.assertFalse((case.directory / 'native-proof.json').exists())
                    self.assertFalse(any(args[0] in ('rm', 'stop') for args in case.engine.argv))
                finally:
                    case.doCleanups()

    def test_untrusted_observation_does_not_mask_failed_phase(self):
        self.engine.native_fail = True
        self.engine.observation_fail = True
        with self.assertRaises(ERRORS):
            self.run_phase()
        value = json.loads((self.directory / 'failure-observation.json').read_text())
        self.assertEqual(value['status'], 'unavailable')
        self.assertNotIn('PRIVATE_CANARY', str(value))
        self.assertEqual(len(self.engine.containers), 1)

    def test_preexisting_name_refused_before_create(self):
        self.engine.preexisting = True
        with self.assertRaises(ERRORS):
            self.run_phase()
        self.assertEqual(self.engine.generation, 0)
        self.assertEqual(json.loads((self.directory / 'failure.json').read_text())['admitted_name'], p.NAME)

    def test_preexisting_output_refused_without_engine_command(self):
        self.directory.mkdir()
        with self.assertRaises(FileExistsError):
            self.run_phase()
        self.assertEqual(self.engine.argv, [])

    def test_post_create_inspect_failure_still_has_durable_id(self):
        self.engine.inspect_fail = True
        with self.assertRaises(ERRORS):
            self.run_phase()
        failure = json.loads((self.directory / 'failure.json').read_text())
        self.assertEqual(len(failure['created'][0]['id']), 64)
        self.assertTrue(self.engine.durable_seen)
        self.assertFalse(any(a[0] in ('rm', 'stop') for a in self.engine.argv))

    def test_cleanup_security_drift_and_foreign_receipt_refused(self):
        receipt = self.create()
        identity = receipt['id']
        self.engine.containers[identity]['Mounts'] = [{'Source': '/etc/host-auth'}]
        with self.assertRaises(ERRORS):
            p.cleanup(self.owner, receipt)
        self.assertFalse(any(a[0] in ('rm', 'stop') for a in self.engine.argv))
        with self.assertRaises(ERRORS):
            p.cleanup(self.owner, dict(receipt, id='e' * 64))

    def test_cleanup_already_stopped_crash_exit_codes(self):
        for code in (0, 1, 125, 137, 143, 255):
            with self.subTest(code=code):
                case = Phase()
                try:
                    case.setUp()
                    receipt = case.create()
                    case.engine.containers[receipt['id']]['State'].update(Running=False, Pid=0, ExitCode=code)
                    p.cleanup(case.owner, receipt)
                    self.assertEqual(case.engine.containers, {})
                    self.assertEqual(receipt['phase'], 'removed')
                    self.assertIn(['rm', receipt['id']], case.engine.argv)
                    self.assertFalse(any(a[0] in ('stop', 'wait') for a in case.engine.argv))
                finally:
                    case.doCleanups()

    def test_cleanup_unknown_stopped_state_refuses_removal(self):
        for state in (
            dict(Running=False, Pid=42, ExitCode=1),
            dict(Running='unknown', Pid=0, ExitCode=1),
            *(dict(Running=False, Pid=0, ExitCode=code) for code in (None, True, -1, 256)),
        ):
            with self.subTest(state=state):
                case = Phase()
                try:
                    case.setUp()
                    receipt = case.create()
                    case.engine.containers[receipt['id']]['State'].update(state)
                    with self.assertRaises(ERRORS):
                        p.cleanup(case.owner, receipt)
                    self.assertEqual(receipt['phase'], 'created')
                    self.assertIn(receipt['id'], case.engine.containers)
                    self.assertFalse(any(a[0] in ('rm', 'stop') for a in case.engine.argv))
                finally:
                    case.doCleanups()

    def test_cleanup_running_wait_failure_and_same_name_replacement_refused(self):
        receipt = self.create()
        command = self.owner.command
        def failed_wait(args, data=None, check=True):
            if args[0] == 'wait':
                return subprocess.CompletedProcess(args, 0, '1', '')
            return command(args, data, check)
        self.owner.command = failed_wait
        with self.assertRaises(ERRORS):
            p.cleanup(self.owner, receipt)
        self.assertFalse(any(a[0] == 'rm' for a in self.engine.argv))
        self.owner.command = command
        self.engine.containers[receipt['id']]['State'].update(Running=False, Pid=0, ExitCode=1)
        self.engine.replace_on_rm = True
        with self.assertRaises(ERRORS):
            p.cleanup(self.owner, receipt)
        self.assertEqual(set(self.engine.containers), {'f' * 64})
        self.assertEqual(self.engine.argv[-1], ['rm', receipt['id']])
        self.assertEqual(receipt['phase'], 'created')

    def test_clean_exit_still_requires_pid_zero_before_export(self):
        receipt = self.create()
        self.engine.containers[receipt['id']]['State'].update(Running=False, Pid=123, ExitCode=0)
        with self.assertRaises(ERRORS):
            self.owner.stopped(receipt)

    def test_same_name_replacement_rm_uses_old_immutable_id(self):
        receipt = self.create()
        self.engine.replace_on_rm = True
        with self.assertRaises(ERRORS):
            self.owner.finish(receipt)
        self.assertEqual(set(self.engine.containers), {'f' * 64})
        self.assertEqual(self.engine.argv[-1], ['rm', receipt['id']])

    def test_preexisting_export_path_refused_before_shutdown(self):
        receipt = self.create()
        (self.directory / 'baseline.qcow2').write_bytes(b'preexisting')
        with self.assertRaises(ERRORS):
            self.owner.finish(receipt, export=True)
        self.assertTrue(self.engine.containers[receipt['id']]['State']['Running'])

    def test_recover_exact_existing_creation_receipt(self):
        receipt = self.create()
        with patch.object(p.Owner, 'command', side_effect=self.engine.command):
            p.recover(self.value)
        self.assertEqual(self.engine.containers, {})
        self.assertEqual(json.loads((self.directory / 'receipts.json').read_text())[0]['id'], receipt['id'])

    def test_cleanup_already_stopped_has_no_stop(self):
        receipt = self.create()
        self.engine.containers[receipt['id']]['State'].update(Running=False, Pid=0, ExitCode=0)
        p.cleanup(self.owner, receipt)
        self.assertFalse(any(args[0] == 'stop' for args in self.engine.argv))

    def trace_fsync(self, fail=None, error=OSError):
        original = p.os.fsync
        failed = False
        def observed(descriptor):
            nonlocal failed
            target = p.os.readlink('/proc/self/fd/' + str(descriptor))
            self.events.append(('fsync', target))
            if fail is not None and fail(target) and not failed:
                failed = True
                raise error('injected directory sync failure')
            return original(descriptor)
        return patch.object(p.os, 'fsync', side_effect=observed)

    def test_output_parent_synced_before_owner_and_runtime(self):
        with self.trace_fsync():
            self.run_phase()
        boundary = ('fsync', str(self.directory.parent))
        self.assertIn(boundary, self.events)
        self.assertLess(self.events.index(boundary), self.events.index(('factory', 'owner')))
        self.assertLess(self.events.index(boundary), self.events.index(('command', 'image')))

    def test_export_directory_synced_after_both_files_before_retirement_inspect(self):
        with self.trace_fsync():
            self.run_phase()
        copy_index = self.events.index(('copy', str(self.directory / 'baseline.qcow2')))
        exported = self.events.index(('fsync', str(self.directory / 'baseline.qcow2')), copy_index)
        digest = self.events.index(('fsync', str(self.directory / 'baseline.sha256')), exported)
        inspect = self.events.index(('command', 'inspect'), digest)
        self.assertIn(('fsync', str(self.directory)), self.events[digest + 1:inspect])
        self.assertLess(inspect, self.events.index(('command', 'rm'), inspect))

    def test_parent_fsync_failure_aborts_before_owner_or_runtime(self):
        with self.trace_fsync(lambda target: target == str(self.directory.parent)), self.assertRaises(OSError):
            self.run_phase()
        self.assertIsNone(self.owner)
        self.assertNotIn(('factory', 'owner'), self.events)
        self.assertEqual(self.engine.argv, [])
        self.assertTrue(self.directory.is_dir())

    def test_export_directory_fsync_failure_retains_stopped_source_without_retirement(self):
        def export_boundary(target):
            return (target == str(self.directory) and (self.directory / 'baseline.qcow2').exists()
                    and (self.directory / 'baseline.sha256').exists())
        with self.trace_fsync(export_boundary), self.assertRaises(ERRORS):
            self.run_phase()
        receipt = self.owner.receipts[0]
        self.assertEqual(receipt['phase'], 'created')
        self.assertFalse(self.engine.containers[receipt['id']]['State']['Running'])
        self.assertEqual(self.engine.containers[receipt['id']]['State']['Pid'], 0)
        self.assertFalse(any(args[0] == 'rm' for args in self.engine.argv))
        copy_index = self.events.index(('copy', str(self.directory / 'baseline.qcow2')))
        self.assertNotIn(('command', 'inspect'), self.events[copy_index + 1:])
        self.assertTrue((self.directory / 'failure.json').is_file())


class Metadata(unittest.TestCase):
    def test_native_strict_metadata_and_optional_contract(self):
        versions = {'versions': {'runner': '2.336.0', 'node20': 'v20.20.0', 'node24': 'v24.14.0'}}
        for optional in ([], native()['optional_tracing_unavailable']):
            value = native(); value['optional_tracing_unavailable'] = optional
            self.assertEqual(p.validate_native(json.dumps(value) + '\n' + json.dumps(versions))['versions'], versions['versions'])
        for key, bad in [('elf_hashes', 20), ('runtime', '9.0'), ('actual_jit_qualified', True),
                         ('optional_tracing_unavailable', [{'member': 'other', 'unavailable': ['libssl.so.3']}])]:
            value = native(); value[key] = bad
            with self.assertRaises(ERRORS):
                p.validate_native(json.dumps(value) + '\n' + json.dumps(versions))
        with self.assertRaises(ERRORS):
            p.validate_native('{}')

    def test_fresh_rejects_secret_values_or_unknown_metadata(self):
        value = {'fresh': True, 'boot_id': '11111111-2222-3333-4444-555555555555', 'docker_server': '29.1.3', 'env_names': ['PATH']}
        self.assertEqual(p.validate_fresh(value), value)
        for key, bad in [('env_names', ['TOKEN']), ('env_names', ['secret=value']), ('boot_id', 'unknown'), ('docker_server', 'latest')]:
            changed = dict(value); changed[key] = bad
            with self.assertRaises(ERRORS):
                p.validate_fresh(changed)
        with self.assertRaises(ERRORS):
            p.validate_fresh(dict(value, token='fixture'))

    def test_frozen_input_and_cli_authority_controls(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.directory = Path(temporary.name) / 'unused'
        with patch.object(p, 'sha', return_value='0' * 64), self.assertRaises(ERRORS):
            p.inputs()
        with patch('sys.argv', ['phase', '--workspace', str(self.directory.parent)]), self.assertRaises(ERRORS):
            p.main()
        for flag, target in [('--execute-reviewed-phase', 'execute'), ('--cleanup-reviewed-failed', 'recover')]:
            with patch('sys.argv', ['phase', flag, '--workspace', str(self.directory.parent)]), patch.object(p, 'admit', return_value={'image': IMAGE}), patch.object(p, target) as action:
                p.main()
                action.assert_called_once_with({'image': IMAGE})
        with patch('sys.argv', ['phase', '--workspace', '/not-existing-fixture']), contextlib.redirect_stdout(io.StringIO()) as output, self.assertRaises(SystemExit) as error:
            runpy.run_path(str(ROOT / 'native_phase.py'), run_name='__main__')
        self.assertEqual(error.exception.code, 1)
        self.assertIn('metadata only', output.getvalue())

    def test_completed_image_receipt_context_and_all_45_inputs(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); context = root / 'public-capacity-context-q1'; context.mkdir()
            sealed = {}
            for number in range(50):
                name = str(number); (context / name).write_text(name)
                sealed[name] = p.sha(context / name)
            (context / 'context-seal.json').write_text(json.dumps(sealed))
            seal = p.sha(context / 'context-seal.json')
            receipt = root / 'creation-receipt.json'
            value = {'image': IMAGE, 'project': 'vimbadmin', 'runlabel': 'vi-local-ci-208c2d13-v4-capacity-r3-image-q1',
                     'context_seal_sha256': seal, 'runner_archive_sha256': p.ARCHIVE, 'runner_version': '2.336.0', 'runtime_qualification': 'UNQUALIFIED'}
            receipt.write_text(json.dumps(value))
            with patch.object(p, 'WORKSPACE', root), patch('runner_setup.verify_materialized'), patch.object(p, 'IMAGE_RECEIPT', receipt), patch.object(p, 'CONTEXT_SEAL', seal):
                self.assertEqual(p.admit(receipt), value)
                value['image'] = 'opaque'; receipt.write_text(json.dumps(value))
                with self.assertRaises(ERRORS):
                    p.admit(receipt)
                value['image'] = IMAGE; receipt.write_text(json.dumps(value))
                (context / '0').write_text('drift')
                with self.assertRaises(ERRORS):
                    p.admit(receipt)
                (context / '0').write_text('0')
                (context / 'extra').write_text('unsealed')
                with self.assertRaises(ERRORS):
                    p.admit(receipt)


if __name__ == '__main__':
    unittest.main()
