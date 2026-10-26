#!/usr/bin/env bash
# Exercise the shipped root selection with real Psalm and isolated source sinks.
# shellcheck disable=SC2016 # PHP source must remain literal in shell strings.
set -euo pipefail
repo_root=$(cd "$(dirname "$0")/.." && pwd -P)
php=${PHP_BINARY:-php}
if [[ -n ${PSALM_BIN:-} ]]; then
  psalm=$PSALM_BIN
elif [[ -x $repo_root/vendor/bin/psalm ]]; then
  psalm=$repo_root/vendor/bin/psalm
else
  psalm=$(command -v psalm)
fi
if [[ $psalm == */* && $psalm != /* ]]; then
  psalm=$repo_root/$psalm
fi
work=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-psalm-entrypoints.XXXXXX")
trap 'rm -rf -- "${work:?}"' EXIT
mkdir -p "$work"/{src,library,application,public,bin/nested,tools/nested,tests,vendor}
# Psalm requires an autoloader even for a dependency-free source fixture.
printf '<?php\n' >"$work/vendor/autoload.php"
"$php" -r '
    $doc = new DOMDocument();
    if (!$doc->load($argv[1])) exit(1);
    $doc->documentElement->removeAttribute("errorBaseline");
    $doc->save($argv[2]);
' "$repo_root/psalm.xml" "$work/psalm.xml"

roots=('public/index.php' 'bin/nested/probe.php' 'tools/nested/probe.php' 'preload.php')
for path in "${roots[@]}"; do
  # Distinct source keys attribute every finding to its own entrypoint domain.
  printf '<?php\nfile_get_contents($_GET["%s"]);\n' "$path" >"$work/$path"
done
# Trusted fixed paths are safe, while sibling public files and test doubles
# remain outside the project roots. Including tests would redeclare QueueRunner.
printf '<?php\nclass ViMbAdmin_Service_QueueRunner {}\nfile_get_contents(__FILE__);\n' >"$work/src/Safe.php"
printf '<?php\nclass ViMbAdmin_Service_QueueRunner {}\nfile_get_contents($_GET["excluded_test"]);\n' >"$work/tests/test-kernel-cli.php"
printf '<?php\nfile_get_contents($_GET["excluded_public"]);\n' >"$work/public/unrelated.php"

analyse() {
  local config=$1 status=0
  local mode=${2:---taint-analysis}
  (cd "$work" && "$psalm" --config="$config" --no-progress --no-cache \
    --threads=1 "$mode" --output-format=json) >"$work/result.json" 2>"$work/psalm.err" || status=$?
  if [[ $status != 0 && $status != 2 ]]; then
    cat "$work/psalm.err" "$work/result.json" >&2
    echo "Psalm entrypoint analysis failed with status $status." >&2
    return 1
  fi
}
assert_sinks() {
  "$php" -r '
    $issues = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $actual = [];
    foreach ($issues as $issue) {
        if ($issue["type"] !== "TaintedFile") {
            fwrite(STDERR, "Unexpected Psalm issue: " . $issue["type"] . "\n"); exit(1);
        }
        $actual[] = $issue["file_name"];
    }
    $expected = array_slice($argv, 2);
    sort($actual); sort($expected);
    if ($actual !== $expected) {
        fwrite(STDERR, "Psalm entrypoint sinks differ: " . json_encode([$expected, $actual]) . "\n"); exit(1);
    }
  ' "$work/result.json" "$@"
}
analyse "$work/psalm.xml"
assert_sinks "${roots[@]}"
echo 'Psalm detects source-to-file sinks in all four entrypoint domains.'

# Each removed root must fail the same oracle used for the shipped config.
for index in "${!roots[@]}"; do
  "$php" -r '
    $doc = new DOMDocument(); $doc->load($argv[1]);
    $xpath = new DOMXPath($doc); $xpath->registerNamespace("p", "https://getpsalm.org/schema/config");
    $names = ["public/index.php", "bin", "tools", "preload.php"];
    foreach ($xpath->query("/p:psalm/p:projectFiles/*") as $node) {
        if ($node->getAttribute("name") === $names[(int)$argv[3]]) $node->parentNode->removeChild($node);
    }
    $doc->save($argv[2]);
  ' "$work/psalm.xml" "$work/omitted.xml" "$index"
  analyse "$work/omitted.xml"
  if assert_sinks "${roots[@]}" >"$work/control.log" 2>&1; then
    echo "Removed root survived: ${roots[$index]}" >&2
    exit 1
  fi
  grep -qF 'Psalm entrypoint sinks differ:' "$work/control.log"
  expected=("${roots[@]}")
  unset 'expected[index]'
  assert_sinks "${expected[@]}"
  echo "Negative control detects removed root: ${roots[$index]}"
done
# Restore the original config after the seeded root omissions.
analyse "$work/psalm.xml"
assert_sinks "${roots[@]}"

# A malformed entrypoint cannot be mistaken for a clean taint result.
printf '<?php function {\n' >"$work/tools/nested/probe.php"
# Psalm taint-only mode filters non-taint diagnostics; use its parser/type mode
# for the malformed-source control, without changing the shipped analysis gate.
analyse "$work/psalm.xml" --show-info=false
"$php" -r '
    $issues = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    foreach ($issues as $issue) {
        if ($issue["type"] === "ParseError" && $issue["file_name"] === "tools/nested/probe.php") exit(0);
    }
    fwrite(STDERR, "Malformed entrypoint was not reported as ParseError.\n"); exit(1);
' "$work/result.json"
printf '<psalm>\n' >"$work/broken.xml"
if analyse "$work/broken.xml" >"$work/config-error.log" 2>&1; then
  echo 'Malformed Psalm config was accepted.' >&2
  exit 1
fi
grep -qiE 'XML|config' "$work/config-error.log"
echo 'Psalm rejects malformed entrypoints and configuration; test doubles and public siblings stay excluded.'
