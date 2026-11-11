"""Pinned Ubuntu Runner8.0.28 native closure; guest execution requires a later grant."""
import ctypes
import hashlib
import json
import os
import re
import subprocess
from pathlib import Path

SOURCE = Path(__file__).resolve().with_name('native-inputs.json')
if hashlib.sha256(SOURCE.read_bytes()).hexdigest() != '467a2b52424f02b3675a98395d4af068e57d2baa04d89a98e93fbbd7b38c01c3':
    raise ValueError('pinned native input metadata changed')
INPUTS = json.loads(SOURCE.read_text())


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def verify(target, run=subprocess.run, load=ctypes.CDLL):
    """All payload hashes first. The only optional failure is the pinned PAL dlopen."""
    if INPUTS['runtime_version'] != '8.0.28' or INPUTS['optional_missing_sonames'] != ['liblttng-ust.so.0']:
        raise ValueError('native policy contract changed')
    for relative, metadata in INPUTS['members'].items():
        if digest(target / relative) != metadata['sha256']:
            raise ValueError('installed native payload changed')
    skipped = INPUTS['ubuntu_unselected_members']
    if skipped != ['externals/node20_alpine/bin/node', 'externals/node24_alpine/bin/node']:
        raise ValueError('Ubuntu Node selector contract changed')
    optional = []
    # This search directory is for dependency inspection of side-by-side archive
    # libraries (including debugger libraries), not the listener environment.
    environment = {'PATH': os.defpath, 'LD_LIBRARY_PATH': str(target / 'bin')}
    for relative in INPUTS['members']:
        if relative in skipped:
            continue
        result = run(['ldd', str(target / relative)], capture_output=True, text=True, check=False, env=environment)
        text = result.stdout + result.stderr
        missing = re.findall(r'^\s*(\S+)\s+=>\s+not found\s*$', text, re.MULTILINE)
        unexplained = '\n'.join(line for line in text.splitlines() if 'not found' in line and not re.fullmatch(r'\s*\S+\s+=>\s+not found\s*', line))
        if result.returncode or unexplained or 'undefined symbol' in text:
            raise ValueError('native loader inspection unresolved')
        if missing:
            if relative != 'bin/libcoreclrtraceptprovider.so' or missing != ['liblttng-ust.so.0']:
                raise ValueError('required native dependency missing')
            # v8.0.28 PAL constructor explicitly ignores this dlopen failure.
            # Do not set LTTng=0, add a compatibility symlink or mask another failure.
            optional.append({'member': relative, 'unavailable': missing})
    for library in INPUTS['dynamic_system_libraries']:
        try:
            load(library)
        except OSError:
            raise ValueError('required dynamic system library unavailable') from None
    for relative, expected in [('bin/Runner.Listener', '2.336.0'),
                               ('externals/node20/bin/node', 'v20.'), ('externals/node24/bin/node', 'v24.')]:
        result = run(['/usr/sbin/runuser', '-u', 'runner', '--', str(target / relative), '--version'],
                     capture_output=True, text=True, check=False, env={'PATH': os.defpath})
        version = result.stdout.strip()
        if result.returncode or (version != expected if relative.startswith('bin/') else not version.startswith(expected)):
            raise ValueError('active native executable version unresolved')
    return {'runtime': '8.0.28', 'elf_hashes': len(INPUTS['members']),
            'unselected_alpine': skipped, 'optional_tracing_unavailable': optional,
            'active_runner_version': '2.336.0', 'actual_jit_qualified': False}
