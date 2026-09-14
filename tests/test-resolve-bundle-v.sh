#!/usr/bin/env bash

set -euo pipefail
shopt -s inherit_errexit

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
helper="$repo_root/tests/support/resolve-bundle-v.sh"
tmp=$(mktemp -d /tmp/vimbadmin-resolve-bundle-v.XXXXXX)
trap 'rm -rf "$tmp"' EXIT

make_fixture() {
  local name=$1
  local fixture="$tmp/$name"
  mkdir -p "$fixture/tests/support" "$fixture/public/js"
  cp "$helper" "$fixture/tests/support/resolve-bundle-v.sh"
  printf '%s\n' "$fixture"
}

run_exact_case() {
  local state=$1
  local fixture
  fixture=$(make_fixture "exact-$state")
  touch "$fixture/public/js/min.bundle-v29.js"

  local output status
  set +e
  output=$(bash -e -O inherit_errexit -c '
    state=$1
    fixture=$2
    shopt "-$state" nullglob
    bundle=$(source "$fixture/tests/support/resolve-bundle-v.sh" && resolve_bundle_v)
    [[ "$bundle" == min.bundle-v29.js ]]
    if shopt -q nullglob; then
      [[ "$state" == s ]]
    else
      [[ "$state" == u ]]
    fi
  ' _ "$state" "$fixture" 2>&1)
  status=$?
  set -e
  if [[ $status -ne 0 ]]; then
    echo "FAIL: inherited-errexit exact bundle with nullglob $([[ "$state" == s ]] && echo on || echo off) (status $status): $output" >&2
    return 1
  fi
  echo "OK: inherited-errexit exact bundle with nullglob $([[ "$state" == s ]] && echo on || echo off)"
}

run_failure_case() {
  local name=$1
  local expected=$2
  shift 2
  local fixture output status
  fixture=$(make_fixture "$name")
  for bundle in "$@"; do
    touch "$fixture/public/js/$bundle"
  done

  set +e
  output=$(bash -e -O inherit_errexit -c \
    'source "$1/tests/support/resolve-bundle-v.sh"; resolve_bundle_v' _ "$fixture" 2>&1)
  status=$?
  set -e
  [[ $status -eq 2 ]]
  [[ "$output" == *"$expected"* ]]
  echo "OK: $name returns 2 with its diagnostic"
}

case ${1:-all} in
  nullglob-off-negative-control)
    run_exact_case u
    ;;
  all)
    run_exact_case u
    run_exact_case s
    run_failure_case missing 'no JS bundle found'
    run_failure_case multiple 'multiple matches found' \
      min.bundle-v28.js min.bundle-v29.js
    ;;
  *)
    echo "Unknown test case: $1" >&2
    exit 2
    ;;
esac
