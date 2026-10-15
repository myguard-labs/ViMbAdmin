#!/usr/bin/env python3
"""Tracked-only clone baseline. Detailed, reproducible reports belong outside Git."""

import argparse
import hashlib
import json
import shutil
import subprocess
import sys
import tempfile
from collections import Counter
from pathlib import Path

ROOTS = ("src", "application", "library")
OPTIONS = [
    "--format",
    "php",
    "--mode",
    "weak",
    "--min-lines",
    "8",
    "--min-tokens",
    "70",
    "--ignore",
    "**/Proxies/**",
    "--reporters",
    "json",
    "--no-colors",
]
ARTIFACTS = {
    "ci/refactor-metrics/baseline.json",
    "ci/refactor-metrics/tracked-manifest.tsv",
}
CLASSES = {"first-party", "mapping", "vendor", "generated", "attribution"}


def run(argv, cwd=None, data=None):
    return subprocess.run(
        argv, cwd=cwd, input=data, text=True, check=True, capture_output=True
    ).stdout


def digest(data):
    return hashlib.sha256(data).hexdigest()


def encoded(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":")).encode()


def unique_object(pairs):
    value = {}
    for key, item in pairs:
        if key in value:
            raise ValueError(f"duplicate JSON key: {key}")
        value[key] = item
    return value


def load(path):
    return json.loads(path.read_text(), object_pairs_hook=unique_object)


def save(path, value):
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n")


def tracked(repo, others=False):
    args = ["git", "ls-files", "-z", "--cached"]
    if others:
        args += ["--others", "--exclude-standard"]
    return sorted(set(run(args, repo).rstrip("\0").split("\0")) - {""})


def file_entry(repo, path):
    source = repo / path
    if source.is_symlink() or not source.is_file():
        raise ValueError(f"input must be a present regular file: {path}")
    return {
        "path": path,
        "sha256": digest(source.read_bytes()),
        "mode": "100755" if source.stat().st_mode & 0o111 else "100644",
    }


def version():
    actual = run(["jscpd", "--version"]).strip()
    if actual not in ("cpd 5.0.12", "jscpd 5.0.12", "5.0.12"):
        raise ValueError(f"require jscpd 5.0.12, got {actual!r}")
    return actual


def php_lines(snapshot, paths):
    # PHP's lexer, rather than a comment regex, preserves comment-like strings.
    code = r"""
$paths = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$out = [];
foreach ($paths as $path) {
    $line = 1; $lines = [];
    foreach (token_get_all(file_get_contents($path)) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $skip = is_array($token) && in_array($token[0],
            [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_OPEN_TAG,T_CLOSE_TAG], true);
        foreach (explode("\n", $text) as $offset => $part) {
            if (!$skip && trim($part) !== '') { $lines[$line + $offset] = true; }
        }
        $line += substr_count($text, "\n");
    }
    $out[$path] = array_keys($lines);
}
echo json_encode($out, JSON_THROW_ON_ERROR);
"""
    return json.loads(run(["php", "-r", code], snapshot, json.dumps(paths)))


def capture(repo, output):
    repo = repo.resolve()
    output = output.resolve()
    tool_version = version()
    if output.exists():
        raise ValueError(f"output must be a new directory: {output}")
    if output == repo or repo in output.parents:
        raise ValueError(f"output must be outside the checkout: {output}")
    entries = [file_entry(repo, p) for p in tracked(repo) if p.split("/")[0] in ROOTS]
    if not entries:
        raise ValueError("tracked source roots are empty")
    output.mkdir(parents=True)
    # A clean tracked-only snapshot also prevents local config/ignore drift.
    with tempfile.TemporaryDirectory(prefix="vimbadmin-metrics-") as scratch:
        snapshot = Path(scratch)
        for entry in entries:
            target = snapshot / entry["path"]
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(repo / entry["path"], target)
        for source in ROOTS:
            (snapshot / source).mkdir(exist_ok=True)
        command = ["jscpd", *OPTIONS, "--output", str(output), *ROOTS]
        log = run(command, snapshot)
        (output / "jscpd.log").write_text(log)
        raw = json.loads((output / "jscpd-report.json").read_text())
        eligible = [
            e["path"]
            for e in entries
            if "/Proxies/" not in e["path"]
            and Path(e["path"]).suffix in (".php", ".phtml")
        ]
        token_lines = php_lines(snapshot, eligible)
        clones = []
        for item in raw["duplicates"]:
            sites = []
            for key in ("firstFile", "secondFile"):
                fragment = item[key]
                # cpd 5 strips each root from names; fail on ambiguous names.
                name = fragment["name"].replace("\\", "/")
                matches = [
                    p
                    for p in eligible
                    if p == name or any(p == f"{r}/{name}" for r in ROOTS)
                ]
                if len(matches) != 1:
                    raise ValueError(f"ambiguous/untracked report path: {name}")
                path = matches[0]
                start, end = fragment["start"], fragment["end"]
                lines = (snapshot / path).read_bytes().splitlines(keepends=True)
                if not 1 <= start <= end <= len(lines):
                    raise ValueError(f"invalid clone range: {path}:{start}-{end}")
                sites.append(
                    {
                        "path": path,
                        "start": start,
                        "end": end,
                        "sha256": digest(b"".join(lines[start - 1 : end])),
                        "code_lines": [
                            n for n in token_lines[path] if start <= n <= end
                        ],
                    }
                )
            sites.sort(key=lambda s: (s["path"], s["start"], s["end"]))
            identity = [
                {k: s[k] for k in ("path", "start", "end", "sha256")} for s in sites
            ]
            clones.append(
                {
                    "id": digest(encoded(identity))[:16],
                    "sites": sites,
                    "reported_lines": item["lines"],
                    "reported_tokens": item["tokens"],
                }
            )
    clones.sort(key=lambda c: c["id"])
    if len({c["id"] for c in clones}) != len(clones):
        raise ValueError("duplicate clone identity")
    history = run(
        [
            "git",
            "log",
            "-100",
            "--no-merges",
            "--format=commit:%H",
            "--name-only",
            "--",
            *ROOTS,
        ],
        repo,
    )
    (output / "history.txt").write_text(history)
    changes = []
    for block in history.split("commit:")[1:]:
        lines = block.splitlines()
        changes.append({"commit": lines[0], "paths": sorted(set(lines[1:]) - {""})})
    manifest = "".join(f"{e['mode']}\t{e['sha256']}\t{e['path']}\n" for e in entries)
    (output / "tracked-manifest.tsv").write_text(manifest)
    candidate = [
        file_entry(repo, p) for p in tracked(repo, others=True) if p not in ARTIFACTS
    ]
    # Hash the launcher and installed package dataset, including native binaries.
    launcher = Path(shutil.which("jscpd")).resolve()
    package = launcher.parent
    tool_files = [launcher]
    if (package / "package.json").exists():
        # npm may hoist native optional packages beside the launcher package.
        roots = [package, *sorted(package.parent.glob("jscpd-*"))]
        tool_files = sorted(
            {p for root in roots for p in root.rglob("*") if p.is_file()}
        )
    tool_manifest = [
        {"path": str(p.relative_to(package.parent)), "sha256": digest(p.read_bytes())}
        for p in tool_files
    ]
    save(output / "tool-manifest.json", tool_manifest)
    baseline = {
        "schema": 1,
        "commit": run(["git", "rev-parse", "HEAD"], repo).strip(),
        "tree": run(["git", "rev-parse", "HEAD^{tree}"], repo).strip(),
        "tracked_working_candidate_sha256": digest(encoded(candidate)),
        "candidate_manifest": "candidate-manifest.json",
        "candidate_fingerprint_exclusions": sorted(ARTIFACTS),
        "source_roots": ROOTS,
        "exclusions": ["**/Proxies/**"],
        "input_policy": "current bytes of tracked regular files only; no licenses removed",
        "tracked_source_files": len(entries),
        "token_bearing_source_lines": {
            "first_party_including_mapping": sum(
                len(lines)
                for path, lines in token_lines.items()
                if not path.startswith("library/OSS/")
            ),
            "attributed_oss_vendor": sum(
                len(lines)
                for path, lines in token_lines.items()
                if path.startswith("library/OSS/")
            ),
            "generated": 0,
        },
        "tracked_manifest_sha256": digest(manifest.encode()),
        "scan_input_sha256": digest(
            encoded([e for e in entries if e["path"] in eligible])
        ),
        "tools": {
            "jscpd": tool_version,
            "php": run(["php", "-r", "echo PHP_VERSION;"]).strip(),
            "python": sys.version.split()[0],
            "git": run(["git", "--version"]).strip(),
            "node": run(["node", "--version"]).strip(),
            "jscpd_package_sha256": digest(encoded(tool_manifest)),
        },
        "command": ["jscpd", *OPTIONS, "--output", "<new-output-dir>", *ROOTS],
        "history_policy": "last 100 non-merge commits touching roots at recorded HEAD",
        "history_sha256": digest(history.encode()),
        "clone_dataset_sha256": digest(encoded(clones)),
        "statistics": raw["statistics"]["total"],
        "measurement": (
            "code_lines are PHP lexer non-comment/non-whitespace token-bearing "
            "physical lines; includes declarations/braces/template HTML, "
            "not runtime statements"
        ),
    }
    # The raw report's detectionDate is intentionally not a reproducibility key.
    baseline["statistics"].pop("detectionDate", None)
    save(output / "candidate-manifest.json", candidate)
    save(output / "clones.json", {"schema": 1, "clones": clones, "changes": changes})
    save(output / "baseline.json", baseline)
    print(json.dumps(baseline["statistics"], sort_keys=True))


def require(condition, message):
    if not condition:
        raise ValueError(message)


def census(report, classification, output):
    data = load(report)
    policy = load(classification)
    require(
        isinstance(data, dict)
        and data.get("schema") == 1
        and isinstance(data.get("clones"), list)
        and isinstance(data.get("changes"), list),
        "invalid clone report schema",
    )
    require(
        isinstance(policy, dict)
        and policy.get("schema") == 1
        and isinstance(policy.get("groups"), dict)
        and isinstance(policy.get("clones"), dict),
        "invalid classification schema",
    )
    require(
        all(
            isinstance(c, dict) and isinstance(c.get("id"), str) for c in data["clones"]
        ),
        "invalid clone identity",
    )
    ids = [c["id"] for c in data["clones"]]
    require(len(set(ids)) == len(ids), "duplicate clone identity")
    require(
        set(ids) == set(policy["clones"]),
        "classification must cover every clone exactly once",
    )
    groups = policy["groups"]
    for name, group in groups.items():
        require(
            isinstance(name, str) and name and isinstance(group, dict), "invalid group"
        )
        require(
            group.get("class") in CLASSES
            and group.get("disposition") in ("candidate", "retained")
            and isinstance(group.get("reason"), str)
            and len(group["reason"].strip()) >= 20,
            f"invalid class/disposition/reason: {name}",
        )
        require(
            type(group.get("helper_overhead_code_lines")) is int
            and group["helper_overhead_code_lines"] >= 0,
            f"invalid helper overhead: {name}",
        )
        require(
            group["class"] == "first-party" or group["disposition"] == "retained",
            f"non-first-party clone cannot earn savings: {name}",
        )
    counts = Counter(
        {
            f"{kind}:{state}": 0
            for kind, state in [
                ("first-party", "candidate"),
                ("first-party", "retained"),
                ("mapping", "retained"),
                ("vendor", "retained"),
                ("generated", "retained"),
                ("attribution", "retained"),
            ]
        }
    )
    rows = []
    used = set()
    for clone in data["clones"]:
        group = policy["clones"][clone["id"]]
        require(isinstance(group, str) and group in groups, "unknown clone group")
        used.add(group)
        counts[f"{groups[group]['class']}:{groups[group]['disposition']}"] += 1
        require(
            isinstance(clone["sites"], list) and len(clone["sites"]) == 2,
            "clone must have two sites",
        )
        for site in clone["sites"]:
            require(
                isinstance(site, dict)
                and isinstance(site.get("path"), str)
                and not Path(site["path"]).is_absolute()
                and ".." not in Path(site["path"]).parts
                and type(site.get("start")) is int
                and type(site.get("end")) is int
                and 1 <= site["start"] <= site["end"]
                and isinstance(site.get("code_lines"), list)
                and all(
                    type(n) is int and site["start"] <= n <= site["end"]
                    for n in site["code_lines"]
                ),
                "invalid clone site",
            )
    require(used == set(groups), "unused classification group")
    for name, group in groups.items():
        members = [c for c in data["clones"] if policy["clones"][c["id"]] == name]
        # Merge overlapping sites: pairwise clone counts cannot be summed.
        sites = {}
        for clone in members:
            for site in clone["sites"]:
                sites.setdefault(site["path"], []).append(site)
        merged = []
        for path, ranges in sorted(sites.items()):
            for site in sorted(ranges, key=lambda s: s["start"]):
                if (
                    merged
                    and merged[-1]["path"] == path
                    and site["start"] <= merged[-1]["end"]
                ):
                    merged[-1]["end"] = max(merged[-1]["end"], site["end"])
                    merged[-1]["code_lines"] |= set(site["code_lines"])
                else:
                    merged.append(
                        {
                            "path": path,
                            "start": site["start"],
                            "end": site["end"],
                            "code_lines": set(site["code_lines"]),
                        }
                    )
        sizes = [len(s["code_lines"]) for s in merged]
        overhead = group["helper_overhead_code_lines"]
        upper = max(0, sum(sizes) - max(sizes, default=0) - overhead)
        paths = set(sites)
        commits = [c for c in data["changes"] if paths.intersection(c["paths"])]
        cochanges = [c for c in commits if len(paths.intersection(c["paths"])) >= 2]
        rows.append(
            {
                "group": name,
                **group,
                "clone_matches": len(members),
                "token_bearing_lines_union": sum(sizes),
                "unique_sites": len(merged),
                "optimistic_net_code_lines_upper_bound": upper
                if group["disposition"] == "candidate"
                else 0,
                "recent_touch_commits": len(commits),
                "recent_cochange_commits": len(cochanges),
                "cochange_sites_max": max(
                    (len(paths.intersection(c["paths"])) for c in commits), default=0
                ),
                "sites": [
                    {k: v for k, v in s.items() if k != "code_lines"} for s in merged
                ],
            }
        )
    rows.sort(
        key=lambda r: (
            -r["optimistic_net_code_lines_upper_bound"],
            -r["recent_touch_commits"],
            -r["recent_cochange_commits"],
            r["group"],
        )
    )
    save(
        output,
        {
            "schema": 1,
            "clone_dataset_sha256": digest(encoded(data["clones"])),
            "classification_sha256": digest(classification.read_bytes()),
            "counts": dict(sorted(counts.items())),
            "ranked_groups": rows,
            "limits": (
                "Candidates require semantic proof. Upper bounds assume one reusable "
                "implementation, subtract estimated helper overhead, and include "
                "token-bearing declarations/braces. Groups overlap; never sum group "
                "bounds or claim realized savings. No whole-program target chosen."
            ),
        },
    )
    print(json.dumps(dict(sorted(counts.items()))))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    capture_parser = commands.add_parser("capture")
    capture_parser.add_argument("--repo", type=Path, default=Path.cwd())
    capture_parser.add_argument("--output", type=Path, required=True)
    census_parser = commands.add_parser("census")
    census_parser.add_argument("--report", type=Path, required=True)
    census_parser.add_argument("--classification", type=Path, required=True)
    census_parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    try:
        if args.command == "capture":
            capture(args.repo.resolve(), args.output.resolve())
        else:
            census(args.report, args.classification, args.output)
    except (
        ValueError,
        OSError,
        KeyError,
        TypeError,
        subprocess.CalledProcessError,
    ) as error:
        print(f"refactor-metrics: {error}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
