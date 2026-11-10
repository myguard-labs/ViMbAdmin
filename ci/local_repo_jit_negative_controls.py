"""Observed causal controls in private source copies; frozen inputs never edited."""
import argparse
import hashlib
import importlib.util
import io
import json
import tempfile
import unittest
from pathlib import Path

CHECKOUT = Path(__file__).resolve().parents[1]
ROOT = CHECKOUT / '.github/scripts/local-repo-jit'
CONTROLS = {
    'owned-local-label': ('adapter', "'vimbadmin-owned-local-v1', ", '',
                          'Fixture.test_serial_positive_cancel_probe_fresh_reset_reap'),
    'immutable-id': ('q2', "self.command([*before, receipt['id'], *after], data=data)",
                     "self.command([*before, receipt['name'], *after], data=data)",
                     'Fixture.test_stale_id_name_replacement_never_receives_stdin'),
    'ref-binding': ('v2', " and run.get('head_branch') == proof['ref']", '',
                    'Fixture.test_branch_ref_binding'),
    'retirement': ('v2', "require(self.api.retired(rid), 'unresolved backend prevents successor')",
                   "require(True, 'mutant skips retirement')", 'Fixture.test_unresolved_retirement_blocks_successor'),
    'credential-clean': ('adapter', 'self.execute(v2.CLEAN)', 'pass  # mutant skips credential check',
                         'Fixture.test_dirty_or_credentials_or_version_refuse_before_mint'),
    'capture-disabled': ('bridge', "'capture-output': False", "'capture-output': True",
                         'Bridge.test_start_private_env_and_no_capture'),
    'supported-env': ('bridge', 'ACTIONS_RUNNER_INPUT_JITCONFIG', 'UNSUPPORTED_JIT_VARIABLE',
                      'Bridge.test_start_private_env_and_no_capture'),
    'preexisting': ('q2', "if name in self.command(['container', 'ls', '-a', '--format', '{{.Names}}']).stdout.splitlines():",
                    'if False:', 'Fixture.test_preexisting_and_unqualified_inputs'),
    'native-preflight': ('policy', "if relative != 'bin/libcoreclrtraceptprovider.so' or missing != ['liblttng-ust.so.0']:",
                         'if False:', 'NativePolicy.test_required_missing_and_unexplained_optional_failure_rejected'),
    'old-v3-all-required': ('policy', 'if missing:', "if missing:\n            raise ValueError('old v3 blanket dependency requirement')",
                            'NativePolicy.test_optional_exact_missing_only'),
}


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def run(tests, name):
    stream = io.StringIO()
    result = unittest.TextTestRunner(stream=stream, verbosity=2).run(unittest.defaultTestLoader.loadTestsFromName(name, tests))
    return int(not result.wasSuccessful()), stream.getvalue()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--control', choices=sorted(CONTROLS))
    parser.add_argument('--receipt-dir', type=Path)
    args = parser.parse_args()
    tests = load('causal_fixture', CHECKOUT / 'ci/test_local_repo_jit_docker.py')
    original = tests.m, tests.b, tests.s, tests.n, tests.ERRORS
    paths = {'adapter': ROOT / 'producer.py', 'bridge': ROOT / 'listener_bridge.py',
             'setup': ROOT / 'runner_setup.py', 'q2': ROOT / 'vm_backend.py',
             'v2': ROOT / 'repo_api.py', 'policy': ROOT / 'native_policy.py'}
    directory = args.receipt_dir or Path(tempfile.mkdtemp(prefix='vimbadmin-jit-controls-'))
    if directory.resolve().is_relative_to(CHECKOUT):
        raise RuntimeError('control evidence must stay outside the source checkout')
    directory.mkdir(mode=0o700, exist_ok=True)
    receipts = []
    for slug in ([args.control] if args.control else CONTROLS):
        target, before, after, test = CONTROLS[slug]
        source = paths[target].read_text()
        if target != 'bridge' and source.count(before) != 1:
            raise RuntimeError('mutation preimage is not unique')
        baseline, baseline_log = run(tests, test)
        if baseline:
            raise RuntimeError('baseline test is not green')
        mutant = source.replace(before, after)
        # Keep only public relative-input resolution identical in the copied module.
        mutant = mutant.replace('ROOT = Path(__file__).resolve().parent', 'ROOT = Path(' + repr(str(paths[target].parent)) + ')')
        mutant = mutant.replace("SOURCE = Path(__file__).resolve().with_name('native-inputs.json')", "SOURCE = Path(" + repr(str(ROOT / 'native-inputs.json')) + ')')
        path = directory / (slug + '.py')
        path.write_text(mutant)
        adapter = load('adapter_' + slug.replace('-', '_'), ROOT / 'producer.py')
        altered = load('mutant_' + slug.replace('-', '_'), path)
        tests.m = adapter
        if target == 'adapter':
            tests.m = altered
        elif target == 'bridge':
            tests.b = altered
        elif target == 'setup':
            tests.s = altered
        elif target == 'policy':
            tests.n = altered
        elif target == 'q2':
            tests.m.q2 = altered
        elif target == 'v2' and slug == 'ref-binding':
            tests.m.RepoAPI.matches = staticmethod(altered.RepoAPI.matches)
        elif target == 'v2' and slug == 'retirement':
            tests.m.Supervisor.finish = altered.Supervisor.finish
        tests.ERRORS = (tests.m.Refused, tests.m.q2.Refused, tests.m.v2.Refused, getattr(altered, 'Refused', RuntimeError))
        red, red_log = run(tests, test)
        tests.m, tests.b, tests.s, tests.n, tests.ERRORS = original
        restored, restored_log = run(tests, test)
        (directory / (slug + '-baseline.log')).write_text(baseline_log)
        (directory / (slug + '-red.log')).write_text(red_log)
        (directory / (slug + '-restored.log')).write_text(restored_log)
        stat = path.stat()
        receipts.append({'control': slug, 'test': test, 'command': 'python3 ci/local_repo_jit_negative_controls.py --control ' + slug,
                         'candidate_source_sha256': hashlib.sha256(source.encode()).hexdigest(),
                         'mutant_source_sha256': hashlib.sha256(mutant.encode()).hexdigest(),
                         'mutant_mtime_ns': stat.st_mtime_ns, 'mutant_bytes': stat.st_size,
                         'baseline_exit': baseline, 'mutant_exit': red, 'restored_exit': restored})
        if red != 1 or restored != 0 or 'ERROR:' in red_log:
            raise RuntimeError('control survived or restoration failed: ' + slug)
    (directory / 'result.json').write_text(json.dumps(receipts, indent=2) + '\n')
    print(json.dumps({'controls': len(receipts), 'all_red_then_restored_green': True}))


if __name__ == '__main__':
    main()
