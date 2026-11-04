"""Scan tracked project sources with the immutable structural rule corpus.

Usage: python3 ci/run-ast-grep.py --corpus CHECKOUT --output NEW_DIRECTORY
Requires Git, Python 3 and the corpus's locked npm installation. Copies tracked
regular sources into a temporary snapshot; never edits the project or corpus.
Writes complete finding locations, input/rule hashes and discovery counts.
Findings are review candidates; tool/configuration/coverage errors fail the job.
Source snippets and metavariable values are deliberately absent from artifacts.
Extend LANGUAGES/SUFFIXES together when adding a project source language.
"""

import argparse
import hashlib
import json
import re
import shutil
import subprocess
import tempfile
from pathlib import Path

CORPUS_REVISION = "9025c0dd887d7a6797352b6d7ab5db6ba58d4603"
ENGINE_VERSION = "ast-grep 0.51.0"
ENGINE_SHA256 = "99b5aeab1f47536789482b4517595b759de910f7f1f1d1f80c752824e894e38e"
LANGUAGES = ("bash", "html", "javascript", "php", "python", "typescript")
SUFFIXES = {
    ".sh",
    ".bash",
    ".html",
    ".htm",
    ".js",
    ".cjs",
    ".mjs",
    ".php",
    ".phtml",
    ".py",
    ".ts",
    ".jsx",
}


def command(arguments, cwd):
    return subprocess.run(arguments, cwd=cwd, capture_output=True, check=False)


def git(arguments, cwd):
    result = command(["git", *arguments], cwd)
    if result.returncode:
        raise ValueError("Git could not verify the scan inputs")
    return result.stdout


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def scan(project, corpus, output, engine=None):
    project, corpus, output = project.resolve(), corpus.resolve(), output.resolve()
    if output.exists():
        raise ValueError("output must be a new directory")
    if git(["rev-parse", "HEAD"], corpus).decode().strip() != CORPUS_REVISION:
        raise ValueError("unexpected corpus revision")
    git(
        [
            "diff",
            "--exit-code",
            "HEAD",
            "--",
            "rules",
            "utils",
            "package.json",
            "package-lock.json",
        ],
        corpus,
    )
    if git(
        ["ls-files", "--others", "--exclude-standard", "--", "rules", "utils"], corpus
    ):
        raise ValueError("untracked corpus rules are not allowed")
    if engine is None:
        engine = corpus / "node_modules/@ast-grep/cli-linux-x64-gnu/ast-grep"
        if digest(engine) != ENGINE_SHA256:
            raise ValueError("unexpected ast-grep engine checksum")
    version = command([str(engine), "--version"], project)
    if version.returncode or version.stdout.decode().strip() != ENGINE_VERSION:
        raise ValueError("unexpected ast-grep engine version")

    rules = []
    for language in LANGUAGES:
        directory = corpus / "rules" / language
        files = sorted(directory.rglob("*.yml"))
        if not files:
            raise ValueError(f"missing {language} rule coverage")
        rules.extend(
            {"path": str(path.relative_to(corpus)), "sha256": digest(path)}
            for path in files
        )
    # This pinned corpus has no generic/regex pack. Refuse a new one being
    # silently omitted when updating the immutable revision above.
    for name in ("generic", "regex"):
        if (corpus / "rules" / name).exists():
            raise ValueError(f"unmapped cross-language rule directory: {name}")

    inputs = []
    with tempfile.TemporaryDirectory(prefix="vimbadmin-ast-grep-") as scratch:
        snapshot = Path(scratch) / "sources"
        snapshot.mkdir()
        for encoded in git(["ls-files", "-z"], project).split(b"\0"):
            if not encoded:
                continue
            relative = Path(encoded.decode())
            source = project / relative
            if source.suffix.lower() == ".tsx":
                raise ValueError(
                    "TSX requires native TSX rules; the pinned corpus has only TypeScript rules"
                )
            if source.suffix.lower() not in SUFFIXES:
                continue
            if (
                source.is_symlink()
                or not source.is_file()
                or not source.resolve().is_relative_to(project)
            ):
                raise ValueError("selected tracked source is not a regular file")
            target = snapshot / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, target)
            inputs.append({"path": str(relative), "sha256": digest(target)})
        if not inputs:
            raise ValueError("no tracked applicable source inputs")
        config = Path(scratch) / "sgconfig.yml"
        # JSON is valid YAML, and quotes all filesystem paths unambiguously.
        config.write_text(
            json.dumps(
                {
                    "ruleDirs": [str(corpus / "rules" / name) for name in LANGUAGES],
                    "utilDirs": [str(corpus / "utils")],
                    "languageGlobs": {"php": ["*.php", "*.phtml"]},
                }
            )
        )
        arguments = [
            str(engine),
            "scan",
            "--config",
            str(config),
            "--json=compact",
            "--inspect=summary",
            "--color=never",
        ]
        for category in ("hidden", "dot", "exclude", "global", "parent", "vcs"):
            arguments.extend(["--no-ignore", category])
        result = command([*arguments, "."], snapshot)
        if result.returncode not in (0, 1):
            raise ValueError(f"ast-grep execution failed (exit {result.returncode})")
        findings = json.loads(result.stdout)
        if not isinstance(findings, list) or (result.returncode == 1 and not findings):
            raise ValueError("invalid ast-grep findings output")
        inspection = result.stderr.decode()
        scanned = re.search(r"scannedFileCount=(\d+)", inspection)
        effective = re.search(r"effectiveRuleCount=(\d+)", inspection)
        if not scanned or int(scanned[1]) != len(inputs):
            raise ValueError("ast-grep did not scan every selected input")
        if not effective or int(effective[1]) == 0:
            raise ValueError("ast-grep loaded no effective rules")
        locations = []
        known_inputs = {entry["path"] for entry in inputs}
        for finding in findings:
            path = Path(finding["file"])
            if path.is_absolute():
                path = path.relative_to(snapshot)
            if str(path) not in known_inputs:
                raise ValueError("finding outside selected source snapshot")
            locations.append(
                {
                    "ruleId": finding["ruleId"],
                    "file": str(path),
                    "range": finding["range"],
                    "severity": finding["severity"],
                }
            )
        output.mkdir(parents=True)
        report = {
            "corpus_revision": CORPUS_REVISION,
            "engine_version": ENGINE_VERSION,
            "engine_sha256": ENGINE_SHA256,
            "npm_lock_sha256": digest(corpus / "package-lock.json"),
            "status": "candidates" if locations else "no-candidates",
            "input_count": len(inputs),
            "effective_rule_count": int(effective[1]),
            "rule_file_count": len(rules),
            "finding_count": len(locations),
            "inputs": inputs,
            "rules": rules,
            "findings": locations,
        }
        (output / "report.json").write_text(json.dumps(report, indent=2) + "\n")
        print(
            f"ast-grep scanned {len(inputs)} files with {int(effective[1])} rules; "
            f"{len(locations)} review candidates (see report.json)"
        )
        return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--corpus", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    arguments = parser.parse_args()
    try:
        scan(Path(__file__).resolve().parents[1], arguments.corpus, arguments.output)
    except (OSError, ValueError, KeyError, TypeError) as error:
        parser.exit(1, f"Structural scan failed: {error}\n")


if __name__ == "__main__":
    main()
