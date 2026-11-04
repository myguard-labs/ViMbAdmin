"""Private stdin-only asynchronous listener bridge; output is PID/status only."""
import base64
import hashlib
import importlib.util
import secrets
import socket
from pathlib import Path

QGA_SHA = 'ff687fe027a3de51dac0707c9422d5610cadf5979c3ddb94d1fb93919c387c7d'
SOCKET = '/work/vi-local-ci-208c2d13-vm-q2/qga.sock'
HANDOFF = ('IFS= read -r ACTIONS_RUNNER_INPUT_JITCONFIG && export ACTIONS_RUNNER_INPUT_JITCONFIG && '
           'cd /opt/actions-runner && exec ./run.sh')


def perform(request, call):
    mode = request.get('mode')
    if mode == 'start':
        if set(request) != {'mode', 'payload'}:
            raise ValueError('unsupported start schema')
        payload = request['payload']
        if not isinstance(payload, str) or not payload or len(payload) > 1000000 or '\n' in payload or '\r' in payload:
            raise ValueError('invalid private input')
        result = call({'execute': 'guest-exec', 'arguments': {
            'path': '/usr/sbin/runuser',
            'arg': ['-u', 'runner', '--', 'env', '-i', 'PATH=/usr/sbin:/usr/bin:/sbin:/bin', '/bin/sh', '-c', HANDOFF],
            'input-data': base64.b64encode((payload + '\n').encode()).decode(),
            'capture-output': False}})
        if type(result.get('pid')) is not int or result['pid'] <= 0:
            raise ValueError('missing listener PID')
        return {'pid': result['pid']}
    if mode == 'status':
        if set(request) != {'mode', 'pid'} or type(request['pid']) is not int or request['pid'] <= 0:
            raise ValueError('invalid listener identity')
        result = call({'execute': 'guest-exec-status', 'arguments': {'pid': request['pid']}})
        if type(result.get('exited')) is not bool:
            raise ValueError('missing listener state')
        if not result['exited']:
            return {'exited': False}
        code, signal = result.get('exitcode'), result.get('signal')
        if not ((type(code) is int and code >= 0 and signal is None)
                or (type(signal) is int and signal > 0 and code is None)):
            raise ValueError('ambiguous listener termination')
        # Never forward arbitrary guest output, errors or listener transcripts.
        return {'exited': True, 'exitcode': code, 'signal': signal}
    raise ValueError('unsupported bridge operation')


def main(request):
    source = Path('/opt/vm/qga.py')
    if hashlib.sha256(source.read_bytes()).hexdigest() != QGA_SHA:
        raise RuntimeError('frozen QGA transport changed')
    spec = importlib.util.spec_from_file_location('frozen_qga_exchange', source)
    qga = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(qga)
    with socket.socket(socket.AF_UNIX) as connection:
        connection.settimeout(10)
        connection.connect(SOCKET)
        with connection.makefile('rb') as reader:
            sync = secrets.randbelow(2**31)
            if qga.exchange(connection, reader, {'execute': 'guest-sync', 'arguments': {'id': sync}}) != sync:
                raise RuntimeError('agent synchronization failed')
            return perform(request, lambda message: qga.exchange(connection, reader, message))
