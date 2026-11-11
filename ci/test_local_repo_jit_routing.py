"""Offline routing contract; labels are public placement data, never authority."""
import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / '.github/scripts/local-repo-jit/repo_api.py'
LEGACY_SELECTOR = '["self-hosted-run-${{ github.run_id }}-${{ github.run_attempt }}", "vimbadmin-owned-local-v1-run-${{ github.run_id }}-${{ github.run_attempt }}", "docker-run-${{ github.run_id }}-${{ github.run_attempt }}", "jit-env-input-run-${{ github.run_id }}-${{ github.run_attempt }}", "vimbadmin-pristine-jit-v1-run-${{ github.run_id }}-${{ github.run_attempt }}"]'
spec = importlib.util.spec_from_file_location('routing_api', SOURCE)
if spec is None or spec.loader is None:
    raise RuntimeError('Routing contract source loader unavailable')
api = importlib.util.module_from_spec(spec)
spec.loader.exec_module(api)


class ScopedRouting(unittest.TestCase):
    def test_all_fourteen_selectors_require_exact_scoped_five(self):
        count = 0
        for path in (ROOT / '.github/workflows').glob('*.yml'):
            for line in path.read_text().splitlines():
                if not line.startswith('    runs-on: '):
                    continue
                self.assertEqual(line, '    runs-on: ubuntu-24.04', str(path))
                # Exercise the retained scoped-label contract on the legacy projection.
                labels = json.loads(LEGACY_SELECTOR)
                actual = [label.replace('${{ github.run_id }}', '312').replace(
                    '${{ github.run_attempt }}', '2') for label in labels]
                api.require_scoped_job_labels(actual, 312, 2)
                count += 1
        self.assertEqual(count, 14)

    def test_post_and_get_sets_allow_order_only(self):
        labels = api.scoped_job_labels(312, 2)
        api.require_scoped_job_labels(labels, 312, 2)  # POST
        api.require_scoped_job_labels(list(reversed(labels)), 312, 2)  # GET names
        self.assertEqual(len(set(labels)), 5)
        self.assertFalse({'self-hosted', 'Linux', 'X64', 'docker'} & set(labels))
        # Old jobs require a generic label this runner does not publish.
        self.assertFalse({'self-hosted', 'vimbadmin-owned-local-v1', 'docker',
                          'jit-env-input', api.CAPABILITY}.issubset(labels))

    def test_wrong_run_attempt_missing_each_generic_subset_and_duplicates(self):
        labels = api.scoped_job_labels(312, 2)
        candidates = [api.scoped_job_labels(313, 2), api.scoped_job_labels(312, 1),
                      ['self-hosted', 'vimbadmin-owned-local-v1', 'docker', 'jit-env-input', api.CAPABILITY],
                      labels[:1], labels + ['Linux'], labels[:-1] + labels[:1], None]
        candidates += [labels[:i] + labels[i + 1:] for i in range(5)]
        for candidate in candidates:
            with self.subTest(labels=candidate), self.assertRaises(api.Refused):
                api.require_scoped_job_labels(candidate, 312, 2)
        for run, attempt in [(0, 2), (-1, 2), (312, 0), (True, 2), (312, True), ('312', 2)]:
            with self.subTest(run=run, attempt=attempt), self.assertRaises(api.Refused):
                api.scoped_job_labels(run, attempt)

    def test_scoped_production_capability_refused_before_transport(self):
        calls = []
        def transport(*args):
            calls.append(args)
            return 201, {'runner': {'id': 1, 'name': 'vi-local-ci-208c2d13-01'},
                         'encoded_jit_config': 'fixture-only'}
        client = api.RepoAPI(transport=transport, guard=lambda: None)
        scoped = api.scoped_job_labels(312, 2)
        for labels in ([api.CAPABILITY], scoped, [api.CAPABILITY + '-run-malformed'],
                       [api.CAPABILITY.upper()], [api.CAPABILITY.title()],
                       [label.upper() for label in scoped], [label.title() for label in scoped],
                       [api.CAPABILITY.title() + '-RuN-312-2']):
            with self.subTest(labels=labels), self.assertRaisesRegex(api.Refused, 'production capability'):
                client.mint('vi-local-ci-208c2d13-01', 1, labels)
        self.assertEqual(calls, [])
