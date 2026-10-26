#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd "$(dirname "$0")/.." && pwd)
cd "$repo_root"

# Keep entrypoint coverage in the existing CI taint-contract gate.
bash ci/test-psalm-entrypoint-contract.sh

generator=$PWD/tests/regenerate-psalm-taint-baseline.sh
source_file=$PWD/library/ViMbAdmin/BruteForce.php
backup=$(mktemp "${TMPDIR:-/tmp}/vimbadmin-bruteforce.XXXXXX.php")
output=$(mktemp "${TMPDIR:-/tmp}/vimbadmin-psalm-contract.XXXXXX.log")
readonly dollar='$'
readonly probe="${dollar}_GET[\"psalm_taint_contract\"]"
cleanup() {
  cp -- "$backup" "$source_file"
  rm -f -- "$backup" "$output"
}
trap cleanup EXIT
cp -- "$source_file" "$backup"

if grep -qF '<file name="library/ViMbAdmin/BruteForce.php"' psalm.xml; then
  echo 'Psalm config must not suppress BruteForce findings before baseline generation.' >&2
  exit 1
fi

# Plant a distinct request-to-file sink immediately before the class closes.
# The baseline check must observe it, while the existing hash-derived filename
# findings remain represented by their exact generated baseline entries.
sed -i '$i\
    public function psalmTaintContractProbe(): void\
    {\
        file_get_contents($_GET["psalm_taint_contract"]);\
    }\
' "$source_file"

if bash "$generator" --check >"$output" 2>&1; then
  echo 'Psalm baseline accepted a new BruteForce file sink.' >&2
  exit 1
fi
grep -qF 'Psalm taint baseline drift' "$output" || {
  cat "$output" >&2
  exit 1
}
grep -qF "$probe" "$output" || {
  cat "$output" >&2
  exit 1
}

cp -- "$backup" "$source_file"
bash "$generator" --check >/dev/null

echo 'Psalm taint contract rejects a new BruteForce file sink.'
