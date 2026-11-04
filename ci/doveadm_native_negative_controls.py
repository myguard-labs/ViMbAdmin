"""Prove canonical-driver regressions are detected, using private source copies."""
import hashlib
import io
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import test_doveadm_native_driver as tests


def main():
    source = tests.DRIVER.read_text()
    controls = (
        ('receipt-umask-mode-drift', 'test_receipt_privacy_preserves_original_archive_executable_mode',
         [('set -euo pipefail\n', 'set -euo pipefail\numask 077\n')], 'original archive executable mode'),
        ('receipt-file-sync', 'test_durable_receipt_ordering_before_build_identity_use_and_cleanup',
         [('    os.fsync(fd)', '    pass # fsync omitted')], 'receipt file must be fsynced'),
        ('receipt-directory-sync', 'test_durable_receipt_ordering_before_build_identity_use_and_cleanup',
         [('        os.fsync(directory)', '        pass # parent fsync omitted')], 'receipt parent must be fsynced'),
        ('mutable-cleanup', 'test_name_and_tag_replacements_survive_immutable_cleanup',
         [('docker rm --force "$cid"', 'docker rm --force "$container"'),
          ('docker image rm "$image_id"', 'docker image rm "$image"')], 'Lists differ'),
        ('image-use-before-identity', 'test_foreign_absent_and_malformed_ids_are_never_used_or_removed',
         [('\nimage_identity\n', '\n: # identity check removed\n')], 'identity mismatch must fail before start'),
        ('container-use-before-identity', 'test_foreign_absent_and_malformed_ids_are_never_used_or_removed',
         [('\ncontainer_identity\n', '\n: # identity check removed\n')], 'identity mismatch must fail before start'),
        ('lost-create-receipt', 'test_failed_build_creation_and_signals_recover_owned_ids',
         [('$create_attempted == 1', '$create_attempted == 0')], 'Lists differ'),
    )
    receipts = []
    with tempfile.TemporaryDirectory(prefix='native-driver-controls-') as scratch:
        driver = Path(scratch) / 'driver.sh'
        for name, method, replacements, assertion in controls:
            mutant = source
            for old, new in replacements:
                if mutant.count(old) != 1:
                    raise AssertionError(f'{name}: mutation site is not unique')
                mutant = mutant.replace(old, new)
            row = {'name': name, 'test': method}
            for phase, text in (('baseline', source), ('mutant', mutant), ('restored', source)):
                driver.write_text(text)
                output = io.StringIO()
                suite = unittest.defaultTestLoader.loadTestsFromName(method, tests.NativeDriver)
                with patch.object(tests, 'DRIVER', driver):
                    result = unittest.TextTestRunner(stream=output, verbosity=2).run(suite)
                row[phase] = {'sha256': hashlib.sha256(text.encode()).hexdigest(),
                              'mtime_ns': driver.stat().st_mtime_ns, 'size': driver.stat().st_size,
                              'tests': result.testsRun, 'output': output.getvalue()}
                if phase == 'mutant':
                    valid = result.testsRun == 1 and not result.errors and result.failures
                    valid = valid and any(assertion in failure for _, failure in result.failures)
                    if not valid:
                        raise AssertionError(f'{name}: control survived or failed outside its intended assertion\n{output.getvalue()}')
                elif not result.wasSuccessful() or result.testsRun != 1:
                    raise AssertionError(f'{name}: {phase} must be green\n{output.getvalue()}')
            receipts.append(row)
    print(json.dumps({'source_sha256': hashlib.sha256(source.encode()).hexdigest(), 'controls': receipts}, indent=2))


if __name__ == '__main__':
    main()
