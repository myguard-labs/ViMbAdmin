"""Bounded serial reads: private durable raw receipt before marker parsing."""
import base64
import hashlib
import json
import os
import re
import socket
import stat
import uuid
from pathlib import Path

LIMIT = 65536
SOCKET = '/work/vi-local-ci-208c2d13-v4-capacity-r3-q1/qmp.sock'
SPOOL = Path(SOCKET).parent / 'serial-observations'
EXCEPTIONS = {'ValueError', 'AssertionError', 'RuntimeError', 'OSError', 'CalledProcessError', 'none', 'unclassified'}


def unavailable():
    return {'status': 'unavailable', 'marker_events': [], 'categories': [],
            'console_sha256': None, 'console_bytes': 0, 'raw_retained': False, 'raw_receipt': None}


def parse_console(raw):
    if not isinstance(raw, bytes) or len(raw) > LIMIT:
        raise ValueError('bounded console bytes required')
    markers = []
    for match in re.finditer(rb'MYGUARD_CI_BOUNDARY:(root_ready|provision):(start|pass|fail):([A-Za-z_]+)', raw):
        boundary, event, exception = (value.decode('ascii') for value in match.groups())
        markers.append({'boundary': boundary, 'event': event,
                        'exception_type': exception if exception in EXCEPTIONS else 'unclassified'})
    categories = [name for needle, name in [(b'Kernel panic', 'kernel-panic'),
                                            (b'No bootable device', 'no-bootable-device')] if needle in raw]
    return {'status': 'observed', 'marker_events': markers[-32:], 'categories': categories,
            'console_sha256': hashlib.sha256(raw).hexdigest(), 'console_bytes': len(raw), 'raw_retained': False, 'raw_receipt': None}


def validate(value):
    if set(value) != set(unavailable()) or value['status'] not in ('observed', 'unavailable'):
        raise ValueError('observation schema refused')
    if type(value['raw_retained']) is not bool or type(value['console_bytes']) is not int or not 0 <= value['console_bytes'] <= LIMIT:
        raise ValueError('observation size/raw retention refused')
    if value['status'] == 'observed':
        if not isinstance(value['console_sha256'], str) or not re.fullmatch('[0-9a-f]{64}', value['console_sha256']):
            raise ValueError('console digest refused')
    elif value != unavailable():
        raise ValueError('unavailable observation refused')
    receipt = value['raw_receipt']
    if value['raw_retained']:
        if (not isinstance(receipt, dict) or set(receipt) != {'file', 'sha256', 'bytes'}
                or not isinstance(receipt['file'], str) or not re.fullmatch('serial-[0-9a-f]{32}\\.bin', receipt['file'])
                or receipt['sha256'] != value['console_sha256'] or type(receipt['bytes']) is not int
                or receipt['bytes'] != value['console_bytes']):
            raise ValueError('raw serial receipt refused')
    elif receipt is not None:
        raise ValueError('raw serial receipt without retention refused')
    if (not isinstance(value['marker_events'], list) or len(value['marker_events']) > 32
            or not isinstance(value['categories'], list)
            or any(name not in ('kernel-panic', 'no-bootable-device') for name in value['categories'])):
        raise ValueError('observation markers/categories refused')
    for marker in value['marker_events']:
        if (set(marker) != {'boundary', 'event', 'exception_type'} or marker['boundary'] not in ('root_ready', 'provision')
                or marker['event'] not in ('start', 'pass', 'fail') or marker['exception_type'] not in EXCEPTIONS):
            raise ValueError('observation marker refused')
    return value


def exchange(connection, reader, request):
    connection.sendall(json.dumps(request).encode() + b'\n')
    for _ in range(32):
        line = reader.readline(131072)
        if not line or len(line) >= 131072:
            raise ValueError('bounded QMP response required')
        response = json.loads(line)
        if 'error' in response:
            raise ValueError('QMP observation unavailable')
        if 'return' in response:
            return response['return']
    raise ValueError('QMP response unresolved')


def private_spool(directory):
    directory.mkdir(mode=0o700, exist_ok=True)
    info = directory.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
        raise ValueError('private owned serial directory required')
    fd = os.open(directory.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def spool(raw, directory):
    if not isinstance(raw, bytes) or len(raw) > LIMIT:
        raise ValueError('bounded serial bytes required')
    name = 'serial-' + uuid.uuid4().hex + '.bin'
    receipt = {'file': name, 'sha256': hashlib.sha256(raw).hexdigest(), 'bytes': len(raw)}
    # Exclusive files remain on any failure; never parse/use an incomplete read.
    for path, data in ((directory / name, raw),
                       (directory / (name + '.json'), json.dumps(receipt).encode() + b'\n')):
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            os.fchmod(fd, 0o600)
            written = os.write(fd, data)
            os.fsync(fd)
            if written != len(data):
                raise OSError('short serial receipt write')
        finally:
            os.close(fd)
    fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)
    return receipt


def observe(factory=socket.socket, directory=SPOOL):
    try:
        # Refuse an unwritable/foreign spool before consuming the QMP ring.
        private_spool(directory)
        with factory(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
            connection.settimeout(3); connection.connect(SOCKET)
            with connection.makefile('rb') as reader:
                greeting = reader.readline(131072)
                if not greeting or len(greeting) >= 131072 or 'QMP' not in json.loads(greeting):
                    raise ValueError('QMP greeting refused')
                exchange(connection, reader, {'execute': 'qmp_capabilities'})
                encoded = exchange(connection, reader, {'execute': 'ringbuf-read', 'arguments': {
                    'device': 'ci-serial', 'size': LIMIT, 'format': 'base64'}})
                raw = base64.b64decode(encoded, validate=True)
                receipt = spool(raw, directory)
                value = parse_console(raw)
                value.update(raw_retained=True, raw_receipt=receipt)
                return validate(value)
    except (OSError, ValueError, TypeError):
        return unavailable()


if __name__ == '__main__':
    print(json.dumps(observe()))
