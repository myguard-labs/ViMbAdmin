#!/usr/bin/env bash
set -euo pipefail

# The unit container has Composer dependencies but no database service. Keep
# cache and schema tests in their dedicated jobs and PHPStan harness tests in
# static-analysis, while every other tracked PHP test runs here automatically.
readonly excluded_tests=(
  tests/test-cache-bootstrap.php
  tests/test-kernel-em-factory.php
  tests/test-kernel-smarty-view.php
  tests/test-mailbox-queue-atomic-mariadb.php
  tests/test-oss-message.php
  tests/test-schema-no-pending.php
)

if [[ ${1:-} == --print-excluded-tests ]]; then
  [[ $# -eq 1 ]] || {
    printf 'usage: %s [--print-excluded-tests|tests/test-*.php ...]\n' "$0" >&2
    exit 2
  }
  printf '%s\n' "${excluded_tests[@]}"
  exit 0
fi

is_excluded() {
  local candidate=$1 excluded
  for excluded in "${excluded_tests[@]}"; do
    [[ $candidate == "$excluded" ]] && return 0
  done
  return 1
}

manifest=$(mktemp)
test_output=$(mktemp)
cleanup() {
  rm -f "$manifest" "$test_output"
}
trap cleanup EXIT

# A terminal verdict is required as the LAST non-empty stdout line, or the
# runner treats an exit-0 test as unproven rather than scoring it a pass — a
# test that truncates mid-run (an early exit()/return) still exits 0 but
# leaves no verdict line behind.
#
# These patterns match the shapes the suite actually emits; they are a check
# that a test reached its end, not an assertion about what it proved. A test
# is free to print a misleading verdict, so this is a truncation gate only.
readonly verdict_re_all_passed='^ALL PASSED( \([0-9]+ checks?\))?$'
readonly verdict_re_ok_assertions='^OK: all [A-Za-z0-9][A-Za-z0-9_+ -]{0,120} assertions passed( \(PHP [^)]*\))?$'
verdict_ok() {
  local last_line
  last_line=$(grep -v '^[[:space:]]*$' "$1" | tail -n 1) || true
  [[ $last_line =~ $verdict_re_all_passed ]] && return 0
  [[ $last_line =~ $verdict_re_ok_assertions ]] && return 0
  return 1
}

if ! git ls-files 'tests/test-*.php' | sort >"$manifest"; then
  printf 'Could not enumerate tracked PHP tests.\n' >&2
  exit 1
fi

mapfile -t tracked_tests <"$manifest"
selected_tests=("${tracked_tests[@]}")
if [[ $# -gt 0 ]]; then
  selected_tests=("$@")
  for test in "${selected_tests[@]}"; do
    if [[ $test != tests/test-*.php ]] || ! grep -qxF -- "$test" "$manifest"; then
      printf 'Requested test is not a tracked PHP unit test: %s\n' "$test" >&2
      exit 2
    fi
    if [[ $test == tests/test-phpstan-*.php ]] || is_excluded "$test"; then
      printf 'Requested test belongs to another CI job: %s\n' "$test" >&2
      exit 2
    fi
  done
fi

if [[ $# -eq 0 ]]; then
  node ci/test-browser-auth-diagnostics.cjs
fi

for test in "${selected_tests[@]}"; do
  [[ $test == tests/test-phpstan-*.php ]] && continue
  is_excluded "$test" && continue
  echo "== $test =="
  : >"$test_output"
  # `set -e`/`pipefail` would abort at the pipeline itself, making the
  # diagnostics below unreachable, so disable it just long enough to read
  # PIPESTATUS. It must be captured by the statement immediately following the
  # pipeline — any other command in between resets it.
  set +e
  php "$test" | tee "$test_output"
  pipe_status=("${PIPESTATUS[@]}")
  set -e
  php_status=${pipe_status[0]}
  tee_status=${pipe_status[1]:-0}
  if ((php_status != 0)); then
    printf 'Test failed: %s (exit %d)\n' "$test" "$php_status" >&2
    exit "$php_status"
  fi
  # php succeeded but the pipeline did not, so `tee` failed and the captured
  # output is unreliable — do not let that surface as a missing verdict.
  if ((tee_status != 0)); then
    printf 'Could not capture output for %s (tee exit %d)\n' "$test" "$tee_status" >&2
    exit 1
  fi
  if ! verdict_ok "$test_output"; then
    last_line=$(grep -v '^[[:space:]]*$' "$test_output" | tail -n 1) || true
    printf 'Test exited 0 but printed no terminal verdict: %s\nlast line: %s\n' \
      "$test" "${last_line:-<empty output>}" >&2
    exit 1
  fi
done
