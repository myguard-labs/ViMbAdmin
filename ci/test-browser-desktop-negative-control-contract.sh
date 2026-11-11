#!/usr/bin/env bash
# Exercise the real fixture matcher and desktop call without browser services.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
fixture=${1:-$root/tests/support/browser-application-fixture.sh}
matcher=$(awk '/^expect_red\(\) \{$/ { copying=1 } copying { print } copying && /^}$/ { exit }' "$fixture")
desktop_call=$(awk '$1 == "expect_red" && $3 == "dark-hidden-desktop-action" { print; count++ } END { if (count != 1) exit 1 }' "$fixture")
[[ -n $matcher ]] || {
  echo 'FAIL: real expect_red matcher missing' >&2
  exit 1
}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/logs"

# Only the browser process is replaced: matching and its expected operand are
# extracted verbatim from the production fixture and executed together.
# Variables and run_browser are consumed by the extracted code through eval.
# shellcheck disable=SC2034,SC2317
run_case() {
  local name=$1 status=$2 diagnostic=$3 expected=$4 observed=0
  printf '%s\n' "$diagnostic" >"$work/browser.log"
  (
    engine=contract
    app_origin=http://fixture.invalid
    fixture_root=$work
    evidence_dir=$work
    run_browser() {
      BROWSER_STATUS=$status
      BROWSER_LOG=$work/browser.log
    }
    eval "$matcher"
    eval "$desktop_call"
  ) >"$work/logs/$name.log" 2>&1 || observed=$?
  if [[ $observed != "$expected" ]]; then
    printf 'FAIL: %s expected matcher exit %s, got %s\n' "$name" "$expected" "$observed" >&2
    cat "$work/logs/$name.log" >&2
    exit 1
  fi
  printf 'PASS: %s\n' "$name"
}

run_case desktop-1440 1 'AssertionError: admins: primary action visible at 1440' 0
run_case tablet-768 1 'AssertionError: admins: primary action visible at 768' 1
run_case mobile-375 1 'AssertionError: admins: primary action visible at 375' 1
run_case unrelated 1 'AssertionError: navigation toggle is visible at 1440' 1
run_case missing-width 1 'AssertionError: admins: primary action visible' 1
run_case malformed-width 1 'AssertionError: admins: primary action visible at desktop' 1
run_case empty-log 1 '' 1
run_case success-status 0 'AssertionError: admins: primary action visible at 1440' 1
run_case error-status 2 'AssertionError: admins: primary action visible at 1440' 1
run_case malformed-status invalid 'AssertionError: admins: primary action visible at 1440' 1
printf 'PASS: desktop negative-control contract\n'
