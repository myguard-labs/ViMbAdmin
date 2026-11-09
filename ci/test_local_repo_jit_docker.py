"""Pure boundary fixtures: no Docker, VM, credentials or network execution."""
import base64
import contextlib
import copy
import errno
import hashlib
import importlib.util
import io
import json
import os
import runpy
import subprocess
import sys
import tarfile
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import patch

CHECKOUT = Path(__file__).resolve().parents[1]
ROOT = CHECKOUT / '.github/scripts/local-repo-jit'
sys.path.insert(0, str(ROOT))


def load(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


m = load('docker_jit', 'producer.py')
b = load('bridge', 'listener_bridge.py')
s = load('setup', 'runner_setup.py')
n = load('native_policy', 'native_policy.py')
ERRORS = (m.Refused, m.q2.Refused, m.v2.Refused)
IMAGE = 'sha256:' + 'a' * 64
SECRET = 'SYNTHETIC-FIXTURE-ONLY-private-jit'
LABEL = 'vi-local-proof-fixture-a1234567'
REF = 'local-proof-208c2d13'
SHA = 'b' * 40


class Docker:
    """Fake Docker engine; real frozen Owned.create/act/finish/validate run above it."""
    def __init__(self):
        self.containers = {}
        self.argv = []
        self.generation = 0
        self.listener_done = False
        self.listener_code = 0
        self.dirty = False
        self.credentials = False
        self.bad_version = False
        self.same_boot = False
        self.preexisting = False
        self.race = False
        self.foreign_received = False
        self.transferred = 0
        self.slot = False
        self.last_exec = None

    def factory(self, directory):
        owner = m.q2.Owned(directory)
        owner.command = self.command
        return owner

    def command(self, args, data=None, check=True):
        self.argv.append(list(args))  # No stdin/log retention.
        code, out, err = 0, '', ''
        if args[:2] == ['image', 'inspect']:
            out = json.dumps([{'Id': IMAGE, 'Config': {'Labels': {'myguard.project': 'vimbadmin'}, 'Env': ['PATH=/usr/bin']}}])
        elif args[:2] == ['container', 'ls']:
            out = '\n'.join(x['Name'][1:] for x in self.containers.values())
            if self.preexisting:
                out += '\n' + m.q2.PREFIX + 'life-q1'
        elif args[0] == 'create':
            self.generation += 1
            identity = format(self.generation, '064x')
            name = args[args.index('--name') + 1]
            self.containers[identity] = {
                'Id': identity, 'Name': '/' + name, 'Image': IMAGE, 'Mounts': [],
                'Config': {'Image': IMAGE, 'Labels': {'myguard.run': name, 'myguard.project': 'vimbadmin'}, 'Env': ['PATH=/usr/bin']},
                'HostConfig': {'CapDrop': ['ALL'], 'SecurityOpt': ['no-new-privileges:true'], 'NetworkMode': 'bridge',
                               'Memory': 3221225472, 'NanoCpus': 2000000000, 'PidsLimit': 256, 'IpcMode': 'private',
                               'Devices': [{'PathOnHost': '/dev/kvm', 'PathInContainer': '/dev/kvm', 'CgroupPermissions': 'rw'}]},
                'State': {'Running': False, 'Pid': 0, 'ExitCode': 0}}
            out = identity
        elif args[0] == 'inspect':
            identity = args[-1]
            if identity not in self.containers:
                code, err = 1, 'No such container'
            else:
                out = json.dumps([self.containers[identity]])
        else:
            identity = args[2] if args[:2] == ['exec', '-i'] else args[1]
            literal = identity
            identity = next((key for key, value in self.containers.items() if value['Name'] == '/' + literal), identity)
            if self.race and args[0] == 'exec':
                self.race = False
                foreign = copy.deepcopy(self.containers.pop(identity))
                foreign['Id'] = 'f' * 64
                foreign['Config']['Labels'] = {'myguard.run': 'foreign', 'myguard.project': 'other'}
                self.containers[foreign['Id']] = foreign
                # Real Docker resolves a human name at execution time, not query time.
                identity = next((key for key, value in self.containers.items() if value['Name'] == '/' + literal), literal)
            if identity not in self.containers:
                code, err = 1, 'No such container'
            elif args[0] == 'start':
                self.containers[identity]['State'].update(Running=True, Pid=100 + self.generation)
            elif args[0] == 'wait':
                out = '0'
            elif args[0] == 'rm':
                assert self.containers[identity]['State']['Running'] is False
                del self.containers[identity]
            elif args[0] == 'exec':
                self.last_exec = list(args)
                request = json.loads(data)
                if 'helper' in request:
                    result = b.perform(request['request'], self.agent)
                    out = json.dumps(result)
                else:
                    script = request['script']
                    result = {'exitcode': 0, 'signal': None, 'out-data': ''}
                    if script == 'python3 -' and request['stdin'] == m.q2.FRESH:
                        result['out-data'] = json.dumps({'fresh': not self.dirty, 'boot_id': 'fixed' if self.same_boot else 'boot-' + str(self.generation)})
                    elif 'Runner.Listener --version' in script:
                        result['out-data'] = 'wrong' if self.bad_version else '2.336.0\n'
                    elif script == m.v2.CLEAN and self.credentials:
                        result['exitcode'] = 1
                    elif 'mktemp /run/.myguard-ci-slot' in script:
                        assert m.RUNNER_NAME in script and 'builder02' not in script
                        self.slot = True
                    elif 'poweroff' in script:
                        self.containers[identity]['State'].update(Running=False, Pid=0)
                    out = json.dumps(result)
            else:
                raise AssertionError('unexpected fake Docker operation ' + args[0])
        if code and check:
            raise m.q2.Refused('owned Docker operation failed; request/output not logged')
        return subprocess.CompletedProcess(args, code, out, err)

    def agent(self, request):
        if request['execute'] == 'guest-exec':
            args = request['arguments']
            assert args['capture-output'] is False
            assert SECRET not in json.dumps(args['arg'])
            assert base64.b64decode(args['input-data']).decode() == SECRET + '\n'
            self.transferred += 1
            self.listener_done = False
            return {'pid': 201}
        assert request == {'execute': 'guest-exec-status', 'arguments': {'pid': 201}}
        return {'exited': self.listener_done, 'exitcode': self.listener_code,
                'out-data': SECRET, 'err-data': SECRET}


class API:
    def __init__(self, docker):
        self.docker, self.rid, self.run = docker, 1000, None
        self.calls = []
        self.retirement = 404
        self.inventory = 200
        self.wrong_branch = False
        self.wrong_placement = False
        self.duplicate = False
        self.mint_fail = False
        self.busy = False
        self.hold = False
        self.cancelled = False

    def transport(self, method, path, body):
        self.calls.append((method, path))  # Metadata only, never body/payload.
        if path == 'actions/runners/generate-jitconfig':
            assert m.RUNNER_NAME == body['name'] and body['work_folder'] == '_work'
            assert 'builder02' not in body['labels'] and m.v2.CAPABILITY not in body['labels']
            assert 'vimbadmin-owned-local-v1' in body['labels']
            if self.mint_fail:
                return 403, {}
            self.rid += 1
            return 201, {'runner': {'id': self.rid, 'name': m.RUNNER_NAME}, 'encoded_jit_config': SECRET}
        if path.startswith('git/ref/heads/'):
            return 200, {'object': {'sha': SHA}}
        if path.endswith('/dispatches'):
            self.hold = body['inputs']['mode'] == 'hold'
            self.cancelled = False
            self.run = {'id': self.rid + 5000, 'display_title': 'local-jit-proof:' + body['inputs']['marker'] + ':' + body['inputs']['mode'],
                        'head_sha': SHA, 'head_branch': 'foreign-ref' if self.wrong_branch else body['ref'],
                        'event': 'workflow_dispatch', 'path': '.github/workflows/static-analysis.yml@' + SHA,
                        'status': 'in_progress' if self.hold else 'completed', 'conclusion': None if self.hold else 'success'}
            self.docker.listener_done = not self.hold
            return 204, {}
        if '/runs?event=' in path:
            return 200, {'workflow_runs': [] if self.run is None else [self.run] * (2 if self.duplicate else 1)}
        if path.endswith('/jobs'):
            return 200, {'jobs': [{'runner_id': -1 if self.wrong_placement else self.rid, 'status': self.run['status']}]}
        if path.endswith('/cancel'):
            self.cancelled = True
            self.run.update(status='completed', conclusion='cancelled')
            self.docker.listener_done = True
            return 202, {}
        if path == 'actions/runners?per_page=1':
            return self.inventory, {}
        if path.startswith('actions/runners/'):
            if method == 'DELETE':
                return 204, {}
            return self.retirement, {'id': self.rid, 'name': m.RUNNER_NAME, 'busy': self.busy}
        if path.startswith('actions/runs/'):
            return 200, self.run
        raise AssertionError('unexpected fixture API path')


class Fixture(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.docker = Docker()
        self.config = json.loads((ROOT / 'config.json').read_text())
        self.config.update(runtime_image=IMAGE, baseline_context_seal='c' * 64)
        self.guest = m.DockerGuest(self.root / 'receipt', self.config, self.docker.factory, fixture=True)
        self.backend = API(self.docker)
        self.api = m.RepoAPI(transport=self.backend.transport, guard=self.guest.identity)
        self.supervisor = m.Supervisor(self.guest, self.api)
        self.no_subprocess = patch.object(subprocess, 'run', side_effect=AssertionError('live subprocess forbidden'))
        self.addCleanup(self.no_subprocess.stop)
        self.no_subprocess.start()
        self.no_network = patch.object(m.v2.urllib.request, 'urlopen', side_effect=AssertionError('network forbidden'))
        self.addCleanup(self.no_network.stop)
        self.no_network.start()

    def baseline(self):
        self.guest.create()
        self.guest.prepare_baseline()

    def cycle(self, mode='normal'):
        # Each fixture models the GitHub run list before its unique dispatch.
        self.backend.run = None
        with patch.object(m.v2.time, 'sleep'):
            return self.supervisor.run_one(1, LABEL, 'static-analysis.yml', REF, SHA,
                                           'fixture-run-12345678-' + mode, mode, timeout=5)

    def test_serial_positive_cancel_probe_fresh_reset_reap(self):
        self.baseline()
        identities, boots = [], []
        for mode in ('normal', 'probe', 'hold', 'probe'):
            identities.append(self.guest.receipt['id'])
            boots.append(self.guest.boot)
            result = self.cycle(mode)
            self.assertEqual(result['reset'], 'clean')
            self.assertIsNone(self.guest.outstanding)
            self.assertTrue(self.guest.qualified)
        self.assertEqual(len(set(identities)), 4)
        self.assertEqual(len(set(boots)), 4)
        self.assertEqual(self.docker.transferred, 4)
        self.assertTrue(self.docker.slot)
        self.assertTrue(any(path.endswith('/cancel') for _, path in self.backend.calls))
        self.guest.remove()
        self.assertEqual(self.docker.containers, {})
        self.assert_no_secrets()

    def assert_no_secrets(self):
        self.assertNotIn(SECRET, json.dumps(self.docker.argv))
        self.assertNotIn(base64.b64encode((SECRET + '\n').encode()).decode(), json.dumps(self.docker.argv))
        for path in self.root.rglob('*.json'):
            self.assertNotIn(SECRET, path.read_text())
            self.assertNotIn('encoded_jit_config', path.read_text())

    def test_stale_id_name_replacement_never_receives_stdin(self):
        self.baseline()
        # Race after real act() inspect/validate, before ID-addressed exec.
        original = self.guest.owner.act
        def race(receipt, before, after=(), data=None):
            if data and '"mode": "start"' in data:
                self.docker.race = True
            return original(receipt, before, after, data)
        self.guest.owner.act = race
        with self.assertRaises(ERRORS):
            self.supervisor.begin(1, LABEL)
        self.assertEqual(self.guest.outstanding, 1001)
        self.assertEqual(self.docker.transferred, 0)
        self.assertEqual(set(self.docker.containers), {'f' * 64})
        self.assertFalse(any(x[0] == 'rm' for x in self.docker.argv))
        with self.assertRaises(ERRORS):
            self.guest.reset()
        with self.assertRaises(ERRORS):
            self.guest.remove()
        with self.assertRaises(ERRORS):
            self.supervisor.begin(1, LABEL)
        self.assert_no_secrets()

    def test_unresolved_retirement_blocks_successor(self):
        self.baseline()
        self.backend.retirement = 200
        old_id = self.guest.receipt['id']
        with self.assertRaises(ERRORS):
            self.cycle()
        self.assertEqual(self.guest.outstanding, self.backend.rid)
        self.assertEqual(self.guest.receipt['id'], old_id)
        with self.assertRaises(ERRORS):
            self.supervisor.begin(1, LABEL)
        self.assertFalse(any(method == 'DELETE' for method, _ in self.backend.calls))

    def test_inventory_denial(self):
        self.baseline()
        self.backend.inventory = 403
        with self.assertRaises(ERRORS):
            self.cycle()
        self.assertEqual(self.guest.outstanding, 1001)

    def test_wrong_placement(self):
        self.baseline()
        self.backend.wrong_placement = True
        with self.assertRaises(ERRORS):
            self.cycle('hold')
        self.assertFalse(self.backend.cancelled)

    def test_branch_ref_binding(self):
        self.baseline()
        rid, process = self.supervisor.begin(1, LABEL)
        proof = self.api.dispatch('static-analysis.yml', REF, SHA, 'fixture-run-12345678', 'hold')
        self.backend.run['head_branch'] = 'other-branch'
        self.assertIsNone(self.api.find_run(proof))
        with self.assertRaises(ERRORS):
            self.api.cancel(self.backend.run['id'], rid)
        self.backend.run['head_branch'] = REF
        self.api.find_run(proof)
        self.backend.run['head_branch'] = 'other-branch'
        with self.assertRaises(ERRORS):
            self.api.cancel(self.backend.run['id'], rid)
        self.assertFalse(self.backend.cancelled)
        self.assertIsNone(process.poll())

    def test_listener_error_or_unreaped_prevents_reset(self):
        self.baseline()
        self.docker.listener_code = 7
        with self.assertRaises(ERRORS):
            self.cycle()
        self.assertEqual(self.guest.outstanding, 1001)
        self.assertTrue(self.guest.process.reaped)
        self.assertEqual(self.guest.process.poll(), 7)
        self.guest.outstanding = None  # Isolated reset predicate fixture only.
        self.guest.process.reaped = False
        with self.assertRaises(ERRORS):
            self.guest.reset()
        with self.assertRaises(ERRORS):
            self.guest.remove()

    def test_dirty_or_credentials_or_version_refuse_before_mint(self):
        self.baseline()
        for flag in ('dirty', 'credentials', 'bad_version'):
            setattr(self.docker, flag, True)
            with self.assertRaises(ERRORS):
                self.supervisor.begin(1, LABEL)
            setattr(self.docker, flag, False)
        self.assertEqual(self.backend.calls, [])

    def test_security_drift_and_stale_listener_generation(self):
        self.baseline()
        rid, process = self.supervisor.begin(1, LABEL)
        info = self.docker.containers[self.guest.receipt['id']]
        for mutate in (lambda: info.update(Mounts=[{'Source': '/etc'}]),
                       lambda: info['Config']['Labels'].update({'myguard.project': 'foreign'})):
            old = copy.deepcopy(info)
            mutate()
            with self.assertRaises(ERRORS):
                process.poll()
            info.clear(); info.update(old)
        process.outer_id = 'e' * 64
        with self.assertRaises(ERRORS):
            process.poll()
        self.assertEqual(self.guest.outstanding, rid)

    def test_failed_mint_keeps_pending_and_refuses_removal(self):
        self.baseline()
        self.backend.mint_fail = True
        with self.assertRaises(ERRORS):
            self.supervisor.begin(1, LABEL)
        self.assertEqual(self.guest.outstanding, 'mint-pending')
        with self.assertRaises(ERRORS):
            self.guest.remove()

    def test_preexisting_and_unqualified_inputs(self):
        with self.assertRaises(ERRORS):
            m.DockerGuest(self.root / 'never', self.config, self.docker.factory)
        with self.assertRaises(FileExistsError):
            m.DockerGuest(self.root / 'receipt', self.config, self.docker.factory, fixture=True)
        self.docker.preexisting = True
        with self.assertRaises(ERRORS):
            self.guest.create()
        self.assertEqual(self.docker.containers, {})
        self.config['runtime_image'] = None
        other = m.DockerGuest(self.root / 'other', self.config, self.docker.factory, fixture=True)
        with self.assertRaises(ERRORS):
            other.create()

    def test_stale_reset_rejects_reused_guest_boot(self):
        self.docker.same_boot = True
        self.baseline()
        with self.assertRaises(ERRORS):
            self.cycle()
        self.assertFalse(self.guest.qualified)
        self.assertIsNone(self.guest.outstanding)

    def test_authority_scope_name_labels_and_ref(self):
        for kwargs in ({'external': True, 'transport': self.backend.transport}, {}, {'transport': 7}):
            with self.assertRaises(ERRORS):
                m.RepoAPI(**kwargs)
        with self.assertRaises(ERRORS):
            m.Supervisor(self.guest, m.RepoAPI(transport=self.backend.transport, guard=lambda: None))
        for name, group, labels in [('builder02', 1, [LABEL]), (m.RUNNER_NAME, True, [LABEL]), (m.RUNNER_NAME, 1, [m.v2.CAPABILITY])]:
            with self.assertRaises(ERRORS):
                self.api.mint(name, group, labels)
        for ref in ('main', 'master', 'invalid/ref'):
            with self.assertRaises(ERRORS):
                self.api.dispatch('static-analysis.yml', ref, SHA, 'fixture-run-12345678', 'normal')
        self.baseline()
        for method, path in [('DELETE', 'actions/runners/123'), ('POST', 'actions/runs/123/cancel')]:
            with self.assertRaises(ERRORS):
                self.api.request(method, path)
        self.assertFalse(any(method in ('DELETE', 'POST') for method, _ in self.backend.calls))

    def test_helper_pin_prevents_stdin_open(self):
        self.baseline()
        with patch.object(m, 'BRIDGE_SHA', '0' * 64), self.assertRaises(ERRORS):
            self.supervisor.begin(1, LABEL)
        self.assertEqual(self.docker.transferred, 0)
        self.assertEqual(self.guest.outstanding, 1001)

    def test_owned_idle_deletion_only_and_timeout_retains_identity(self):
        self.baseline()
        rid, _ = self.supervisor.begin(1, LABEL)
        self.backend.retirement = 200
        self.backend.busy = True
        with self.assertRaises(ERRORS):
            self.api.delete_idle(rid)
        self.assertFalse(any(method == 'DELETE' for method, _ in self.backend.calls))
        self.backend.busy = False
        self.api.delete_idle(rid)
        self.assertIn(('DELETE', 'actions/runners/' + str(rid)), self.backend.calls)
        self.assertEqual(self.guest.outstanding, rid)  # API DELETE is not a retirement/reset proof.

    def test_timeout_other_branch_never_adopts_cancels_or_resets(self):
        self.baseline()
        self.backend.wrong_branch = True
        original_id = self.guest.receipt['id']
        with patch.object(m.v2.time, 'monotonic', side_effect=[0, 1, 9]), patch.object(m.v2.time, 'sleep'), self.assertRaises(ERRORS):
            self.supervisor.run_one(1, LABEL, 'static-analysis.yml', REF, SHA, 'fixture-run-12345678', 'hold', timeout=5)
        self.assertEqual(self.guest.receipt['id'], original_id)
        self.assertEqual(self.guest.outstanding, 1001)
        self.assertFalse(self.backend.cancelled)


class Bridge(unittest.TestCase):
    def test_private_agent_transport_hash_and_sync(self):
        from unittest.mock import MagicMock
        body = b'fixture exchange transport'
        connection = MagicMock()
        connection.__enter__.return_value = connection
        reader = MagicMock()
        connection.makefile.return_value.__enter__.return_value = reader
        def exchange(_, __, request):
            if request['execute'] == 'guest-sync':
                return request['arguments']['id']
            return {'pid': 17}
        spec = types.SimpleNamespace(loader=MagicMock())
        transport = types.SimpleNamespace(exchange=exchange)
        with (patch.object(b.Path, 'read_bytes', return_value=body),
              patch.object(b, 'QGA_SHA', hashlib.sha256(body).hexdigest()),
              patch.object(b.importlib.util, 'spec_from_file_location', return_value=spec),
              patch.object(b.importlib.util, 'module_from_spec', return_value=transport),
              patch.object(b.socket, 'socket', return_value=connection)):
            self.assertEqual(b.main({'mode': 'start', 'payload': SECRET}), {'pid': 17})
            connection.connect.assert_called_once_with(b.SOCKET)
            transport.exchange = lambda *_: -1
            with self.assertRaises(RuntimeError):
                b.main({'mode': 'start', 'payload': SECRET})
        with patch.object(b.Path, 'read_bytes', return_value=body), self.assertRaises(RuntimeError):
            b.main({'mode': 'start', 'payload': SECRET})

    def test_start_private_env_and_no_capture(self):
        observed = []
        def call(request):
            observed.append(request)
            return {'pid': 17, 'untrusted': SECRET}
        self.assertEqual(b.perform({'mode': 'start', 'payload': SECRET}, call), {'pid': 17})
        args = observed[0]['arguments']
        self.assertFalse(args['capture-output'])
        self.assertEqual(args['arg'][:5], ['-u', 'runner', '--', 'env', '-i'])
        self.assertIn('ACTIONS_RUNNER_INPUT_JITCONFIG', args['arg'][-1])
        self.assertNotIn(SECRET, json.dumps(args['arg']))
        self.assertEqual(base64.b64decode(args['input-data']), (SECRET + '\n').encode())

    def test_status_strips_transcripts_signal_pending_and_code(self):
        for result, expected in [({'exited': False, 'out-data': SECRET}, {'exited': False}),
                                 ({'exited': True, 'exitcode': 0, 'out-data': SECRET}, {'exited': True, 'exitcode': 0, 'signal': None}),
                                 ({'exited': True, 'signal': 15, 'err-data': SECRET}, {'exited': True, 'exitcode': None, 'signal': 15})]:
            self.assertEqual(b.perform({'mode': 'status', 'pid': 17}, lambda _, result=result: result), expected)

    def test_malformed_inputs_and_unresolved_reply(self):
        requests = [{}, {'mode': 'delete'}, {'mode': 'start', 'payload': ''}, {'mode': 'start', 'payload': '\n'},
                    {'mode': 'start', 'payload': '\r'}, {'mode': 'start', 'payload': 5},
                    {'mode': 'start', 'payload': 'x' * 1000001}, {'mode': 'start', 'payload': SECRET, 'argv': []},
                    {'mode': 'status', 'pid': True}, {'mode': 'status', 'pid': 0}, {'mode': 'status', 'pid': 1, 'other': 2}]
        for request in requests:
            with self.assertRaises(ValueError):
                b.perform(request, lambda _: self.fail('must refuse before guest operation'))
        for result in ({}, {'pid': True}, {'pid': 0}):
            with self.assertRaises(ValueError):
                b.perform({'mode': 'start', 'payload': SECRET}, lambda _, result=result: result)
        for result in ({}, {'exited': 1}, {'exited': True}, {'exited': True, 'exitcode': 0, 'signal': 1}):
            with self.assertRaises(ValueError):
                b.perform({'mode': 'status', 'pid': 17}, lambda _, result=result: result)


class Contracts(unittest.TestCase):
    def test_configuration_frozen_manifest_and_cli(self):
        config = json.loads((ROOT / 'config.json').read_text())
        self.assertEqual(m.validate_config(config), config)
        for field, value in [('external_authority', True), ('runtime_qualification', 'PASS'), ('repository', 'other/repo'),
                             ('runner_name', 'builder02'), ('runtime_image', 'latest'), ('baseline_context_seal', 'bad')]:
            changed = dict(config); changed[field] = value
            with self.assertRaises(ERRORS):
                m.validate_config(changed)
        with self.assertRaises(ERRORS):
            m.validate_config({})
        with tempfile.TemporaryDirectory() as path:
            directory = Path(path)
            (directory / 'code-inputs.json').write_text('{}')
            with self.assertRaises(ERRORS):
                m.frozen(directory, '0' * 64, 'x.py', 'bad')
            (directory / 'x.py').write_text('raise AssertionError("must not execute")')
            (directory / 'code-inputs.json').write_text(json.dumps({'x.py': {'sha256': '0' * 64}}))
            seal = hashlib.sha256((directory / 'code-inputs.json').read_bytes()).hexdigest()
            with self.assertRaises(ERRORS):
                m.frozen(directory, seal, 'x.py', 'bad')
        with patch('sys.argv', ['prototype', '--validate-config']), contextlib.redirect_stdout(io.StringIO()) as output:
            m.main()
        self.assertEqual(json.loads(output.getvalue())['runtime'], 'UNQUALIFIED')
        with patch('sys.argv', ['prototype', '--run']), self.assertRaises(ERRORS):
            m.main()
        with patch('sys.argv', ['prototype', '--validate-config']), contextlib.redirect_stdout(io.StringIO()):
            runpy.run_path(str(ROOT / 'producer.py'), run_name='__main__')

    def test_pinned_runner_setup_is_offline_and_before_pristine_marker(self):
        compile(s.INSTALL, '<installer>', 'exec')
        self.assertEqual(s.ARCHIVE_SHA, '04cf0be1aff4c3ec3554466c39124ca250e3effd8873bb7e8d68535aa9505d5d')
        self.assertIn('filter=\'data\'', s.INSTALL)
        self.assertIn('native_policy.verify(target)', s.INSTALL)
        self.assertIn("'runuser','-u','runner','--','docker','info'", s.INSTALL)
        self.assertNotIn('installdependencies.sh', s.INSTALL)
        self.assertNotIn('apt-get', s.INSTALL)
        with patch('sys.argv', ['setup', '--describe-inputs']), contextlib.redirect_stdout(io.StringIO()) as output:
            s.main()
        self.assertFalse(json.loads(output.getvalue())['local_archive_available'])
        with patch('sys.argv', ['setup', '--describe-inputs']), contextlib.redirect_stdout(io.StringIO()):
            runpy.run_path(str(ROOT / 'runner_setup.py'), run_name='__main__')
        with patch('sys.argv', ['setup']), self.assertRaises(ValueError):
            s.main()
        for argv in (['setup', '--stage-verified-public-seed', '--archive', '/public/archive'],
                     ['setup', '--build-verified-public-seed'],
                     ['setup', '--build-verified-public-seed', '--output-dir', str(ROOT / 'forbidden-public-inputs')]):
            with patch('sys.argv', argv), self.assertRaises(ValueError):
                s.main()
        with self.assertRaisesRegex(ValueError, 'explicit public input'):
            s.stage(Path('/not-read'), Path('/not-created'))


class PublicSeed(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.q2 = self.root / 'q2'
        self.source = self.q2 / 'build-provision-q1'
        (self.source / 'iso').mkdir(parents=True)
        (self.source / 'iso/provision.py').write_text("import pathlib,subprocess\npathlib.Path('/var/lib/local-ci/provisioned').touch()\n")
        (self.source / 'Dockerfile').write_text('LABEL myguard.run="vi-local-ci-208c2d13-vm-image-q2"\nCOPY base.sha256 outer.py qga.py seed.iso /opt/vm/\n')
        (self.source / 'outer.py').write_text("import subprocess\nfrom pathlib import Path\nroot=Path('/opt/vm');work=Path('/work/vi-local-ci-208c2d13-vm-q2')\nexpected='public'\nsubprocess.run(['qemu-img', 'create', '-f', 'qcow2', '-F', 'qcow2', '-b', str(root / 'base.qcow2'), str(work / 'owned.qcow2')], check=True, stdout=subprocess.DEVNULL)\nserial=['-serial', 'null']\n")
        (self.source / 'qga.py').write_text("WORK='/work/vi-local-ci-208c2d13-vm-q2'\n")
        (self.source / 'iso/user-data').write_text('#cloud-config\n{}\n')
        (self.source / 'iso/meta-data').write_text('instance-id: fixture\n')
        (self.source / 'public-disk').write_bytes(b'public-immutable-payload')
        (self.source / 'seed.iso').write_bytes(b'old-public-seed')
        self.archive = self.root / s.ARCHIVE
        self.files = {'run.sh': b'fixture script', 'bin/Runner.Listener': b'fixture binary', 'bin/native.so': b'fixture shared library'}
        with tarfile.open(self.archive, 'w:gz') as output:
            for relative, body in self.files.items():
                info = tarfile.TarInfo(relative); info.size = len(body)
                output.addfile(info, io.BytesIO(body))
        self.hashes = {key: hashlib.sha256(body).hexdigest() for key, body in self.files.items()}
        self.seal()
        self.patches = [patch.object(s, 'Q2', self.q2), patch.object(s, 'Q2_MANIFEST', s.sha(self.q2 / 'manifest.json')),
                        patch.object(s, 'ARCHIVE_SHA', s.sha(self.archive)), patch.object(s, 'FILES', self.hashes)]
        for item in self.patches:
            item.start(); self.addCleanup(item.stop)

    def seal(self):
        seal = {str(path.relative_to(self.source)): s.sha(path) for path in self.source.rglob('*') if path.is_file() and path.name != 'context-seal.json'}
        (self.source / 'context-seal.json').write_text(json.dumps(seal))
        (self.q2 / 'manifest.json').write_text(json.dumps({'build-provision-q1/context-seal.json': {'sha256': s.sha(self.source / 'context-seal.json')}}))

    def iso(self, argv, **kwargs):
        self.assertEqual(argv[0], 'genisoimage')
        self.assertEqual(kwargs['env'], {'PATH': s.os.defpath})
        Path(argv[argv.index('-output') + 1]).write_bytes(b'new-public-iso')

    def test_stage_independent_seed_preserves_inputs_and_hashes(self):
        output = self.root / 'prepared'
        before = {str(path): s.sha(path) for path in self.source.rglob('*') if path.is_file()}
        with patch.object(s.subprocess, 'run', side_effect=self.iso):
            s.stage(self.archive, output)
        self.assertEqual({str(path): s.sha(Path(path)) for path in before}, before)
        self.assertEqual((output / 'public-disk').stat().st_ino, (self.source / 'public-disk').stat().st_ino)
        self.assertNotEqual((output / 'Dockerfile').stat().st_ino, (self.source / 'Dockerfile').stat().st_ino)
        provision = (output / 'iso/provision.py').read_text()
        self.assertLess(provision.index('/mnt/install-runner.py'), provision.index("pathlib.Path('/var/lib/local-ci/provisioned').touch()"))
        self.assertIn('guest_capacity.admit(', (output / 'iso/install-runner.py').read_text())
        seal = json.loads((output / 'context-seal.json').read_text())
        self.assertTrue(all(s.sha(output / path) == digest for path, digest in seal.items()))
        with patch.object(s.subprocess, 'run', side_effect=self.iso), self.assertRaises(FileExistsError):
            s.stage(self.archive, output)

    def test_stage_cross_filesystem_copy_preserves_inputs_mode_and_seal(self):
        output = self.root / 'prepared'
        (self.source / 'public-disk').chmod(0o550)
        before = {str(path): (s.sha(path), path.stat().st_mode, path.stat().st_ino)
                  for path in self.source.rglob('*') if path.is_file()}
        with patch.object(s.os, 'link', side_effect=OSError(errno.EXDEV, 'cross-device fixture')), patch.object(s.subprocess, 'run', side_effect=self.iso):
            s.stage(self.archive, output)
        self.assertEqual({path: (s.sha(Path(path)), Path(path).stat().st_mode, Path(path).stat().st_ino)
                          for path in before}, before)
        self.assertNotEqual((output / 'public-disk').stat().st_ino, (self.source / 'public-disk').stat().st_ino)
        self.assertEqual((output / 'public-disk').read_bytes(), (self.source / 'public-disk').read_bytes())
        self.assertEqual((output / 'public-disk').stat().st_mode, (self.source / 'public-disk').stat().st_mode)
        seal = json.loads((output / 'context-seal.json').read_text())
        self.assertTrue(all(s.sha(output / path) == digest for path, digest in seal.items()))

    def test_stage_non_exdev_errors_never_copy_or_seal(self):
        for number in (errno.EPERM, errno.EACCES, errno.EEXIST, errno.EIO):
            with self.subTest(errno=number):
                output = self.root / ('error-' + str(number))
                with patch.object(s.os, 'link', side_effect=OSError(number, 'link fixture')), patch.object(s.shutil, 'copyfileobj') as copied, patch.object(s.subprocess, 'run') as iso, self.assertRaises(OSError) as raised:
                    s.stage(self.archive, output)
                self.assertEqual(raised.exception.errno, number)
                copied.assert_not_called()
                iso.assert_not_called()
                self.assertFalse((output / 'context-seal.json').exists())

    def test_stage_cross_filesystem_partial_copy_failure_never_seals_or_retries(self):
        output = self.root / 'partial'
        before = s.sha(self.source / 'public-disk')
        def partial(_source, target):
            target.write(b'partial')
            raise OSError(errno.ENOSPC, 'copy fixture')
        with patch.object(s.os, 'link', side_effect=OSError(errno.EXDEV, 'cross-device fixture')), patch.object(s.shutil, 'copyfileobj', side_effect=partial), patch.object(s.subprocess, 'run') as iso, self.assertRaises(OSError) as raised:
            s.stage(self.archive, output)
        self.assertEqual(raised.exception.errno, errno.ENOSPC)
        self.assertFalse((output / 'context-seal.json').exists())
        self.assertEqual(s.sha(self.source / 'public-disk'), before)
        iso.assert_not_called()
        with self.assertRaises(FileExistsError):
            s.stage(self.archive, output)

    def test_stage_cross_filesystem_corrupt_copy_refuses_original_digest(self):
        output = self.root / 'corrupt'
        def corrupt(_source, target):
            target.write(b'wrong public copy')
        with patch.object(s.os, 'link', side_effect=OSError(errno.EXDEV, 'cross-device fixture')), patch.object(s.shutil, 'copyfileobj', side_effect=corrupt), patch.object(s.subprocess, 'run') as iso, self.assertRaisesRegex(ValueError, 'copied public input fingerprint'):
            s.stage(self.archive, output)
        self.assertFalse((output / 'context-seal.json').exists())
        iso.assert_not_called()

    def test_stage_cross_filesystem_collision_preserves_existing_destination(self):
        output = self.root / 'collision'
        def collide(_source, destination):
            Path(destination).write_bytes(b'existing fixture')
            raise OSError(errno.EXDEV, 'cross-device fixture')
        with patch.object(s.os, 'link', side_effect=collide), patch.object(s.subprocess, 'run') as iso, self.assertRaises(FileExistsError):
            s.stage(self.archive, output)
        self.assertEqual((output / 'public-disk').read_bytes(), b'existing fixture')
        self.assertFalse((output / 'context-seal.json').exists())
        iso.assert_not_called()

    def test_stage_cross_filesystem_source_drift_before_sealing_is_refused(self):
        output = self.root / 'drift'
        def changed(argv, **kwargs):
            self.iso(argv, **kwargs)
            (self.source / 'public-disk').write_bytes(b'changed public fixture')
        with patch.object(s.os, 'link', side_effect=OSError(errno.EXDEV, 'cross-device fixture')), patch.object(s.subprocess, 'run', side_effect=changed), self.assertRaisesRegex(ValueError, 'public input changed during staging'):
            s.stage(self.archive, output)
        self.assertFalse((output / 'context-seal.json').exists())

    def test_stage_capacity_refusal_precedes_link_or_copy(self):
        output = self.root / 'capacity'
        with patch.object(s, 'host_ready', side_effect=ValueError('host capacity budget unproved')), patch.object(s.os, 'link') as linked, patch.object(s.shutil, 'copyfileobj') as copied, patch.object(s.subprocess, 'run') as iso, self.assertRaisesRegex(ValueError, 'capacity budget'):
            s.stage(self.archive, output)
        self.assertFalse(output.exists())
        linked.assert_not_called()
        copied.assert_not_called()
        iso.assert_not_called()

    def test_build_seal_private_config_and_immutable_receipt(self):
        output = self.root / 'prepared'
        with patch.object(s.subprocess, 'run', side_effect=self.iso):
            s.stage(self.archive, output)
        def command(argv, **kwargs):
            self.assertEqual(argv[:2], ['docker', '--config'])
            self.assertIn('--network=none', argv)
            self.assertIn('--pull=false', argv)
            self.assertEqual(kwargs['env'], {'PATH': s.os.defpath})
            config = Path(argv[2])
            self.assertEqual(list(config.iterdir()), [])
            Path(argv[argv.index('--iidfile') + 1]).write_text(IMAGE)
            return subprocess.CompletedProcess(argv, 0)
        s.build(output, command)
        receipt = json.loads((self.root / 'prepared-build-receipts/creation-receipt.json').read_text())
        self.assertEqual(receipt['image'], IMAGE)
        self.assertEqual(receipt['runtime_qualification'], 'UNQUALIFIED')
        self.assertEqual(receipt['context_seal_sha256'], s.sha(output / 'context-seal.json'))
        with self.assertRaises(FileExistsError):
            s.build(output, command)
        (output / 'extra').write_text('drift')
        with self.assertRaisesRegex(ValueError, 'drift'):
            s.build(output, command)
        with patch('sys.argv', ['setup', '--build-verified-public-seed', '--output-dir', str(output)]), patch.object(s, 'build') as build:
            s.main()
        build.assert_called_once_with(output, subprocess.run)
        with patch('sys.argv', ['setup', '--build-verified-public-seed', '--archive', 'wrong']), self.assertRaises(ValueError):
            s.main()

    def test_build_missing_full_id_refuses_receipt(self):
        output = self.root / 'prepared'
        with patch.object(s.subprocess, 'run', side_effect=self.iso):
            s.stage(self.archive, output)
        def command(argv, **_):
            Path(argv[argv.index('--iidfile') + 1]).write_text('short-id')
            return subprocess.CompletedProcess(argv, 0)
        with self.assertRaisesRegex(ValueError, 'immutable image ID'):
            s.build(output, command)
        self.assertFalse((self.root / 'prepared-build-receipts/creation-receipt.json').exists())

    def test_hash_seal_archive_member_and_context_refusals(self):
        with patch.object(s, 'ARCHIVE_SHA', '0' * 64), self.assertRaises(ValueError):
            s.stage(self.archive, self.root / 'bad')
        with patch.dict(s.FILES, {'run.sh': '0' * 64}), self.assertRaises(ValueError):
            s.stage(self.archive, self.root / 'bad')
        (self.source / 'context-seal.json').write_text('{}')
        with self.assertRaisesRegex(ValueError, 'context seal'):
            s.stage(self.archive, self.root / 'bad')
        self.seal()
        with patch.object(s, 'Q2_MANIFEST', s.sha(self.q2 / 'manifest.json')):
            (self.source / 'public-disk').write_bytes(b'drift')
            with self.assertRaisesRegex(ValueError, 'context changed'):
                s.stage(self.archive, self.root / 'bad')

    def test_marker_changed_refuses_sealing_and_cli_stage_dispatch(self):
        (self.source / 'iso/provision.py').write_text('missing marker')
        self.seal()
        with patch.object(s, 'Q2_MANIFEST', s.sha(self.q2 / 'manifest.json')), self.assertRaisesRegex(ValueError, 'prerequisite marker'):
            s.stage(self.archive, self.root / 'bad')
        output = self.root / 'public-output'
        with patch('sys.argv', ['setup', '--stage-verified-public-seed', '--archive', str(self.archive), '--public-input-dir', str(self.q2), '--output-dir', str(output)]), patch.object(s, 'stage') as stage:
            s.main()
        stage.assert_called_once_with(self.archive, output)

    def test_installer_native_preflight_and_metadata_fail_closed(self):
        # Execute the installer in a private mapped filesystem, no guest/host commands.
        import pathlib
        import pwd
        import shutil
        guest = self.root / 'guest'
        real_path = pathlib.Path
        def mapped(value):
            return guest / str(value).lstrip('/')
        (mapped('/mnt')).mkdir(parents=True)
        shutil.copyfile(self.archive, mapped('/mnt/' + s.ARCHIVE))
        for name in ('native_policy.py', 'native-inputs.json'):
            shutil.copyfile(ROOT / name, mapped('/mnt/' + name))
        mapped('/opt').mkdir()
        mapped('/run').mkdir()
        mapped('/var/lib/local-ci').mkdir(parents=True)
        install = s.INSTALL.replace('04cf0be1aff4c3ec3554466c39124ca250e3effd8873bb7e8d68535aa9505d5d', s.sha(self.archive)).replace(repr({'run.sh': 'b39d7e0ca921a3189f7fe4e0a2f686b46719d4ccc2647f156f14407ec4517e8f', 'bin/Runner.Listener': 'b73c247aa9f0b4198aeb00ae924b7b137c0a717e5dc1066919a80fa4876fdda4', 'bin/Runner.Listener.dll': 'ae306d39005112b465026b6afd9f6d2c6c59684b38c4394323a9ffa4634fa544', 'bin/Runner.Common.dll': '4507ccfd86aefc0c2e246ed17be2e5a0be771dbc435083da53e56d8ba717bc9f'}), repr(self.hashes))
        account = types.SimpleNamespace(pw_uid=1000, pw_gid=1000, pw_dir='/home/runner')
        from unittest.mock import MagicMock
        policy = types.SimpleNamespace(verify=MagicMock(return_value={'native': 'fixture-only'}))
        def check_output(argv, **_):
            if '--version' in argv:
                return '2.336.0\n'
            return '{"Containers":0,"Images":0}'
        # Root-only metadata is represented by mocked stat after real local write.
        actual_stat = real_path.stat
        def stat(path, *args, **kwargs):
            result = actual_stat(path, *args, **kwargs)
            if str(path) == str(guest / 'run/myguard-ci-slot'):
                values = list(result); values[4] = 0; values[5] = 0
                return s.os.stat_result(values)
            return result
        with (patch.object(pathlib, 'Path', side_effect=mapped), patch.object(pwd, 'getpwnam', return_value=account),
              patch.object(shutil, 'chown'), patch.object(s.os, 'chown'), patch.object(real_path, 'stat', stat),
              patch.object(sys, 'path', list(sys.path)),
              patch.dict(sys.modules, {'native_policy': policy}),
              patch.object(subprocess, 'check_output', side_effect=check_output)):
            # Maintained installer runs with private mapped paths and mocked operations.
            exec(compile(install, '<private-installer-fixture>', 'exec'), {})  # noqa: S102 - fixed public installer in mapped fixture  # nosemgrep: python.lang.security.audit.exec-detected.exec-detected
            policy.verify.assert_called_once()
            self.assertEqual((guest / 'run/myguard-ci-slot').read_text(), m.RUNNER_NAME + '\n/opt/actions-runner\n')
            with self.assertRaisesRegex(AssertionError, 'preexisting'):
                # Maintained installer runs with private mapped paths and mocked operations.
                exec(compile(install, '<private-installer-fixture>', 'exec'), {})  # noqa: S102 - fixed public installer in mapped fixture  # nosemgrep: python.lang.security.audit.exec-detected.exec-detected
            for error in [ValueError('required native dependency missing'), ValueError('native loader inspection unresolved')]:
                shutil.rmtree(guest / 'opt/actions-runner')
                shutil.rmtree(guest / 'opt/local-ci-inputs')
                (guest / 'var/lib/local-ci/runner-inputs-ready').unlink(missing_ok=True)
                (guest / 'run/myguard-ci-slot').unlink(missing_ok=True)
                policy.verify.side_effect = error
                with self.assertRaises(ValueError):
                    # Maintained installer runs with private mapped paths and mocked operations.
                    exec(compile(install, '<private-installer-fixture>', 'exec'), {})  # noqa: S102 - bounded private mapped fixture  # nosemgrep: python.lang.security.audit.exec-detected.exec-detected
                self.assertFalse((guest / 'var/lib/local-ci/runner-inputs-ready').exists())


class NativePolicy(unittest.TestCase):
    def test_pinned_native_metadata_tamper_rejected_before_load(self):
        with patch.object(n.Path, 'read_bytes', return_value=b'tampered'), self.assertRaisesRegex(ValueError, 'input metadata changed'):
            load('tampered_native', 'native_policy.py')

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.inputs = copy.deepcopy(n.INPUTS)
        for relative, data in self.inputs['members'].items():
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(relative.encode())
            data['sha256'] = hashlib.sha256(relative.encode()).hexdigest()
        self.patch = patch.object(n, 'INPUTS', self.inputs)
        self.patch.start(); self.addCleanup(self.patch.stop)
        self.calls, self.loads = [], []
        self.bad_member, self.bad_text, self.bad_code = None, '', 0

    def command(self, argv, **kwargs):
        self.calls.append(argv)
        self.assertNotIn('COMPlus_LTTng', kwargs['env'])
        self.assertNotIn('DOTNET_LTTng', kwargs['env'])
        if argv[0] == 'ldd':
            self.assertNotIn('_alpine', argv[1])
            relative = str(Path(argv[1]).relative_to(self.root))
            if relative == self.bad_member:
                return subprocess.CompletedProcess(argv, self.bad_code, self.bad_text, '')
            if relative == 'bin/libcoreclrtraceptprovider.so':
                return subprocess.CompletedProcess(argv, 0, 'liblttng-ust.so.0 => not found\nlibc.so.6 => /lib/libc.so.6', '')
            return subprocess.CompletedProcess(argv, 0, 'libc.so.6 => /lib/libc.so.6', '')
        self.assertEqual(kwargs['env'], {'PATH': os.defpath})
        self.assertNotIn('LD_LIBRARY_PATH', kwargs['env'])
        version = '2.336.0' if '/bin/Runner.Listener' in argv[-2] else ('v20.1.2' if 'node20' in argv[-2] else 'v24.1.2')
        return subprocess.CompletedProcess(argv, self.bad_code, version, '')

    def test_optional_exact_missing_only(self):
        try:
            result = n.verify(self.root, self.command, self.loads.append)
        except ValueError as error:
            self.fail('source-proven optional PAL failure was rejected: ' + str(error))
        self.assertEqual(result['optional_tracing_unavailable'], [{'member': 'bin/libcoreclrtraceptprovider.so', 'unavailable': ['liblttng-ust.so.0']}])
        self.assertEqual(len(self.calls), 22)  # 19 selected ELF inspections plus3 active versions.
        self.assertEqual(self.loads, self.inputs['dynamic_system_libraries'])
        self.assertFalse(result['actual_jit_qualified'])

    def test_version_probe_uses_fixed_runuser_and_drops_privileges(self):
        n.verify(self.root, self.command, self.loads.append)
        calls = [argv for argv in self.calls if argv[0] != 'ldd']
        self.assertEqual(len(calls), 3)
        for argv in calls:
            self.assertEqual(argv[:4], ['/usr/sbin/runuser', '-u', 'runner', '--'])
            self.assertEqual(argv[-1], '--version')

    def test_missing_or_failed_runuser_never_qualifies(self):
        for failure in (FileNotFoundError('missing executable'), PermissionError('not executable')):
            def missing(argv, failure=failure, **kwargs):
                if argv[0] == '/usr/sbin/runuser':
                    raise failure
                return self.command(argv, **kwargs)
            with self.subTest(failure=type(failure).__name__), self.assertRaises(type(failure)):
                n.verify(self.root, missing, self.loads.append)
        def failed(argv, **kwargs):
            if argv[0] == '/usr/sbin/runuser':
                return subprocess.CompletedProcess(argv, 127, '', 'failed')
            return self.command(argv, **kwargs)
        with self.assertRaisesRegex(ValueError, 'version unresolved'):
            n.verify(self.root, failed, self.loads.append)

    def test_required_missing_and_unexplained_optional_failure_rejected(self):
        cases = [('bin/libcoreclr.so', 'libz.so.1 => not found\n', 0),
                 ('bin/libcoreclrtraceptprovider.so', 'libc.so.6 => not found\n', 0),
                 ('bin/libcoreclrtraceptprovider.so', 'liblttng-ust.so.0 => not found\nlibc.so.6 => not found\n', 0),
                 ('bin/libcoreclrtraceptprovider.so', "version `GLIBC_9.0' not found\n", 0),
                 ('bin/libcoreclrtraceptprovider.so', 'undefined symbol: missing', 0),
                 ('bin/libcoreclrtraceptprovider.so', 'liblttng-ust.so.0 => not found', 1)]
        for member, text, code in cases:
            self.bad_member, self.bad_text, self.bad_code = member, text, code
            with self.assertRaises(ValueError):
                n.verify(self.root, self.command, self.loads.append)

    def test_payload_contract_dynamic_library_and_version_errors(self):
        (self.root / 'bin/Runner.Listener').write_bytes(b'drift')
        with self.assertRaisesRegex(ValueError, 'payload changed'):
            n.verify(self.root, self.command, self.loads.append)
        (self.root / 'bin/Runner.Listener').write_bytes(b'bin/Runner.Listener')
        def missing(_):
            raise OSError('fixture missing library')
        with self.assertRaisesRegex(ValueError, 'dynamic system library'):
            n.verify(self.root, self.command, missing)
        self.inputs['runtime_version'] = 'unknown'
        with self.assertRaisesRegex(ValueError, 'contract changed'):
            n.verify(self.root, self.command, self.loads.append)
        self.inputs['runtime_version'] = '8.0.28'
        self.inputs['ubuntu_unselected_members'] = []
        with self.assertRaisesRegex(ValueError, 'selector contract'):
            n.verify(self.root, self.command, self.loads.append)
        self.inputs['ubuntu_unselected_members'] = ['externals/node20_alpine/bin/node', 'externals/node24_alpine/bin/node']
        def wrong_version(argv, **kwargs):
            if argv[0] == '/usr/sbin/runuser':
                return subprocess.CompletedProcess(argv, 0, 'wrong', '')
            return self.command(argv, **kwargs)
        with self.assertRaisesRegex(ValueError, 'version unresolved'):
            n.verify(self.root, wrong_version, self.loads.append)


class PolicyBank(unittest.TestCase):
    """Run real Bash consumer over private workflows; simulate only root stat/git."""
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.workflows = self.root / 'workflows'
        self.workflows.mkdir()
        for name in ('ci', 'regression', 'security', 'static-analysis'):
            (self.workflows / (name + '.yml')).write_bytes((CHECKOUT / '.github/workflows' / (name + '.yml')).read_bytes())
        self.slot = self.root / 'slot'
        self.slot.write_text(m.RUNNER_NAME + '\n/opt/actions-runner\n')
        self.slot.chmod(0o444)
        commands = self.root / 'bin'; commands.mkdir()
        git = commands / 'git'
        git.write_text('#!/usr/bin/python3\nimport sys\nassert sys.argv[1:]==["-C",str(__import__("pathlib").Path.cwd()),"-c","safe.directory="+str(__import__("pathlib").Path.cwd()),"rev-parse","HEAD"]\nprint("' + SHA + '")\n')
        stat = commands / 'stat'
        stat.write_text('#!/usr/bin/python3\nimport os,sys\np=sys.argv[-1];s=os.stat(p)\nprint("0:0:444" if p==' + repr(str(self.slot)) + ' and s.st_mode&0o777==0o444 else str(s.st_uid)+":"+str(s.st_gid)+":"+oct(s.st_mode&0o777)[2:])\n')
        git.chmod(0o700); stat.chmod(0o700)
        self.env = {'PATH': str(commands) + ':' + os.defpath, 'RUNNER_ENVIRONMENT': 'self-hosted',
                    'RUNNER_NAME': m.RUNNER_NAME, 'GITHUB_EVENT_NAME': 'pull_request',
                    'PR_RUNNER_SLOT_FILE': str(self.slot), 'PR_RUNNER_WORKFLOW_DIR': str(self.workflows),
                    'PR_RUNNER_EXPECTED_SHA': SHA}

    def guard(self):
        return subprocess.run(['bash', str(CHECKOUT / '.github/scripts/assert-pr-runner-isolation.sh')],
                              capture_output=True, text=True, check=False, env=self.env, cwd=CHECKOUT)

    def test_all_fourteen_owned_selectors_and_thirteen_job_count(self):
        selectors = sum(path.read_text().count('runs-on: ["self-hosted-run-${{ github.run_id }}-${{ github.run_attempt }}", "vimbadmin-owned-local-v1-run-${{ github.run_id }}-${{ github.run_attempt }}", "docker-run-${{ github.run_id }}-${{ github.run_attempt }}", "jit-env-input-run-${{ github.run_id }}-${{ github.run_attempt }}", "vimbadmin-pristine-jit-v1-run-${{ github.run_id }}-${{ github.run_attempt }}"]') for path in self.workflows.iterdir())
        self.assertEqual(selectors, 14)
        result = self.guard()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('All pull-request jobs use authorized', result.stdout)
        regression = self.workflows / 'regression.yml'
        with regression.open('a') as out:
            out.write('''
  count_control:
    runs-on: ["self-hosted-run-${{ github.run_id }}-${{ github.run_attempt }}", "vimbadmin-owned-local-v1-run-${{ github.run_id }}-${{ github.run_attempt }}", "docker-run-${{ github.run_id }}-${{ github.run_attempt }}", "jit-env-input-run-${{ github.run_id }}-${{ github.run_attempt }}", "vimbadmin-pristine-jit-v1-run-${{ github.run_id }}-${{ github.run_attempt }}"]
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1
        with:
          ref: ${{ github.event.pull_request.head.sha || github.sha }}
          persist-credentials: false
      - run: bash .github/scripts/assert-pr-runner-isolation.sh
        env:
          PR_RUNNER_EXPECTED_SHA: >-
            ${{ github.event.pull_request.head.sha || github.sha }}
''')
        result = self.guard()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('PR-triggered job count is 14; expected 13.', result.stderr)
        self.assertNotIn('Every PR-triggered job', result.stderr)

    def test_legacy_selector_rejected_and_existing_assets_enrolls_tests(self):
        path = self.workflows / 'ci.yml'
        path.write_text(path.read_text().replace('vimbadmin-owned-local-v1-run-', 'builder02-run-', 1))
        result = self.guard()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Every PR-triggered job', result.stderr)
        workflow = (CHECKOUT / '.github/workflows/regression.yml').read_text()
        assets = workflow.split('  assets:\n', 1)[1].split('\n  static:', 1)[0]
        self.assertIn("python3 -m unittest discover -s ci -p 'test_local_repo_jit*.py' -v", assets)
        self.assertIn('python3 ci/local_repo_jit_capacity_negative_controls.py --receipt-dir ', assets)
        self.assertIn('python3 ci/local_repo_jit_negative_controls.py --receipt-dir "$(mktemp -d "${RUNNER_TEMP:?}/local-repo-jit-controls.XXXXXX")"', assets,
                      'assets must execute maintained JIT negative controls with fresh external receipts')
        self.assertIn('command -v python3', assets)
        self.assertNotIn('    container:', assets)

    def test_own_metadata_cross_name_mode_and_shape_errors(self):
        for text in ['builder02-docker-01\n/opt/actions-runner\n', m.RUNNER_NAME + '\n/opt/actions-runner',
                     m.RUNNER_NAME + '\n/opt/actions-runner\nextra\n']:
            self.slot.chmod(0o644); self.slot.write_text(text); self.slot.chmod(0o444)
            result = self.guard()
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('Published slot identity does not match', result.stderr)
        self.slot.chmod(0o644)
        self.assertIn('invalid published slot metadata', self.guard().stderr)


class FixtureCleanupControls(unittest.TestCase):
    def test_failed_setup_releases_temporary_directory(self):
        original = tempfile.TemporaryDirectory
        created = []

        def allocate(*args, **kwargs):
            temp = original(*args, **kwargs)
            created.append(temp)
            return temp

        try:
            with patch.object(tempfile, 'TemporaryDirectory', side_effect=allocate), \
                 patch.object(m, 'DockerGuest', side_effect=RuntimeError('fixture init failure')):
                result = unittest.TestResult()
                unittest.TestSuite([Fixture('test_serial_positive_cancel_probe_fresh_reset_reap')]).run(result)
            self.assertEqual(len(result.errors), 1)
            self.assertEqual(len(created), 1)
            self.assertFalse(Path(created[0].name).exists(), 'failed setup leaked its temporary directory')
        finally:
            for temp in created:
                temp.cleanup()

    def test_second_patch_failure_restores_first_and_cleans_temp(self):
        original_temp = tempfile.TemporaryDirectory
        original_patch = patch.object
        original_run = subprocess.run
        created, stopped = [], []
        testcase = Fixture('test_serial_positive_cancel_probe_fresh_reset_reap')

        def allocate(*args, **kwargs):
            temp = original_temp(*args, **kwargs)
            created.append(temp)
            return temp

        class FailedPatch:
            def start(self):
                raise RuntimeError('second patch start failure')

            def stop(self):
                stopped.append(True)

        def patch_factory(target, attribute, *args, **kwargs):
            if target is m.v2.urllib.request and attribute == 'urlopen':
                return FailedPatch()
            return original_patch(target, attribute, *args, **kwargs)

        try:
            with original_patch(tempfile, 'TemporaryDirectory', side_effect=allocate), \
                 original_patch(patch, 'object', side_effect=patch_factory):
                result = unittest.TestResult()
                unittest.TestSuite([testcase]).run(result)
            self.assertEqual(len(result.errors), 1)
            self.assertEqual(stopped, [True], 'failed patch cleanup was not registered first')
            self.assertIs(subprocess.run, original_run, 'first guard was not restored')
            self.assertEqual(len(created), 1)
            self.assertFalse(Path(created[0].name).exists(), 'failed setup leaked its temporary directory')
        finally:
            testcase.doCleanups()
            if hasattr(testcase, 'no_subprocess'):
                testcase.no_subprocess.stop()
            for temp in created:
                temp.cleanup()

    def test_first_patch_failure_runs_registered_cleanup(self):
        original_patch = patch.object
        stopped = []

        class FailedPatch:
            def start(self):
                raise RuntimeError('first patch start failure')

            def stop(self):
                stopped.append(True)

        def patch_factory(target, attribute, *args, **kwargs):
            if target is subprocess and attribute == 'run':
                return FailedPatch()
            return original_patch(target, attribute, *args, **kwargs)

        testcase = Fixture('test_serial_positive_cancel_probe_fresh_reset_reap')
        with original_patch(patch, 'object', side_effect=patch_factory):
            result = unittest.TestResult()
            unittest.TestSuite([testcase]).run(result)
        self.assertEqual(len(result.errors), 1)
        self.assertEqual(stopped, [True], 'first patch cleanup was not registered before start')
        self.assertFalse(Path(testcase.tmp.name).exists())

    def test_successful_setup_keeps_guards_active_until_cleanup(self):
        original_run, original_urlopen = subprocess.run, m.v2.urllib.request.urlopen
        fixture = Fixture('test_serial_positive_cancel_probe_fresh_reset_reap')
        try:
            fixture.setUp()
            self.assertIsNot(subprocess.run, original_run)
            self.assertIsNot(m.v2.urllib.request.urlopen, original_urlopen)
            with self.assertRaisesRegex(AssertionError, 'live subprocess forbidden'):
                subprocess.run([], check=False)
            with self.assertRaisesRegex(AssertionError, 'network forbidden'):
                m.v2.urllib.request.urlopen('fixture')
            directory = Path(fixture.tmp.name)
        finally:
            fixture.doCleanups()
        self.assertIs(subprocess.run, original_run)
        self.assertIs(m.v2.urllib.request.urlopen, original_urlopen)
        self.assertFalse(directory.exists())


if __name__ == '__main__':
    unittest.main()
