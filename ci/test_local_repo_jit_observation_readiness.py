"""A boot observation without required pass markers cannot qualify the baseline."""
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import test_local_repo_jit_native_phase as fixtures


class Readiness(unittest.TestCase):
    def test_context_seal_requires_actual_materialized_file(self):
        with tempfile.TemporaryDirectory() as directory:
            workspace = Path(directory)
            with patch.object(fixtures.p, 'WORKSPACE', workspace):
                self.assertIsNone(fixtures.p.sha_context_seal())
                context = workspace / 'public-capacity-context-q1'
                context.mkdir()
                seal = context / 'context-seal.json'
                seal.write_text('{}\n')
                self.assertEqual(fixtures.p.sha_context_seal(), fixtures.p.sha(seal))

    def test_missing_root_or_provision_marker_keeps_failed_owned_resource(self):
        import failure_observation

        for console in [b'', b'MYGUARD_CI_BOUNDARY:root_ready:pass:none\n']:
            case = fixtures.Phase()
            case.setUp()
            try:
                with patch.object(fixtures.p, 'observe_failure', return_value=failure_observation.parse_console(console)), self.assertRaises(fixtures.ERRORS):
                    case.run_phase()
                self.assertFalse((case.directory / 'native-proof.json').exists())
                self.assertFalse((case.directory / 'result.json').exists())
                self.assertEqual(len(case.engine.containers), 1)
                self.assertFalse(any(args[0] in ('rm', 'cp') for args in case.engine.argv))
            finally:
                case.doCleanups()


if __name__ == '__main__':
    unittest.main()
