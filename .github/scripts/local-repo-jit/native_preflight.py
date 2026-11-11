"""Guest stdin program for a later granted owned-ID QGA execution; do not run on host."""
import json
import sys
from pathlib import Path

sys.path.insert(0, '/opt/local-ci-inputs')
import native_policy  # Guest-only installed immutable input directory.

proof = native_policy.verify(Path('/opt/actions-runner'))
ready = json.loads(Path('/var/lib/local-ci/runner-inputs-ready').read_text())
assert ready['runner_name'] == 'vi-local-ci-208c2d13-01'
assert ready['version'] == '2.336.0' and ready['registered'] is False
assert ready['native']['runtime'] == '8.0.28'
assert not any(Path('/opt/actions-runner', name).exists() for name in ('.runner', '.credentials', '.credentials_rsaparams'))
print(json.dumps(proof))
