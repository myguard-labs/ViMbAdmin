"""Exercise the maintained structural scan with real clean and defective inputs.

Usage: python3 ci/test-ast-grep.py --corpus CHECKOUT
Creates only temporary Git indexes, fixtures and reports; no commits or services.
The corpus must already have its locked ast-grep engine installed.
"""

import argparse
import importlib.util
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location(
    "structural_scan", Path(__file__).with_name("run-ast-grep.py")
)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("cannot load the maintained structural scan helper")
SCAN = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SCAN)


class StructuralScan(unittest.TestCase):
    corpus = None

    def setUp(self):
        self.directory = Path(
            self.enterContext(
                # enterContext owns cleanup even when setUp fails.
                tempfile.TemporaryDirectory(prefix="vimbadmin-ast-controls-")  # pylint: disable=consider-using-with
            )
        )
        self.project = self.directory / "project"
        self.project.mkdir()
        subprocess.run(["git", "init", "--quiet", str(self.project)], check=True)

    def fixture(self, source, name="fixture.php"):
        path = self.project / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(source)
        subprocess.run(["git", "-C", str(self.project), "add", "--", name], check=True)

    def scan(self, **arguments):
        return SCAN.scan(
            self.project, self.corpus, self.directory / "report", **arguments
        )

    def test_clean_input_has_real_coverage_and_no_candidates(self):
        self.fixture("<?php echo 1;\n")
        report = self.scan()
        self.assertEqual(report["input_count"], 1)
        self.assertGreater(report["effective_rule_count"], 0)
        self.assertEqual(report["finding_count"], 0)
        self.assertEqual(report["status"], "no-candidates")

    def test_injected_execution_sink_is_reported_without_source_values(self):
        self.fixture("<?php system($_GET['command']);\n", ".github/control.phtml")
        report = self.scan()
        self.assertEqual(report["input_count"], 1)
        self.assertIn("php-exec-sink", {row["ruleId"] for row in report["findings"]})
        for row in report["findings"]:
            self.assertEqual(set(row), {"ruleId", "file", "range", "severity"})
            self.assertEqual(row["file"], ".github/control.phtml")

    def test_source_sentinel_is_absent_from_exported_metadata(self):
        sentinel = "synthetic-artifact-boundary-value-83076"
        self.fixture('<?php system("' + sentinel + '");\n')
        report = self.scan()
        self.assertIn("php-exec-sink", {row["ruleId"] for row in report["findings"]})
        self.assertNotIn(sentinel, json.dumps(report))
        exported = (self.directory / "report/report.json").read_text()
        self.assertNotIn(sentinel, exported)
        for row in report["findings"]:
            self.assertEqual(set(row), {"ruleId", "file", "range", "severity"})

    def test_missing_engine_is_an_error(self):
        self.fixture("<?php echo 1;\n")
        with self.assertRaises(OSError):
            self.scan(engine=self.directory / "missing-engine")
        self.assertFalse((self.directory / "report").exists())

    def test_wrong_engine_checksum_is_an_error(self):
        self.fixture("<?php echo 1;\n")
        with (
            patch.object(SCAN, "ENGINE_SHA256", "0" * 64),
            self.assertRaisesRegex(ValueError, "engine checksum"),
        ):
            self.scan()
        self.assertFalse((self.directory / "report").exists())

    def test_engine_configuration_failure_is_an_error(self):
        self.fixture("<?php echo 1;\n")
        wrapper = self.directory / "broken-config-engine"
        binary = self.corpus / "node_modules/.bin/ast-grep"
        wrapper.write_text(
            '#!/bin/sh\nif [ "$1" = --version ]; then exec "$REAL_ENGINE" --version; fi\n'
            'exec "$REAL_ENGINE" scan --config /nonexistent-vimbadmin-scan-config\n'
        )
        wrapper.chmod(0o700)
        # The wrapper uses an environment variable so paths never become shell code.
        with (
            patch.dict(os.environ, REAL_ENGINE=str(binary)),
            self.assertRaisesRegex(ValueError, "execution failed"),
        ):
            self.scan(engine=wrapper)
        self.assertFalse((self.directory / "report").exists())

    def test_wrong_engine_version_is_an_error(self):
        self.fixture("<?php echo 1;\n")
        wrapper = self.directory / "wrong-engine"
        wrapper.write_text("#!/bin/sh\nprintf 'ast-grep 0.0.0\\n'\n")
        wrapper.chmod(0o700)
        with self.assertRaisesRegex(ValueError, "engine version"):
            self.scan(engine=wrapper)

    def test_incomplete_input_or_rule_coverage_cannot_report_success(self):
        self.fixture("<?php echo 1;\n")
        wrapper = self.directory / "incomplete-engine"
        for scanned, rules in ((0, 1), (1, 0), (2, 1)):
            wrapper.write_text(
                '#!/bin/sh\nif [ "$1" = --version ]; then '
                "printf 'ast-grep 0.51.0\\n'; exit 0; fi\n"
                "printf '[]\\n'\n"
                f"printf 'scannedFileCount={scanned}\\neffectiveRuleCount={rules}\\n' >&2\n"
            )
            wrapper.chmod(0o700)
            with self.assertRaisesRegex(
                ValueError, "every selected input|no effective rules"
            ):
                self.scan(engine=wrapper)
            self.assertFalse((self.directory / "report").exists())

    def test_each_advertised_format_has_real_rule_coverage(self):
        fixtures = {
            "a.php": ("<?php system($_GET['command']);\n", "php-exec-sink"),
            "a.phtml": ("<?php system($_GET['command']);\n", "php-exec-sink"),
            "a.sh": ('#!/usr/bin/env bash\neval "$input"\n', "sh-eval-expansion"),
            "a.js": ("eval(input);\n", "js-dynamic-code-execution"),
            "a.jsx": (
                "const node = <div>{eval(input)}</div>;\n",
                "js-dynamic-code-execution",
            ),
            "a.ts": ("exec(`ls ${input}`);\n", "ts-child-process-exec-template"),
            "a.py": ("eval(user_input)\n", "py-eval-exec"),
            "a.html": (
                '<script src="https://example.invalid/app.js"></script>\n',
                "coraza-external-script-missing-sri-html",
            ),
        }
        for name, (source, _) in fixtures.items():
            self.fixture(source, name)
        report = self.scan()
        self.assertEqual(report["input_count"], len(fixtures))
        for name, (_, rule) in fixtures.items():
            observed = {
                row["ruleId"] for row in report["findings"] if row["file"] == name
            }
            self.assertIn(rule, observed, name)

    def test_tsx_requires_native_rule_coverage(self):
        self.fixture("const node = <div>{exec(`ls ${input}`)}</div>;\n", "a.tsx")
        with self.assertRaisesRegex(ValueError, "TSX requires native TSX rules"):
            self.scan()
        self.assertFalse((self.directory / "report").exists())

    def test_empty_input_cannot_report_success(self):
        with self.assertRaisesRegex(ValueError, "no tracked applicable"):
            self.scan()
        self.assertFalse((self.directory / "report").exists())


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--corpus", type=Path, required=True)
    options = parser.parse_args()
    StructuralScan.corpus = options.corpus.resolve()
    unittest.main(argv=[__file__])
