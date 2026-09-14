#!/usr/bin/env bash

set -Eeuo pipefail
shopt -s inherit_errexit

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
helper="$repo_root/tests/support/resolve-bundle-v.sh"
tmp=$(mktemp -d /tmp/vimbadmin-resolve-bundle-v.XXXXXX)
trap 'rm -rf "$tmp"' EXIT

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

assert_status() {
  local actual=$1 expected=$2 label=$3
  [[ $actual -eq $expected ]] ||
    fail "$label returned $actual, expected $expected"
}

assert_contains() {
  local actual=$1 expected=$2 label=$3
  [[ $actual == *"$expected"* ]] ||
    fail "$label output did not contain: $expected"
}

assert_nullglob_state() {
  local expected=$1 label=$2
  if shopt -q nullglob; then
    [[ $expected == on ]] || fail "$label left nullglob on, expected off"
  else
    [[ $expected == off ]] || fail "$label left nullglob off, expected on"
  fi
}

make_fixture() {
  local name=$1
  local fixture="$tmp/$name"
  mkdir -p "$fixture/tests/support" "$fixture/public/js"
  cp "$helper" "$fixture/tests/support/resolve-bundle-v.sh"
  printf '%s\n' "$fixture"
}

run_case() {
  local outcome=$1 initial_state=$2 fixture output_file output status
  fixture=$(make_fixture "$outcome-$initial_state")
  output_file="$fixture/output"

  case $outcome in
  exact)
    touch "$fixture/public/js/min.bundle-v29.js"
    ;;
  missing)
    ;;
  multiple)
    touch "$fixture/public/js/min.bundle-v28.js" \
      "$fixture/public/js/min.bundle-v29.js"
    ;;
  *)
    fail "unknown fixture outcome: $outcome"
    ;;
  esac

  source "$fixture/tests/support/resolve-bundle-v.sh"
  if [[ $initial_state == on ]]; then
    shopt -s nullglob
  else
    shopt -u nullglob
  fi

  if [[ $outcome == exact ]]; then
    trap 'fail "exact bundle resolution failed under inherited errexit"' ERR
    resolve_bundle_v >"$output_file"
    trap - ERR
    status=0
  else
    set +e
    resolve_bundle_v >"$output_file" 2>&1
    status=$?
    set -e
  fi
  output=$(<"$output_file")

  case $outcome in
  exact)
    assert_status "$status" 0 "$outcome/$initial_state"
    [[ $output == min.bundle-v29.js ]] ||
      fail "$outcome/$initial_state resolved '$output'"
    ;;
  missing)
    assert_status "$status" 2 "$outcome/$initial_state"
    assert_contains "$output" 'no JS bundle found' "$outcome/$initial_state"
    ;;
  multiple)
    assert_status "$status" 2 "$outcome/$initial_state"
    assert_contains "$output" 'multiple matches found' "$outcome/$initial_state"
    assert_contains "$output" 'min.bundle-v28.js' "$outcome/$initial_state"
    assert_contains "$output" 'min.bundle-v29.js' "$outcome/$initial_state"
    ;;
  esac

  assert_nullglob_state "$initial_state" "$outcome/$initial_state"
  printf 'OK: direct %s call restores nullglob %s\n' "$outcome" "$initial_state"
}

case ${1:-all} in
nullglob-off-negative-control)
  run_case exact off
  ;;
all)
  for outcome in exact missing multiple; do
    run_case "$outcome" off
    run_case "$outcome" on
  done
  ;;
*)
  fail "unknown test case: $1"
  ;;
esac
