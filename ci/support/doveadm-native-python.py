#!/usr/bin/env python3
"""Execute the real receipt helper, recording/injecting fsync for driver tests."""
import json
import os
import signal
import stat
import sys
from pathlib import Path


def main():
    if sys.argv[1] != '-':
        os.execv(sys.executable, [sys.executable, *sys.argv[1:]])
    state_path = Path(os.environ['NATIVE_DOCKER_STATE'])
    receipt = Path(sys.argv[2]).name
    original = os.fsync

    def fsync(fd):
        kind = 'directory' if stat.S_ISDIR(os.fstat(fd).st_mode) else 'file'
        state = json.loads(state_path.read_text())
        state.setdefault('events', []).append(['fsync', receipt, kind])
        state_path.write_text(json.dumps(state))
        if os.environ.get('NATIVE_SYNC_FAIL') == receipt + ':' + kind:
            raise OSError('injected persistence failure')
        if os.environ.get('NATIVE_SYNC_TERM') == receipt + ':' + kind:
            os.kill(os.getppid(), signal.SIGTERM)
            raise InterruptedError('injected persistence interruption')
        original(fd)

    os.fsync = fsync
    sys.argv = sys.argv[1:]
    try:
        exec(compile(sys.stdin.read(), '<canonical-receipt-helper>', 'exec'), {'__name__': '__main__'})  # noqa: S102 - exact helper stdin from the canonical driver under test
    except OSError:
        return 73
    return 0


if __name__ == '__main__':
    sys.exit(main())
