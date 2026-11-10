"""Finite template and fake-transport contracts. No backend or guest is started."""
from __future__ import annotations

import ast
import base64
import copy
import dataclasses
import hashlib
import io
import json
import os
import shlex
import shutil
import subprocess
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1] / '.github/scripts/local-repo-jit'
sys.path.insert(0, str(ROOT))
import public_templates as templates
import runner_setup
import vm_backend
from test_local_repo_jit_public_recipe import fixture


def descriptor():
    data = fixture()
    for item in data['artifacts']:
        item['url'] = item['url'].replace('qemu-system-x86_64_', 'qemu-system-x86_')
    row = next(row for row in data['artifacts'] if row['kind'] == 'runner-archive')
    row.update(version='2.336.0', sha256=runner_setup.ARCHIVE_SHA,
               url=runner_setup.URL, bytes=226035903)
    return templates.public_recipe.parse_descriptor(json.dumps(data).encode())


def module(name, raw):
    """Load trusted generated test source, with normal builtins; not a sandbox."""
    value = types.ModuleType('generated_' + name)
    # Synthetic filenames keep coverage attribution distinct for generated code.
    # Callers use hash-verified templates with descriptor() or a literal fixture.
    # The generated __main__ guards stay inactive in this fresh named module.
    # nosemgrep: semgrep.locked.python.lang.security.audit.exec-detected.exec-detected
    exec(compile(raw, str(ROOT / 'public_templates' / (name + '.generated.py')), 'exec'), value.__dict__)  # noqa: S102 - Execute only the pinned generated source under test.
    return value


class GeneratedModules(unittest.TestCase):
    def test_full_source_uses_fresh_non_main_namespace(self):
        raw = b"""VALUE = 'initial'
def read():
    return VALUE
if __name__ == '__main__':
    raise AssertionError('fixture entrypoint executed')
"""
        first = module('fixture', raw)
        second = module('fixture', raw)
        self.assertEqual(first.__name__, 'generated_fixture')
        self.assertEqual(first.read(), 'initial')
        self.assertIs(first.read.__globals__, first.__dict__)
        self.assertIsNot(first.__dict__, second.__dict__)
        first.VALUE = 'changed'
        self.assertEqual(first.read(), 'changed')
        self.assertEqual(second.read(), 'initial')

    def test_verified_generated_sources_load_without_starting_entrypoints(self):
        result = templates._render(descriptor(), templates.sources(ROOT))
        for name, path in (('qga', 'qga.py'), ('outer', 'outer.py'), ('provision', 'iso/provision.py')):
            with self.subTest(name=name), \
                    mock.patch.object(subprocess, 'run', side_effect=AssertionError('unexpected command')) as run, \
                    mock.patch.object(os, 'umask', side_effect=AssertionError('unexpected entrypoint')) as umask:
                first = module(name, result.files[path])
                second = module(name, result.files[path])
                self.assertEqual(first.__name__, 'generated_' + name)
                self.assertIs(first.main.__globals__, first.__dict__)
                self.assertIsNot(first.main, second.main)
                run.assert_not_called()
                umask.assert_not_called()


class Connection:
    """Finite byte transport only; unsupported operations fail immediately."""
    def __init__(self, raw=b''):
        self.reader = io.BytesIO(raw)
        self.sent, self.timeouts, self.addresses = [], [], []
        self.closed = False

    def settimeout(self, timeout):
        self.timeouts.append(timeout)

    def sendall(self, raw):
        self.sent.append(raw)

    def connect(self, address):
        self.addresses.append(address)

    def makefile(self, mode):
        if mode != 'rb':
            raise AssertionError('unsupported fake file mode')
        return self.reader

    def close(self):
        self.closed = True

    def __enter__(self):
        return self

    def __exit__(self, *_):
        self.close()


class Replies:
    def __init__(self, replies, deadline=10):
        self.replies, self.calls = list(replies), []
        self.deadline = deadline

    def call(self, name, arguments=None):
        if name not in ('guest-exec', 'guest-exec-status') or not self.replies:
            raise AssertionError('unsupported or exhausted fake agent')
        self.calls.append((name, arguments))
        return self.replies.pop(0)


class Process:
    def __init__(self, waits=(0,)):
        self.waits, self.events, self.code = list(waits), [], None

    def poll(self):
        return self.code

    def wait(self, timeout):
        self.events.append(('wait', timeout))
        if not self.waits:
            raise AssertionError('exhausted fake process')
        result = self.waits.pop(0)
        if result == 'timeout':
            raise subprocess.TimeoutExpired('fake-qemu', timeout)
        self.code = result
        return result

    def terminate(self):
        self.events.append(('terminate',))

    def kill(self):
        self.events.append(('kill',))


def qmp_wire(extra=b''):
    return Connection(b'{"QMP":{}}\n{"return":{},"id":"qmp_capabilities"}\n' + extra)


class Templates(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.source = templates.sources(ROOT)
        cls.descriptor = descriptor()
        cls.result = templates._render(cls.descriptor, cls.source)

    def test_all_eight_deterministic_under_shuffled_order_and_source_root(self):
        data = json.loads(self.descriptor.canonical)
        data['artifacts'].reverse()
        for row in data['artifacts']:
            row['dependencies'].reverse()
        shuffled = templates.public_recipe.parse_descriptor(json.dumps(data).encode())
        again = templates._render(shuffled, dict(reversed(list(self.source.items()))))
        self.assertEqual(set(self.result.files), set(templates.FILES))
        self.assertEqual(self.result, again)
        with tempfile.TemporaryDirectory() as temp:
            clone = Path(temp) / 'different-stage'
            clone.mkdir()
            for name in templates.CONTRACT_HASHES:
                shutil.copyfile(ROOT / name, clone / name)
            shutil.copytree(ROOT / 'public_templates', clone / 'public_templates')
            self.assertEqual(self.source, templates.sources(clone))
            self.assertEqual(self.result, templates._render(shuffled, templates.sources(clone)))
        self.assertNotIn(str(ROOT), b''.join(self.result.files.values()).decode())

    def test_generate_orders_source_checks_before_actual_input_reads(self):
        with mock.patch.object(templates.public_recipe, 'verify_retained', return_value={
                'recipe_sha256': self.descriptor.sha256}) as verify:
            result = templates.generate(self.descriptor.canonical, {}, expected_sha256=self.descriptor.sha256)
            self.assertEqual(result, self.result)
            verify.assert_called_once_with(self.descriptor.canonical, {}, expected_sha256=self.descriptor.sha256)
        with mock.patch.object(templates.public_recipe, 'verify_retained', return_value={
                'recipe_sha256': '0' * 64}), self.assertRaisesRegex(templates.TemplateError, 'receipt drift'):
            templates.generate(self.descriptor.canonical, {}, expected_sha256=self.descriptor.sha256)
        with self.assertRaises(templates.public_recipe.RecipeError):
            templates.generate(self.descriptor.canonical, {}, expected_sha256='0' * 64)

    def test_selected_toolchain_copy_groups_exact_leaves_without_changing_other_bytes(self):
        profile = templates.literal(self.source['public_recipe_selection.py'], 'PUBLIC')
        historical = templates.literal(self.source['public_recipe_selection.py'], 'HISTORICAL')
        tool = next(row for row in self.descriptor.artifacts if row.kind == 'toolchain')
        other = tuple(row for row in self.descriptor.artifacts if row.kind != 'toolchain')
        for count in (1, 129):
            tools = tuple([dataclasses.replace(tool, id='tool-test-' + str(index),
                          url='https://example.org/test-' + str(index) + '_1.0_amd64.deb')
                           for index in range(count)])
            descriptor = types.SimpleNamespace(artifacts=other + tools, sha256=self.descriptor.sha256)
            legacy = templates._render(descriptor, self.source)
            self.assertEqual(legacy.files, templates._render(descriptor, self.source, profile=historical).files)
            selected = templates._render(descriptor, self.source, profile=profile)
            docker = selected.files['Dockerfile'].decode()
            copies = [shlex.split(line) for line in docker.splitlines() if line.startswith('COPY toolchain/')]
            expected = ['toolchain/' + row.id + '.deb' for row in tools]
            self.assertEqual(copies, [['COPY', *expected, '/opt/public-toolchain/']])
            self.assertEqual(selected.plan['docker_copy_inputs'], legacy.plan['docker_copy_inputs'])
            for name in legacy.files:
                if name not in ('Dockerfile', 'iso/provision.py'):
                    self.assertEqual(selected.files[name], legacy.files[name])
            without_copies = lambda text: '\n'.join(line for line in text.splitlines()
                                                    if not line.startswith('COPY toolchain/'))
            legacy_install = legacy.files['Dockerfile'].decode().replace('apt-get -y --no-download', 'apt-get -y')
            self.assertEqual(without_copies(docker), without_copies(legacy_install))
            self.assertEqual(selected, templates._render(descriptor, self.source, profile=profile))

    def test_selected_offline_install_allows_authenticated_local_file_acquisition(self):
        profile = templates.literal(self.source['public_recipe_selection.py'], 'PUBLIC')
        docker = templates._render(self.descriptor, self.source, profile=profile).files['Dockerfile'].decode()
        commands = [json.loads(line[4:])[-1] for line in docker.splitlines()
                    if line.startswith('RUN ') and 'apt-get' in line]
        self.assertEqual(len(commands), 1)
        command = commands[0]
        self.assertNotIn('--no-download', command)
        self.assertIn('-o Dir::Etc::sourcelist=/dev/null -o Dir::Etc::sourceparts=- install /opt/public-toolchain/', command)
        self.assertLess(command.index('sha256sum -c'), command.index('apt-get -y'))
        self.assertLess(command.index('apt-get -y'), command.index('dpkg-query -W'))

    def test_missing_and_drifted_sources_refuse_before_verifier(self):
        with tempfile.TemporaryDirectory() as temp:
            clone = Path(temp)
            for name in templates.CONTRACT_HASHES:
                shutil.copyfile(ROOT / name, clone / name)
            shutil.copytree(ROOT / 'public_templates', clone / 'public_templates')
            mutations = [(clone / 'public_templates/qga.py.in', None),
                         (clone / 'public_templates/qga.py.in', b'drift'),
                         (clone / 'vm_backend.py', b'drift')]
            for path, raw in mutations:
                original = path.read_bytes()
                try:
                    if raw is None:
                        path.unlink()
                    else:
                        path.write_bytes(raw)
                    with mock.patch.object(templates.public_recipe, 'verify_retained') as verify:
                        with self.assertRaises(templates.TemplateError):
                            templates.generate(self.descriptor.canonical, {}, expected_sha256=self.descriptor.sha256,
                                               source_root=clone)
                        verify.assert_not_called()
                finally:
                    path.write_bytes(original)
            (clone / 'public_templates/extra.in').write_text('unexpected')
            with self.assertRaisesRegex(templates.TemplateError, 'exact finite'):
                templates.sources(clone)

    def test_source_types_aliases_size_and_inaccessible(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            source = root / 'source'
            source.write_bytes(b'actual')
            self.assertEqual(templates.read_source(source, templates.digest(b'actual')), 'actual')
            with self.assertRaisesRegex(templates.TemplateError, 'hash drift'):
                templates.read_source(source, '0' * 64)
            alias = root / 'alias'
            alias.symlink_to(source)
            for path in (alias, root / 'missing', root):
                with self.subTest(path=path), self.assertRaises(templates.TemplateError):
                    templates.read_source(path, '0' * 64)
            source.write_bytes(b'')
            with self.assertRaisesRegex(templates.TemplateError, 'type/size'):
                templates.read_source(source, '0' * 64)
            with self.assertRaises(templates.TemplateError):
                templates.sources(root / 'absent')

    def test_syntax_copy_and_bootstrap_pins(self):
        result = self.result
        for name, raw in result.files.items():
            if name.endswith('.py'):
                ast.parse(raw, filename=name)
        copies = result.plan['docker_copy_inputs']
        self.assertEqual(set(copies), {'base.qcow2', 'base.sha256', 'outer.py', 'qga.py', 'seed.iso'} |
                         {'toolchain/' + row.id + '.deb' for row in self.descriptor.artifacts if row.kind == 'toolchain'})
        docker = result.files['Dockerfile'].decode()
        self.assertIn('FROM docker.io/library/debian@sha256:', docker)
        self.assertNotIn('\nENV ', docker)
        self.assertNotIn('\nVOLUME ', docker)
        self.assertIn('--no-download', docker)
        for item in self.descriptor.artifacts:
            if item.kind == 'toolchain':
                self.assertIn(item.sha256, docker)
                self.assertIn(item.version, docker)
        config = json.loads(result.files['iso/user-data'].split(b'\n', 1)[1])
        self.assertEqual(config['users'], [])
        self.assertFalse(config['allow_public_ssh_keys'])
        self.assertFalse(config['ssh_pwauth'])
        self.assertEqual(config['ssh_genkeytypes'], [])
        self.assertEqual(config['growpart'], {'devices': ['/'], 'ignore_growroot_disabled': False, 'mode': 'auto'})
        self.assertTrue(config['resize_rootfs'])
        self.assertIn(self.descriptor.sha256, result.files['iso/meta-data'].decode())
        self.assertFalse(result.plan['runtime_qualified'])
        self.assertFalse(result.plan['execution_allowed'])
        self.assertEqual(result.plan['dependencies']['iso/alpine.tar']['sha256'], vm_backend.ALPINE_SHA)
        self.assertFalse(result.plan['dependencies']['iso/alpine.tar']['public_origin_accepted'])

    def test_actual_capacity_sources_preimages_and_installer_order(self):
        texts = {name: raw.decode() for name, raw in self.result.files.items()}
        texts['iso/install-runner.py'] = runner_setup.installer_for()
        generated = runner_setup.capacity_sources(Path('/never-read'), texts=texts)
        for name, source in generated.items():
            if name.endswith('.py'):
                ast.parse(source, filename=name)
        self.assertIn(runner_setup.WORK, generated['outer.py'])
        self.assertIn(runner_setup.WORK, generated['qga.py'])
        transformed = ast.parse(generated['outer.py'])
        overlay = next(ast.literal_eval(node.value) for node in transformed.body if isinstance(node, ast.Assign)
                       and any(isinstance(t, ast.Name) and t.id == '_OVERLAY_CREATE' for t in node.targets))
        calls = []
        fake = types.SimpleNamespace(fresh_overlay=lambda *args: calls.append(args))
        with mock.patch.dict(sys.modules, {'owned_overlay': fake}):
            exec(overlay, {'root': Path('/opt/vm'), 'expected': 'public-base', 'work': Path('/private')})  # noqa: S102 - Exercise the maintained executable source replacement.
        self.assertEqual(calls, [(Path('/opt/vm/base.qcow2'), 'public-base', Path('/private'))])
        self.assertIn('ringbuf,id=ci-serial,size=65536', generated['outer.py'])
        provision = generated['iso/provision.py']
        self.assertLess(provision.index('guest_capacity.root_ready()'), provision.index('main()\n'))
        marker = "pathlib.Path('/var/lib/local-ci/provisioned').touch()"
        self.assertEqual(texts['iso/provision.py'].count(marker), 1)
        self.assertLess(texts['iso/provision.py'].index("command(['python3', '/mnt/install-runner.py'])"),
                        texts['iso/provision.py'].index(marker))
        installer = generated['iso/install-runner.py']
        self.assertLess(installer.index('guest_capacity.admit('), installer.index('target.mkdir'))
        self.assertLess(installer.index('guest_capacity.verify_extracted'), installer.index('native_policy.verify'))
        changed = dict(texts, **{'outer.py': texts['outer.py'].replace("'-serial', 'null'", "'invalid'")})
        with self.assertRaisesRegex(ValueError, 'preimage changed'):
            runner_setup.capacity_sources(Path('/never-read'), texts=changed)

    def test_render_refusals_and_copy_boundaries(self):
        with self.assertRaises(templates.TemplateError):
            templates.literal('x = 1', 'missing')
        for source, values in [('@@A@@', {}), ('@@A@@', {'A': '@@'})]:
            with self.assertRaises(templates.TemplateError):
                templates.substitute(source, values)
        for text in ('ADD a /x', 'COPY a', 'COPY absent /x', 'COPY a a /x', 'COPY a /x'):
            with self.assertRaises(templates.TemplateError):
                templates.copy_inputs(text, ['a', 'b'])
        self.assertEqual(templates.copy_inputs('#comment\nRUN true\nCOPY a b /x', ['a', 'b']), ['a', 'b'])
        data = json.loads(self.descriptor.canonical)
        next(row for row in data['artifacts'] if row['kind'] == 'runner-archive')['sha256'] = '0' * 64
        changed = templates.public_recipe.parse_descriptor(json.dumps(data).encode())
        with self.assertRaisesRegex(templates.TemplateError, 'archive identity'):
            templates._render(changed, self.source)
        item = next(row for row in self.descriptor.artifacts if row.kind == 'toolchain')
        with self.assertRaisesRegex(templates.TemplateError, 'filename'):
            templates.package(types.SimpleNamespace(url='https://example.org/invalid.deb'))
        broken = types.SimpleNamespace(artifacts=tuple(self.descriptor.artifacts) + (item,), sha256=self.descriptor.sha256)
        with self.assertRaisesRegex(templates.TemplateError, 'duplicate package'):
            templates._render(broken, self.source)


    def test_source_change_during_read_and_guarded_entrypoints(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / 'source'
            path.write_bytes(b'public')
            info = path.stat()
            later = types.SimpleNamespace(st_dev=info.st_dev, st_ino=info.st_ino, st_size=info.st_size,
                                          st_mtime_ns=info.st_mtime_ns + 1, st_ctime_ns=info.st_ctime_ns)
            with mock.patch.object(templates.os, 'fstat', side_effect=[info, later]), \
                    self.assertRaisesRegex(templates.TemplateError, 'during read'):
                templates.read_source(path, templates.digest(b'public'))
        for file in ('outer.py', 'qga.py', 'iso/provision.py'):
            name = Path(file).stem
            tree = ast.parse(self.result.files[file])
            guard = ast.Module(body=[tree.body[-1]], type_ignores=[])
            called = mock.Mock(return_value=0)
            namespace = {'__name__': '__main__', 'main': called}
            code = compile(guard, str(ROOT / 'public_templates' / (name + '.generated.py')), 'exec')
            try:
                exec(code, namespace)  # noqa: S102 - Only the real entry guard, with a fake main.
            except SystemExit as error:
                self.assertEqual(error.code, 0)
            called.assert_called_once_with()


class GuestProtocol(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.result = templates._render(descriptor(), templates.sources(ROOT))

    def setUp(self):
        self.qga = module('qga', self.result.files['qga.py'])

    def test_request_positive_boundaries_and_malformed_no_private_error(self):
        qga = self.qga
        valid = {'script': 'true', 'stdin': 'private-value\n', 'capture': True}
        self.assertEqual(qga.request(json.dumps(valid).encode()), valid)
        for field, limit in [('script', qga.MAX_SCRIPT), ('stdin', qga.MAX_STDIN)]:
            data = dict(valid, **{field: 'x' * limit})
            self.assertEqual(qga.request(json.dumps(data).encode()), data)
            data[field] += 'x'
            with self.assertRaises(qga.Refused):
                qga.request(json.dumps(data).encode())
        for data in (b'', b'[]', b'{', b'{"script":"x","script":"y"}', b'\xff',
                     json.dumps(dict(valid, capture=1)).encode(), json.dumps(dict(valid, stdin=0)).encode(),
                     json.dumps(dict(valid, script='')).encode(), json.dumps(dict(valid, script='\0')).encode(),
                     b' ' * (qga.MAX_REQUEST + 1), json.dumps(dict(valid, script='\ud800')).encode()):
            with self.subTest(raw=data[:50]), self.assertRaises(qga.Refused) as error:
                qga.request(data)
            self.assertNotIn('private-value', str(error.exception))

    def test_actual_vm_backend_json_stdin_capture_and_exit_contract(self):
        owner = object.__new__(vm_backend.Owned)
        agents = []
        status = {'exited': True, 'exitcode': 0, 'out-data': base64.b64encode(b'first\nsecond').decode(),
                  'err-data': base64.b64encode(b'warning\n').decode()}
        def act(receipt, before, after, data):
            self.assertEqual(receipt, {'id': 'finite-fake'})
            self.assertEqual(before, ['exec', '-i'])
            self.assertEqual(after, ['/usr/bin/python3', '/opt/vm/qga.py'])
            value = self.qga.request(data.encode())
            agent = Replies([{'pid': 17}, {'exited': False}, copy.deepcopy(status)])
            agents.append(agent)
            result = self.qga.execute(value, agent, clock=lambda: 0, sleep=lambda _: None)
            return types.SimpleNamespace(stdout=json.dumps(result))
        owner.act = act
        receipt = {'id': 'finite-fake'}
        self.assertEqual(owner.guest(receipt, 'read a; printf %s "$a"', 'private\n'), 'first\nsecond')
        arguments = agents[-1].calls[0][1]
        self.assertEqual(arguments, {'path': '/bin/sh', 'arg': ['-c', 'read a; printf %s "$a"'],
                                     'input-data': 'cHJpdmF0ZQo=', 'capture-output': True})
        self.assertEqual(agents[-1].calls[1:], [('guest-exec-status', {'pid': 17})] * 2)
        self.assertEqual(owner.guest(receipt, 'true', 'secret-private\n', capture=False), '')
        self.assertEqual(owner.guest_response(receipt, 'true', 'secret-private\n', capture=False),
                         {'exitcode': 0, 'signal': None, 'out-data': '', 'err-data': ''})
        status['exitcode'] = 7
        result = owner.guest_response(receipt, 'exit 7')
        self.assertEqual(result, {'exitcode': 7, 'signal': None, 'out-data': 'first\nsecond', 'err-data': 'warning\n'})
        with self.assertRaises(vm_backend.Refused):
            owner.guest(receipt, 'exit 7')
        del status['exitcode']
        status['signal'] = 15
        self.assertEqual(owner.guest_response(receipt, 'kill -TERM $$')['signal'], 15)
        with self.assertRaises(vm_backend.Refused):
            owner.guest(receipt, 'kill -TERM $$')

    def test_terminal_status_refusals_no_retry_and_output_limits(self):
        valid = {'script': 'true', 'stdin': '', 'capture': True}
        for first, final in [({'pid': True}, None), ({'pid': 0}, None), ({'pid': 1, 'extra': 1}, None),
                             ({'pid': 1}, {'exited': 'true'}), ({'pid': 1}, {'exited': False, 'out-data': 'x'}),
                             ({'pid': 1}, {'exited': True}),
                             ({'pid': 1}, {'exited': True, 'exitcode': 0, 'signal': 1}),
                             ({'pid': 1}, {'exited': True, 'signal': 0}),
                             ({'pid': 1}, {'exited': True, 'exitcode': 256}),
                             ({'pid': 1}, {'exited': True, 'exitcode': 0, 'extra': 1}),
                             ({'pid': 1}, {'exited': True, 'exitcode': 0, 'out-truncated': True})]:
            replies = [first] if final is None else [first, final]
            agent = Replies(replies)
            with self.subTest(replies=replies), self.assertRaises(self.qga.Refused):
                self.qga.execute(valid, agent, clock=lambda: 0, sleep=lambda _: None)
            self.assertEqual(sum(name == 'guest-exec' for name, _ in agent.calls), 1)
        agent = Replies([{'pid': 1}], deadline=0)
        with self.assertRaisesRegex(self.qga.Refused, 'deadline'):
            self.qga.execute(valid, agent, clock=lambda: 0)
        raw = b'z' * self.qga.MAX_OUTPUT
        self.assertEqual(self.qga.output({'out-data': base64.b64encode(raw).decode()}, 'out-data', True), raw.decode())
        for status in ({'out-data': '!'}, {'out-data': 4},
                       {'out-data': base64.b64encode(raw + b'z').decode()}, {'out-truncated': True}):
            with self.assertRaises(self.qga.Refused):
                self.qga.output(status, 'out-data', True)
        self.assertEqual(self.qga.output({'out-data': 'private-invalid!!'}, 'out-data', False), '')
        self.assertEqual(self.qga.output({'out-data': '/w=='}, 'out-data', True), '\ufffd')

    def test_wire_complete_delimited_sync_and_sanitized_errors(self):
        qga = self.qga
        connection = Connection(b'{"return":"stale"}\n\xff{"return":73}\n{"return":{"pid":7}}\n')
        agent = qga.Agent(connection, connection.reader, 10, clock=lambda: 1)
        self.assertEqual(agent.call('guest-sync-delimited', {'id': 73}, sync=True), 73)
        self.assertEqual(agent.call('guest-exec', {'input-data': 'cHJpdmF0ZQ=='}), {'pid': 7})
        self.assertTrue(connection.sent[0].startswith(b'\xff'))
        self.assertEqual(json.loads(connection.sent[1])['arguments']['input-data'], 'cHJpdmF0ZQ==')
        for raw in (b'', b'{}', b'{"error":{"desc":"private-value"}}\n', b'{}\n',
                    b'{"return":0,"return":1}\n', b'\xff\n'):
            connection = Connection(raw)
            with self.subTest(raw=raw), self.assertRaises(qga.Refused) as error:
                qga.Agent(connection, connection.reader, 10, clock=lambda: 1).call('guest-ping')
            self.assertNotIn('private-value', str(error.exception))
        with self.assertRaises(qga.Refused):
            qga.Agent(Connection(), io.BytesIO(), 0, clock=lambda: 1).call('guest-ping')
        with self.assertRaises(qga.Refused):
            c = Connection(b'{}\n' * 32)
            qga.Agent(c, c.reader, 10, clock=lambda: 1).call('guest-sync-delimited', sync=True)

    def test_private_socket_and_lock_refusals_and_main_sanitizes(self):
        with tempfile.TemporaryDirectory() as temp:
            work = Path(temp) / 'private'
            work.mkdir(mode=0o700)
            with self.assertRaises(FileNotFoundError):
                self.qga.serve(b'{"script":"true","stdin":"","capture":false}', work)
            (work / 'qga.sock').write_text('not a socket')
            with self.assertRaises(self.qga.Refused):
                self.qga.serve(b'{"script":"true","stdin":"","capture":false}', work)
            work.chmod(0o755)
            with self.assertRaises(self.qga.Refused):
                self.qga.serve(b'{"script":"true","stdin":"","capture":false}', work)
        stdin = types.SimpleNamespace(buffer=io.BytesIO(b'private-value'))
        stdout, stderr = io.StringIO(), io.StringIO()
        with mock.patch.object(self.qga, 'serve', side_effect=ValueError('private-value')), \
                mock.patch.object(sys, 'stdin', stdin), mock.patch.object(sys, 'stderr', stderr), \
                mock.patch.object(sys, 'stdout', stdout):
            self.assertEqual(self.qga.main(), 1)
        self.assertEqual(stdout.getvalue(), '')
        self.assertNotIn('private-value', stderr.getvalue())


    def test_serve_sync_private_lock_main_success_and_lock_failures(self):
        import stat
        qga = self.qga
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp)
            class Endpoint:
                def lstat(self):
                    return types.SimpleNamespace(st_mode=stat.S_IFSOCK | 0o600, st_uid=os.geteuid())
                def __str__(self):
                    return str(directory / 'qga.sock')
            class Work:
                def lstat(self):
                    return directory.lstat()
                def __truediv__(self, name):
                    return Endpoint() if name == 'qga.sock' else directory / name
            value = {'script': 'true', 'stdin': 'private', 'capture': False}
            connection = Connection(b'\xff{"return":73}\n{"return":{"pid":7}}\n'
                                    b'{"return":{"exited":true,"exitcode":0}}\n')
            with mock.patch.object(qga.secrets, 'randbits', return_value=73):
                result = qga.serve(json.dumps(value).encode(), Work(), lambda *_: connection)
            self.assertEqual(result, {'exitcode': 0, 'signal': None, 'out-data': '', 'err-data': ''})
            self.assertEqual((directory / 'qga.lock').stat().st_mode & 0o777, 0o600)
            self.assertTrue(connection.closed)
            self.assertEqual(connection.addresses, [str(directory / 'qga.sock')])
            (directory / 'qga.lock').chmod(0o644)
            with self.assertRaises(qga.Refused):
                qga.serve(json.dumps(value).encode(), Work(), lambda *_: connection)
            (directory / 'qga.lock').chmod(0o600)
            with mock.patch.object(qga.fcntl, 'flock', side_effect=BlockingIOError()), self.assertRaises(BlockingIOError):
                qga.serve(json.dumps(value).encode(), Work(), lambda *_: connection)
            with mock.patch.object(qga.secrets, 'randbits', return_value=72), self.assertRaises(qga.Refused):
                connection = Connection(b'\xff{"return":73}\n')
                qga.serve(json.dumps(value).encode(), Work(), lambda *_: connection)
            stdin = types.SimpleNamespace(buffer=io.BytesIO(json.dumps(value).encode()))
            stdout = io.StringIO()
            with mock.patch.object(qga, 'serve', return_value=result), mock.patch.object(sys, 'stdin', stdin), \
                    mock.patch.object(sys, 'stdout', stdout):
                self.assertEqual(qga.main(), 0)
            self.assertEqual(json.loads(stdout.getvalue()), result)


class Lifecycle(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.result = templates._render(descriptor(), templates.sources(ROOT))

    def setUp(self):
        self.outer = module('outer', self.result.files['outer.py'])

    def test_qemu_argv_ringbuf_private_disk_only_no_host_import(self):
        args = self.outer.argv('provision')
        self.assertIn('ringbuf,id=ci-serial,size=65536', args)
        self.assertIn('virtserialport,chardev=qga,name=org.qemu.guest_agent.0', args)
        self.assertIn('file=/opt/vm/seed.iso,format=raw,media=cdrom,readonly=on', args)
        self.assertNotIn('file=/opt/vm/seed.iso,format=raw,media=cdrom,readonly=on', self.outer.argv('lifecycle'))
        for part in args:
            self.assertFalse(any(word in part for word in ('virtfs', 'virtiofs', '/dev/sd', '/dev/nvme',
                                                          '/var/run/docker', '/etc/myguard', 'hostfwd', 'token')))
        with self.assertRaises(self.outer.Refused):
            self.outer.argv('unreviewed')

    def test_clean_and_nonzero_lifecycle_reaps_and_uses_path_only_env(self):
        for result in (0, 7):
            process = Process([result])
            launch = mock.Mock(return_value=process)
            connection = qmp_wire()
            if result == 0:
                self.outer.supervise('provision', launch, lambda *_, c=connection: c)
            else:
                with self.assertRaises(self.outer.Refused):
                    self.outer.supervise('provision', launch, lambda *_, c=connection: c)
            self.assertEqual(launch.call_args.kwargs['env'], {'PATH': os.defpath})
            self.assertTrue(launch.call_args.kwargs['close_fds'])
            self.assertEqual(process.events, [('wait', 840)])
            self.assertEqual(json.loads(connection.sent[0])['execute'], 'qmp_capabilities')
            self.assertTrue(connection.closed)

    def test_timeout_powerdown_then_force_reap_no_success(self):
        for waits, events in [(['timeout', 0], [('wait', 840), ('wait', 20)]),
                              (['timeout', 'timeout', 'timeout', -9],
                               [('wait', 840), ('wait', 20), ('terminate',), ('wait', 5), ('kill',), ('wait', 5)])]:
            process = Process(waits)
            connection = qmp_wire(b'{"return":{},"id":"system_powerdown"}\n')
            with self.assertRaisesRegex(self.outer.Refused, 'no export'):
                self.outer.supervise('provision', lambda *_, p=process, **__: p, lambda *_, c=connection: c)
            self.assertEqual(process.events, events)
            self.assertEqual(json.loads(connection.sent[-1])['execute'], 'system_powerdown')
        process = Process([0])
        with mock.patch.object(self.outer, 'qmp', side_effect=ValueError('handshake refused')), \
                self.assertRaises(ValueError):
            self.outer.supervise('provision', lambda *_, p=process, **__: p)
        self.assertEqual(process.events, [('terminate',), ('wait', 5)])

    def test_qmp_error_event_frame_and_readiness_boundaries(self):
        c = Connection(b'{"event":"READY"}\n{"return":{},"id":"qmp_capabilities"}\n')
        self.outer.exchange(c, c.reader, 'qmp_capabilities')
        for raw in (b'', b'{}', b'{"error":{}}\n', b'{"return":{},"id":"wrong"}\n', b'{"return":0}\n'):
            c = Connection(raw)
            with self.assertRaises(self.outer.Refused):
                self.outer.exchange(c, c.reader, 'qmp_capabilities')
        c = Connection(b'{"event":"READY"}\n' * 32)
        with self.assertRaisesRegex(self.outer.Refused, 'unresolved'):
            self.outer.exchange(c, c.reader, 'qmp_capabilities')
        with self.assertRaisesRegex(self.outer.Refused, 'readiness'):
            self.outer.qmp(Process(), 0, clock=lambda: 0)
        failing = Connection()
        failing.connect = mock.Mock(side_effect=OSError())
        ticks = iter([0, 0.1, 2])
        with self.assertRaises(self.outer.Refused):
            self.outer.qmp(Process(), 1, lambda *_: failing, clock=lambda: next(ticks), sleep=lambda _: None)
        self.assertTrue(failing.closed)

    def test_export_exclusive_standalone_geometry_and_fd(self):
        with tempfile.TemporaryDirectory() as temp:
            self.outer.work = Path(temp)
            expected = {'format': 'qcow2', 'virtual-size': self.outer.VIRTUAL_BYTES}
            calls = []
            def run(args, **kwargs):
                calls.append((args, kwargs))
                return types.SimpleNamespace(stdout=json.dumps(expected))
            with mock.patch.object(self.outer, 'command', side_effect=run):
                self.outer.export()
                with self.assertRaises(FileExistsError):
                    self.outer.export()
            self.assertEqual((Path(temp) / 'sealed.qcow2').stat().st_mode & 0o777, 0o600)
            self.assertIn('/proc/self/fd/', calls[0][0][-1])
            self.assertTrue(calls[0][1]['pass_fds'])
            (Path(temp) / 'sealed.qcow2').unlink()
            expected['backing-filename'] = 'unsafe'
            with mock.patch.object(self.outer, 'command', side_effect=run), self.assertRaises(self.outer.Refused):
                self.outer.export()

    def test_main_exclusive_ownership_and_provision_only_export(self):
        with tempfile.TemporaryDirectory() as temp:
            self.outer.root = Path(temp)
            (Path(temp) / 'base.sha256').write_text('new-public-hash\n')
            for phase in ('provision', 'lifecycle'):
                self.outer.work = Path(temp) / phase
                with mock.patch.object(sys, 'argv', ['outer.py', phase]), \
                        mock.patch.object(self.outer.signal, 'alarm') as alarm, \
                        mock.patch.object(self.outer.signal, 'signal'), \
                        mock.patch.object(self.outer, 'create_overlay') as create, \
                        mock.patch.object(self.outer, 'supervise') as supervise, \
                        mock.patch.object(self.outer, 'export') as export:
                    old_umask = os.umask(0o077)
                    try:
                        if phase == 'provision':
                            self.outer.main()
                            export.assert_called_once_with()
                        else:
                            with self.assertRaises(SystemExit) as error:
                                self.outer.main()
                            self.assertEqual(error.exception.code, 0)
                            export.assert_not_called()
                        create.assert_called_once_with('new-public-hash')
                        supervise.assert_called_once_with(phase)
                        self.assertEqual(alarm.call_args_list, [mock.call(900), mock.call(0)])
                        self.assertEqual(self.outer.work.stat().st_mode & 0o777, 0o700)
                        with self.assertRaises(FileExistsError):
                            self.outer.main()
                    finally:
                        os.umask(old_umask)
            self.outer.work = Path(temp) / 'failed'
            with mock.patch.object(sys, 'argv', ['outer.py', 'provision']), \
                    mock.patch.object(self.outer.signal, 'alarm'), mock.patch.object(self.outer.signal, 'signal'), \
                    mock.patch.object(self.outer, 'create_overlay'), \
                    mock.patch.object(self.outer, 'supervise', side_effect=ValueError('failed')), \
                    mock.patch.object(self.outer, 'export') as export:
                old_umask = os.umask(0o077)
                try:
                    with self.assertRaises(ValueError):
                        self.outer.main()
                finally:
                    os.umask(old_umask)
                export.assert_not_called()
        with self.assertRaises(self.outer.Refused):
            self.outer.alarm(0, None)

    def test_overlay_hash_geometry_and_immutable_base(self):
        with tempfile.TemporaryDirectory() as temp:
            self.outer.root = Path(temp)
            self.outer.work = Path(temp) / 'private'
            self.outer.work.mkdir(mode=0o700)
            base = Path(temp) / 'base.qcow2'
            base.write_bytes(b'new-public-base')
            expected = hashlib.sha256(base.read_bytes()).hexdigest()
            self.outer.BASE_SHA256 = expected
            original = {'format': 'qcow2', 'virtual-size': 2**30}
            current = {'format': 'qcow2', 'virtual-size': 2**30, 'backing-filename': str(base)}
            final = dict(current, **{'virtual-size': self.outer.VIRTUAL_BYTES})
            with mock.patch.object(self.outer, 'metadata', side_effect=[original, current, final]), \
                    mock.patch.object(self.outer.subprocess, 'run') as command, \
                    mock.patch.object(self.outer, 'command') as resize:
                self.outer.create_overlay(expected)
            self.assertEqual(command.call_args.args[0][:2], ['qemu-img', 'create'])
            self.assertEqual(resize.call_args.args[0][:2], ['qemu-img', 'resize'])
            with self.assertRaises(self.outer.Refused):
                self.outer.create_overlay('0' * 64)
            with mock.patch.object(self.outer, 'metadata', return_value=dict(original, **{'backing-filename': 'host'})), \
                    self.assertRaises(self.outer.Refused):
                self.outer.create_overlay(expected)


    def test_command_metadata_and_already_grown_overlay(self):
        with mock.patch.object(self.outer.subprocess, 'run', return_value=types.SimpleNamespace(stdout='{}')) as run:
            self.outer.command(['qemu-img', '--version'])
            self.assertEqual(run.call_args.kwargs['timeout'], 30)
            self.assertEqual(run.call_args.kwargs['env'], {'PATH': os.defpath})
            self.assertEqual(self.outer.metadata(Path('/public')), {})
        with tempfile.TemporaryDirectory() as temp:
            self.outer.root = Path(temp)
            self.outer.work = Path(temp) / 'private'
            self.outer.work.mkdir(mode=0o700)
            base = Path(temp) / 'base.qcow2'
            base.write_bytes(b'new-public-base')
            expected = hashlib.sha256(base.read_bytes()).hexdigest()
            self.outer.BASE_SHA256 = expected
            original = {'format': 'qcow2', 'virtual-size': self.outer.VIRTUAL_BYTES}
            current = dict(original, **{'backing-filename': str(base)})
            with mock.patch.object(self.outer, 'metadata', side_effect=[original, current, current]), \
                    mock.patch.object(self.outer.subprocess, 'run'), mock.patch.object(self.outer, 'command') as resize:
                self.outer.create_overlay(expected)
            resize.assert_not_called()
        self.outer._SERIAL = ['-chardev', 'ringbuf,id=ci-serial,size=65536', '-serial', 'chardev:ci-serial']
        self.assertIn('ringbuf,id=ci-serial,size=65536', self.outer.argv('lifecycle'))


class Provision(unittest.TestCase):
    def setUp(self):
        result = templates._render(descriptor(), templates.sources(ROOT))
        self.source = result.files['iso/provision.py']
        self.provision = module('provision', self.source)

    def test_regular_hash_size_and_drift_no_marker(self):
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / 'public-input'
            raw = b'public-small-package'
            path.write_bytes(raw)
            self.provision.verify(path, len(raw), hashlib.sha256(raw).hexdigest())
            for size, sha in ((len(raw) + 1, hashlib.sha256(raw).hexdigest()), (len(raw), '0' * 64)):
                with self.assertRaises(ValueError):
                    self.provision.verify(path, size, sha)
            alias = Path(temp) / 'alias'
            alias.symlink_to(path)
            with self.assertRaises(ValueError):
                self.provision.verify(alias, len(raw), hashlib.sha256(raw).hexdigest())
        self.assertEqual(self.source.count(b"pathlib.Path('/var/lib/local-ci/provisioned').touch()"), 1)
        self.assertIn(b"os.chmod(pathlib.Path('/opt/actions-runner'), 0o700)", self.source)

    def test_offline_install_bootstrap_requires_alpine_and_installer_before_marker(self):
        # Filesystem and commands are finite fakes. The verify() hash predicate
        # has its own real-byte controls above; these tests prove call ordering.
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            iso = root / 'mnt'
            (iso / 'packages').mkdir(parents=True)
            self.provision.ROOT = iso
            for package in self.provision.PACKAGES:
                (iso / 'packages' / (package['id'] + '.deb')).write_bytes(b'fake-public-package')
            (iso / 'actions-runner-linux-x64-2.336.0.tar.gz').write_bytes(b'fake-public-runner')
            events = []
            def path(value):
                value = str(value)
                return root / value.lstrip('/') if value.startswith('/') else Path(value)
            account = types.SimpleNamespace(pw_uid=os.geteuid() + 1000, pw_gid=os.getegid() + 1000,
                                            pw_dir='/home/runner')
            created = [False]
            versions = {row['name']: row['version'] for row in self.provision.PACKAGES}
            def user(_):
                if not created[0]:
                    raise KeyError('runner')
                return account
            def command(args, **kwargs):
                events.append(args)
                if args[0] == 'dpkg-query':
                    return types.SimpleNamespace(stdout=versions[args[-1]])
                if args[0] == 'useradd':
                    created[0] = True
                    path('/home/runner').mkdir(parents=True)
                if args == ['python3', '/mnt/install-runner.py']:
                    self.assertFalse(path('/var/lib/local-ci/provisioned').exists())
                    path('/opt/actions-runner').mkdir(parents=True)
                return types.SimpleNamespace(stdout='')
            path('/var/lib').mkdir(parents=True)
            path('/opt').mkdir()
            fake_hashlib = types.SimpleNamespace(file_digest=lambda *_: types.SimpleNamespace(
                hexdigest=lambda: self.provision.ARCHIVE_SHA256))
            with mock.patch.object(self.provision, 'pathlib', types.SimpleNamespace(Path=path)), \
                    mock.patch.object(self.provision, 'pwd', types.SimpleNamespace(getpwnam=user)), \
                    mock.patch.object(self.provision, 'command', side_effect=command), \
                    mock.patch.object(self.provision, 'verify') as verify, \
                    mock.patch.object(self.provision, 'hashlib', fake_hashlib), \
                    mock.patch.object(self.provision.os, 'chown') as chown:
                with self.assertRaises(FileNotFoundError):
                    self.provision.main()
                self.assertFalse(path('/var/lib/local-ci/provisioned').exists())
                # Reset only this fixture's owned state, keeping the missing-input
                # refusal as a distinct causal run.
                shutil.rmtree(path('/var/lib/local-ci'))
                shutil.rmtree(path('/home'))
                shutil.rmtree(path('/opt/public-fixtures'))
                created[0] = False
                (iso / 'alpine.tar').write_bytes(b'public-alpine-fixture')
                self.provision.main()
                self.assertTrue(path('/var/lib/local-ci/provisioned').is_file())
                self.assertIn(mock.call(iso / 'alpine.tar', len(b'public-alpine-fixture'), vm_backend.ALPINE_SHA), verify.call_args_list)
                apt = next(args for args in events if args[0] == 'apt-get')
                self.assertIn('--no-download', apt)
                self.assertIn('Dir::Etc::sourcelist=/dev/null', apt)
                self.assertEqual(sum(args == ['python3', '/mnt/install-runner.py'] for args in events), 1)
                self.assertEqual(path('/var/lib/local-ci').stat().st_mode & 0o777, 0o711)
                self.assertEqual(path('/var/lib/local-ci').stat().st_uid, os.geteuid())
                for name in ('work', 'sessions'):
                    self.assertEqual(path('/var/lib/local-ci/' + name).stat().st_mode & 0o777, 0o700)
                    self.assertIn(mock.call(path('/var/lib/local-ci/' + name), account.pw_uid, account.pw_gid),
                                  chown.call_args_list)
                self.assertEqual(path('/opt/actions-runner').stat().st_mode & 0o777, 0o700)
                self.assertEqual(path('/opt/public-fixtures/alpine.tar').read_bytes(), b'public-alpine-fixture')


    def test_bounded_command_and_preexisting_account_refusal(self):
        with mock.patch.object(self.provision.subprocess, 'run') as run:
            self.provision.command(['apt-get', '--version'])
        self.assertEqual(run.call_args.kwargs['timeout'], 600)
        self.assertEqual(run.call_args.kwargs['env'], {'PATH': os.defpath, 'DEBIAN_FRONTEND': 'noninteractive'})
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            iso = root / 'mnt'
            (iso / 'packages').mkdir(parents=True)
            self.provision.ROOT = iso
            self.provision.PACKAGES = []
            (root / 'var/lib').mkdir(parents=True)
            def path(value):
                return root / str(value).lstrip('/')
            with mock.patch.object(self.provision, 'pathlib', types.SimpleNamespace(Path=path)), \
                    mock.patch.object(self.provision, 'command'), \
                    mock.patch.object(self.provision.pwd, 'getpwnam'), \
                    self.assertRaisesRegex(ValueError, 'account preexists'):
                self.provision.main()
            self.assertFalse(path('/var/lib/local-ci/provisioned').exists())


if __name__ == '__main__':
    unittest.main()
