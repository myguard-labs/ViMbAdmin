"""Causal maintained capacity controls, injected modules only; no runtime transport."""
import argparse
import hashlib
import io
import json
import sys
import types
import unittest
from pathlib import Path

CHECKOUT = Path(__file__).resolve().parents[1]
ROOT = CHECKOUT / '.github/scripts/local-repo-jit'
sys.path.insert(0, str(ROOT))
sys.path.insert(0, str(CHECKOUT / 'ci'))

import test_local_repo_jit_budget_observation as budget_tests
import test_local_repo_jit_capacity as capacity_tests
import test_local_repo_jit_capacity_context as context_tests
import test_local_repo_jit_docker as docker_tests
import test_local_repo_jit_native_phase as phase_tests

CONTROLS = [
    ('old4gib-budget', budget_tests, 'budget', 'baseline_budget.py',
     'VIRTUAL_BYTES = derive(ROOT_START_BYTES, MIN_ROOT_BYTES, FILESYSTEM_HEADROOM_BYTES,\n                       PROVISION_HEADROOM_BYTES, GPT_TAIL_BYTES)',
     'VIRTUAL_BYTES = 4 * GIB', 'Budget.test_measured_budget_and_old4gib_contradiction'),
    ('bytes', capacity_tests, 'guest', 'guest_capacity.py',
     'require(available >= PAYLOAD_BYTES + RESERVE_BYTES,', 'require(True,', 'Capacity.test_insufficient_bytes'),
    ('inodes', capacity_tests, 'guest', 'guest_capacity.py',
     'require(value.f_favail >= ENTRIES + ROOT_INODES + RESERVE_INODES,', 'require(True,', 'Capacity.test_insufficient_inodes'),
    ('root-inventory', capacity_tests, 'guest', 'guest_capacity.py',
     'root_seen = True', "raise ValueError('legacy root entry counted')", 'Capacity.test_root_entry_inventory_and_safe_extraction'),
    ('serial-spool-omission', budget_tests, 'observation', 'failure_observation.py',
     'receipt = spool(raw, directory)',
     "receipt = {'file': 'serial-' + '0' * 32 + '.bin', 'sha256': hashlib.sha256(raw).hexdigest(), 'bytes': len(raw)}",
     'Observation.test_each_first_success_failure_read_durable_before_parse'),
    ('serial-file-fsync', budget_tests, 'observation', 'failure_observation.py',
     '            os.fsync(fd)', '            pass  # mutant omits file fsync',
     'Observation.test_each_first_success_failure_read_durable_before_parse'),
    ('serial-directory-fsync', budget_tests, 'observation', 'failure_observation.py',
     "    fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)\n    try:\n        os.fsync(fd)",
     "    fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)\n    try:\n        pass  # mutant omits parent fsync",
     'Observation.test_each_first_success_failure_read_durable_before_parse'),
    ('serial-host-export', phase_tests, 'p', 'native_phase.py',
     "        receipt = value['raw_receipt']", "        return value  # mutant bypasses raw host export",
     'Phase.test_positive_native_export_pid0_nonforce_remove_absence'),
    ('serial-host-file-fsync', phase_tests, 'p', 'native_phase.py',
     '            os.fsync(fd)', '            pass  # mutant omits raw host file fsync',
     'Phase.test_serial_host_file_and_parent_sync_before_acceptance_and_cleanup'),
    ('digest-wellformed', capacity_tests, 'guest', 'guest_capacity.py',
     "require(hashlib.file_digest(stream, 'sha256').hexdigest() == ARCHIVE_SHA,", 'require(True,', 'Capacity.test_wellformed_same_inventory_corruption'),
    ('iso-public-owner', capacity_tests, 'guest', 'guest_capacity.py',
     "require(stat.S_ISREG(info.st_mode), 'symlink/nonregular public archive')",
     "require(stat.S_ISREG(info.st_mode) and info.st_uid == os.geteuid(), 'foreign/symlink archive')",
     'Capacity.test_guest_root_accepts_pinned_actual_host_owned_iso_archive'),
    ('root-growth', capacity_tests, 'guest', 'guest_capacity.py',
     'require(value.f_blocks * value.f_frsize >= MIN_ROOT_BYTES,', 'require(True,', 'Capacity.test_exact_boundary_and_root_growth'),
    ('overlay-growth', capacity_tests, 'overlay', 'owned_overlay.py',
     'descriptor, str(VIRTUAL_BYTES)]', 'descriptor]', 'Overlay.test_grown_fresh_overlay_original_unchanged'),
    ('extraction-complete', capacity_tests, 'guest', 'guest_capacity.py',
     'require(stat.S_ISREG(info.st_mode) and info.st_size == member.size,', 'require(True,', 'Capacity.test_partial_extraction'),
    ('standalone-export', capacity_tests, 'overlay', 'owned_overlay.py',
     "require(metadata['format'] == 'qcow2' and metadata['virtual-size'] == VIRTUAL_BYTES\n            and not metadata.get('backing-filename'),", 'require(True,', 'Overlay.test_flattened_export_required'),
    ('serial-ring-omission', docker_tests, 's', 'runner_setup.py',
     'ringbuf,id=ci-serial,size=65536', 'OMITTED', 'Context.test_coupled_growth_serial_marker_helpers_and_source_preservation'),
    ('guest-marker-omission', docker_tests, 's', 'runner_setup.py',
     'MYGUARD_CI_BOUNDARY:', 'OMITTED:', 'Context.test_coupled_growth_serial_marker_helpers_and_source_preservation'),
]


def run(module, name):
    output = io.StringIO()
    result = unittest.TextTestRunner(stream=output, verbosity=2).run(unittest.defaultTestLoader.loadTestsFromName(name, module))
    return result, output.getvalue()


def load_mutant(filename, before, after):
    path = ROOT / filename
    source = path.read_text()
    if source.count(before) != 1:
        raise RuntimeError('nonunique maintained mutation preimage')
    module = types.ModuleType('capacity_control_mutant')
    module.__file__ = str(path)
    exec(compile(source.replace(before, after), str(path), 'exec'), module.__dict__)  # noqa: S102 - fixed maintained source, named test-only mutation
    return module


def record(directory, name, module, test, mutate, restore):
    baseline, _ = run(module, test)
    if not baseline.wasSuccessful():
        raise RuntimeError('causal baseline failed: ' + name)
    try:
        mutate()
        red, red_log = run(module, test)
    finally:
        restore()
    green, green_log = run(module, test)
    (directory / (name + '-red.log')).write_text(red_log)
    (directory / (name + '-restored.log')).write_text(green_log)
    if red.wasSuccessful() or red.errors or not red.failures or not green.wasSuccessful():
        raise RuntimeError('intended assertion RED/restored GREEN missing: ' + name)
    return {'control': name, 'test': test, 'red_failures': len(red.failures), 'red_errors': len(red.errors), 'restored_green': True}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--receipt-dir', type=Path, required=True)
    args = parser.parse_args()
    directory = args.receipt_dir.resolve()
    if directory.is_relative_to(CHECKOUT):
        raise RuntimeError('causal receipts must stay outside checkout')
    directory.mkdir(mode=0o700)  # Fresh exclusive evidence, no overwrite/adoption.
    before_hashes = {str(p.relative_to(ROOT)): hashlib.sha256(p.read_bytes()).hexdigest() for p in ROOT.glob('*.py')}
    records = []
    for name, target, attribute, filename, before, after, test in CONTROLS:
        original = getattr(target, attribute)
        mutant = load_mutant(filename, before, after)
        consumer = context_tests if name in ('serial-ring-omission', 'guest-marker-omission') else target
        records.append(record(directory, name, consumer, test,
                              lambda target=target, attribute=attribute, mutant=mutant: setattr(target, attribute, mutant),
                              lambda target=target, attribute=attribute, original=original: setattr(target, attribute, original)))
    original = phase_tests.p.observe_failure
    records.append(record(directory, 'failure-observer-omission', phase_tests,
                          'Phase.test_agent_independent_failure_observation_is_redacted',
                          lambda: setattr(phase_tests.p, 'observe_failure', lambda owner: phase_tests.p.__dict__['failure_unavailable']()),
                          lambda: setattr(phase_tests.p, 'observe_failure', original)))
    after_hashes = {str(p.relative_to(ROOT)): hashlib.sha256(p.read_bytes()).hexdigest() for p in ROOT.glob('*.py')}
    if before_hashes != after_hashes:
        raise RuntimeError('maintained inputs changed during controls')
    (directory / 'result.json').write_text(json.dumps({'controls': records, 'source_unchanged': True}, indent=2) + '\n')
    print(json.dumps({'intended_red_restored_green_controls': len(records)}))


if __name__ == '__main__':
    import failure_observation
    phase_tests.p.failure_unavailable = failure_observation.unavailable
    main()
