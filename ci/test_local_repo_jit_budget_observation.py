"""Measured budget and bounded serial observation; fake sockets, no runtime."""
import base64
import io
import json
import os
import stat
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1] / '.github/scripts/local-repo-jit'
sys.path.insert(0, str(ROOT))
import baseline_budget as budget
import failure_observation as observation


class Budget(unittest.TestCase):
    def test_measured_budget_and_old4gib_contradiction(self):
        old_upper = 4 * budget.GIB - budget.ROOT_START_BYTES - budget.GPT_TAIL_BYTES
        self.assertEqual(old_upper, 3220160000)
        self.assertLess(old_upper, budget.MIN_ROOT_BYTES)
        self.assertEqual(budget.VIRTUAL_BYTES, 5 * budget.GIB)
        self.assertGreaterEqual(budget.VIRTUAL_BYTES - budget.ROOT_START_BYTES - budget.GPT_TAIL_BYTES,
                                budget.MIN_ROOT_BYTES + budget.FILESYSTEM_HEADROOM_BYTES + budget.PROVISION_HEADROOM_BYTES)

    def test_wholegib_exact_and_boundary(self):
        self.assertEqual(budget.derive(0, 3 * budget.GIB, 0, 0, 0), 3 * budget.GIB)
        self.assertEqual(budget.derive(1, 3 * budget.GIB, 0, 0, 0), 4 * budget.GIB)
        for value in [-1, 1.5, True]:
            with self.assertRaises(ValueError):
                budget.derive(value, 3 * budget.GIB, 0, 0, 0)


class Socket:
    def __init__(self, lines):
        self.lines, self.requests = lines, []

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False

    def settimeout(self, value):
        assert value == 3

    def connect(self, path):
        assert path == observation.SOCKET

    def makefile(self, mode):
        assert mode == 'rb'
        return io.BytesIO(self.lines)

    def sendall(self, raw):
        self.requests.append(json.loads(raw))


class Observation(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.directory = Path(temporary.name) / 'serial'

    def observe(self, factory):
        return observation.observe(factory, self.directory)

    def test_redaction_boundaries_and_known_first_marker(self):
        raw = b'TOKEN=SECRET_VALUE\nMYGUARD_CI_BOUNDARY:root_ready:start:none\nMYGUARD_CI_BOUNDARY:root_ready:fail:ValueError\n'
        value = observation.validate(observation.parse_console(raw))
        self.assertNotIn('SECRET_VALUE', json.dumps(value))
        self.assertFalse(value['raw_retained'])
        self.assertEqual(value['marker_events'][-1], {'boundary': 'root_ready', 'event': 'fail', 'exception_type': 'ValueError'})
        value = observation.parse_console(b'MYGUARD_CI_BOUNDARY:provision:fail:SecretException\nKernel panic\nNo bootable device')
        self.assertEqual(value['marker_events'][0]['exception_type'], 'unclassified')
        self.assertEqual(value['categories'], ['kernel-panic', 'no-bootable-device'])
        self.assertNotIn('SecretException', json.dumps(value))
        self.assertEqual(observation.parse_console(b'x' * observation.LIMIT)['console_bytes'], observation.LIMIT)
        for bad in [b'x' * (observation.LIMIT + 1), 'text']:
            with self.assertRaises(ValueError):
                observation.parse_console(bad)

    def test_marker_count_and_validation_errors(self):
        value = observation.parse_console(b'MYGUARD_CI_BOUNDARY:root_ready:pass:none\n' * 40)
        self.assertEqual(len(value['marker_events']), 32)
        self.assertEqual(observation.validate(observation.unavailable()), observation.unavailable())
        changes = [('extra', 'SECRET'), ('status', 'ignored'), ('raw_retained', True), ('console_bytes', -1),
                   ('console_sha256', 'wrong'), ('marker_events', [{}]), ('categories', ['SECRET'])]
        for key, bad in changes:
            mutated = dict(value); mutated[key] = bad
            with self.assertRaises(ValueError):
                observation.validate(mutated)
        bad = observation.unavailable(); bad['console_bytes'] = 1
        with self.assertRaises(ValueError):
            observation.validate(bad)

    def test_fake_qmp_protocol_without_agent(self):
        raw = b'MYGUARD_CI_BOUNDARY:provision:fail:AssertionError\nSECRET_VALUE'
        lines = b'{"QMP":{}}\n{"event":"ignored"}\n{"return":{}}\n' + json.dumps({'return': base64.b64encode(raw).decode()}).encode() + b'\n'
        connection = Socket(lines)
        value = self.observe(lambda *args: connection)
        self.assertEqual(value['status'], 'observed')
        self.assertNotIn('SECRET_VALUE', json.dumps(value))
        self.assertEqual(connection.requests[-1], {'execute': 'ringbuf-read', 'arguments': {'device': 'ci-serial', 'size': 65536, 'format': 'base64'}})
        self.assertFalse(any('guest' in request['execute'] for request in connection.requests))

    def test_qmp_errors_fail_closed(self):
        for lines in [b'', b'{}\n', b'x' * 131072, b'{"QMP":{}}\n{"error":{}}\n',
                      b'{"QMP":{}}\n', b'{"QMP":{}}\n' + b'{"event":"x"}\n' * 32,
                      b'{"QMP":{}}\n{"return":{}}\n{"return":"!!!"}\n']:
            self.assertEqual(self.observe(lambda *args, lines=lines: Socket(lines)), observation.unavailable())
        with self.assertRaises(ValueError):
            observation.exchange(Socket(b''), io.BytesIO(b'x' * 131072), {'execute': 'x'})
        def failed(*args):
            raise OSError('PRIVATE_CANARY')
        self.assertEqual(self.observe(failed), observation.unavailable())

    def test_each_first_success_failure_read_durable_before_parse(self):
        parser, sync = observation.parse_console, os.fsync
        events, before_parse, values = [], [], []
        def fsync(fd):
            sync(fd)
            events.append('directory' if stat.S_ISDIR(os.fstat(fd).st_mode) else 'file')
        def parse(raw):
            paths = [path for path in self.directory.glob('*.bin') if path.read_bytes() == raw]
            complete = len(paths) == 1
            if complete:
                path = paths[0]
                receipt = json.loads(path.with_name(path.name + '.json').read_text())
                complete = (stat.S_IMODE(path.stat().st_mode) == 0o600 and receipt['bytes'] == len(raw)
                            and receipt['sha256'] == observation.hashlib.sha256(raw).hexdigest())
            before_parse.append(complete and events.count('file') >= 2 and events.count('directory') >= 2)
            events.clear()
            return parser(raw)
        with patch.object(observation.os, 'fsync', side_effect=fsync), patch.object(observation, 'parse_console', side_effect=parse):
            for raw in (b'FIRST_PRIVATE_RAW', b'MYGUARD_CI_BOUNDARY:provision:pass:none',
                        b'MYGUARD_CI_BOUNDARY:provision:fail:ValueError'):
                lines = b'{"QMP":{}}\n{"return":{}}\n' + json.dumps({'return': base64.b64encode(raw).decode()}).encode() + b'\n'
                value = self.observe(lambda *args, lines=lines: Socket(lines))
                self.assertEqual(value['status'], 'observed')
                self.assertTrue(value['raw_retained'])
                values.append(value['raw_receipt']['file'])
        self.assertEqual(before_parse, [True, True, True], 'each raw read must be sealed and file/parent fsynced before parse')
        self.assertEqual(len(set(values)), 3, 'each destructive ring read needs a separate receipt')

    def test_spool_short_write_fsync_interruption_and_errors_fail_closed(self):
        lines = b'{"QMP":{}}\n{"return":{}}\n{"return":"cHJpdmF0ZQ=="}\n'
        real_sync = os.fsync
        def failed_file_sync(fd):
            if stat.S_ISREG(os.fstat(fd).st_mode):
                raise InterruptedError('fixture interruption')
            real_sync(fd)
        for fault in (patch.object(observation.os, 'write', return_value=0),
                      patch.object(observation.os, 'fsync', side_effect=failed_file_sync),
                      patch.object(observation.os, 'open', side_effect=OSError('fixture'))):
            with fault, patch.object(observation, 'parse_console') as parser:
                value = self.observe(lambda *args: Socket(lines))
                self.assertEqual(value, observation.unavailable())
                parser.assert_not_called()

    def test_foreign_or_symlink_spool_refused_before_ring_read(self):
        self.directory.mkdir(mode=0o755)
        with patch.object(observation, 'exchange') as exchange:
            self.assertEqual(self.observe(lambda *args: Socket(b'')), observation.unavailable())
            exchange.assert_not_called()
        self.directory.rmdir()
        self.directory.symlink_to(self.directory.parent)
        self.assertEqual(self.observe(lambda *args: Socket(b'')), observation.unavailable())

    def test_metadata_only_cli_without_socket_access(self):
        import contextlib
        import runpy
        from unittest.mock import patch
        with patch('socket.socket', side_effect=OSError('PRIVATE_CANARY')), patch('pathlib.Path.mkdir', side_effect=OSError('fixture')), contextlib.redirect_stdout(io.StringIO()) as output:
            runpy.run_path(str(ROOT / 'failure_observation.py'), run_name='__main__')
        value = json.loads(output.getvalue())
        self.assertEqual(value, observation.unavailable())
        self.assertNotIn('PRIVATE_CANARY', output.getvalue())

    def test_guest_marker_explicit_serial_and_errors(self):
        import ast
        from unittest.mock import Mock

        import test_local_repo_jit_docker as fixtures
        case = fixtures.PublicSeed()
        case.setUp()
        self.addCleanup(case.doCleanups)
        (case.source / 'iso/install-runner.py').write_text(fixtures.s.INSTALL)
        dockerfile = case.source / 'Dockerfile'
        dockerfile.write_text(dockerfile.read_text().replace('vi-local-ci-208c2d13-vm-image-q2', 'vi-local-ci-208c2d13-vm-jit-inputs-v3'))
        source = fixtures.s.capacity_sources(case.source)['iso/provision.py']
        definition = next(node for node in ast.parse(source).body if isinstance(node, ast.FunctionDef))
        fake = Mock()
        fake.O_WRONLY, fake.O_NOCTTY, fake.O_NONBLOCK = os.O_WRONLY, os.O_NOCTTY, os.O_NONBLOCK
        fake.open.return_value = 9
        payload = b'MYGUARD_CI_BOUNDARY:root_ready:fail:ValueError\n'
        fake.write.return_value = len(payload)
        namespace = {'os': fake, 'print': Mock()}
        exec(compile(ast.Module(body=[definition], type_ignores=[]), '<guest-marker-fragment>', 'exec'), namespace)  # noqa: S102 - fixed generated AST, injected OS and print fakes
        self.assertTrue(namespace['boundary']('root_ready', 'fail', 'ValueError'))
        fake.open.assert_called_once_with('/dev/ttyS0', os.O_WRONLY | os.O_NOCTTY | os.O_NONBLOCK)
        fake.write.assert_called_once_with(9, payload); fake.close.assert_called_once_with(9)
        fake.open.side_effect = OSError('private fixture error')
        self.assertFalse(namespace['boundary']('root_ready', 'start'))


if __name__ == '__main__':
    unittest.main()
