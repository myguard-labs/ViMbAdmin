#!/usr/bin/env bash
# Purpose: verify syntax-only PHP batching, enumeration and CLI failure paths.
# Usage: bash ci/test-php-syntax-batching.sh [--help], from any directory.
# Inputs: sibling check-php-syntax.sh and PHP_BINARY (or php on PATH), PHP 8.3+.
# Output: case results. Side effects: owned scratch tree, removed on exit.
# No services or signals. Extend roots and fixtures when lint scope changes.
set -euo pipefail
if [[ ${1:-} == --help && $# == 1 ]]; then
  sed -n '2,6s/^# //p' "$0"
  exit 0
fi
[[ $# == 0 ]] || { echo 'Unexpected arguments; use --help.' >&2; exit 2; }

helper_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
real_php=${PHP_BINARY:-$(command -v php)}
[[ -x $real_php ]] || { echo 'PHP interpreter unavailable.' >&2; exit 1; }
scratch=$(mktemp -d)
trap 'rm -rf -- "$scratch"' EXIT
fixture=$scratch/tree
roots=(application library public bin src)
mkdir -p "$fixture/ci" "$scratch/bin" "$scratch/no-php"
for root in "${roots[@]}"; do mkdir -p "$fixture/$root"; done
cp -- "$helper_dir/check-php-syntax.sh" "$scratch/candidate.sh"
cp -- "$scratch/candidate.sh" "$fixture/ci/check-php-syntax.sh"
# Keep all helper utilities available while deliberately omitting PHP.
for tool in mktemp rm find xargs sed; do
  ln -s -- "$(command -v "$tool")" "$scratch/no-php/$tool"
done
cat > "$scratch/bin/php" <<'WRAPPER'
#!/bin/bash
set -euo pipefail
printf 'startup\n' >> "$PHP_LINT_STARTUPS"
printf '%s\0' "$@" >> "$PHP_LINT_ARGUMENTS"
[[ ${1:-} == -l ]] || { echo 'Expected syntax-only PHP invocation.' >&2; exit 95; }
exec "$PHP_LINT_REAL_PHP" "$@"
WRAPPER
chmod +x "$scratch/bin/php"
export PHP_LINT_REAL_PHP=$real_php
export PHP_LINT_STARTUPS=$scratch/startups PHP_LINT_ARGUMENTS=$scratch/arguments
export PHP_LINT_EXECUTION_SENTINEL=$scratch/executed
default_path=$PATH
cases=0

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  [[ ! -f $scratch/output ]] || tail -n 12 "$scratch/output" >&2
  exit 1
}
pass() { cases=$((cases + 1)); printf 'PASS: %s\n' "$1"; }
run_helper() {
  local expected=$1 path=$2 status=0
  shift 2
  : > "$PHP_LINT_STARTUPS"
  : > "$PHP_LINT_ARGUMENTS"
  (cd -- "$fixture" && PATH=$path /bin/bash ci/check-php-syntax.sh "$@") \
    > "$scratch/output" 2>&1 || status=$?
  case $expected in
    success) [[ $status == 0 ]] || fail "expected success, got $status" ;;
    failure) [[ $status != 0 ]] || fail 'expected a failure' ;;
    *) [[ $status == "$expected" ]] || fail "expected exit $expected, got $status" ;;
  esac
}
assert_startups() {
  local expected=$1
  local -a startups=()
  mapfile -t startups < "$PHP_LINT_STARTUPS"
  [[ ${#startups[@]} == "$expected" ]] || {
    printf 'expected %s PHP startup(s), got %s\n' "$expected" "${#startups[@]}" >&2
    return 1
  }
}
valid_fixture() {
  local root
  for root in "${roots[@]}"; do
    printf '<?php true;\n' > "$fixture/$root/base.php"
  done
}
check_batch() {
  assert_startups 1 || fail 'ordinary fixture did not use one PHP startup'
  local -a args=()
  mapfile -d '' -t args < "$PHP_LINT_ARGUMENTS"
  [[ ${args[0]:-} == -l ]] || fail 'PHP was invoked without -l'
  printf '%s\0' "${args[@]:1}" | sort -z > "$scratch/actual-files"
  cmp -s "$scratch/expected-files" "$scratch/actual-files" || fail 'lint file set differs'
  [[ ! -e $PHP_LINT_EXECUTION_SENTINEL ]] || fail 'PHP source was executed'
}

valid_fixture
special=('application/with space.php' $'public/with\nnewline.php' 'bin/-leading.php')
for file in "${special[@]}"; do printf '<?php true;\n' > "$fixture/$file"; done
printf "<?php file_put_contents(getenv('PHP_LINT_EXECUTION_SENTINEL'), 'executed');\n" \
  > "$fixture/library/sentinel.php"
mkdir "$fixture/vendor"
printf '<?php function malformed( {\n' > "$fixture/vendor/excluded.php"
{
  for root in "${roots[@]}"; do printf '%s\0' "$root/base.php"; done
  printf '%s\0' "${special[@]}" 'library/sentinel.php'
} | sort -z > "$scratch/expected-files"

run_helper success "$scratch/bin:$default_path"
check_batch
pass 'all roots and unusual filenames lint in one startup; outside files excluded; no execution'
for location in library src; do
  printf '<?php function malformed( {\n' > "$fixture/$location/base.php"
  run_helper failure "$scratch/bin:$default_path"
  assert_startups 1 || fail 'malformed fixture was not checked in one batch'
  valid_fixture
  pass "malformed PHP in $location fails the batch"
done

# Remove only fixture PHP files; the excluded vendor file stays malformed.
for root in "${roots[@]}"; do
  find "$fixture/$root" -name '*.php' -delete
done
run_helper failure "$scratch/bin:$default_path"
assert_startups 0 || fail 'empty enumeration launched PHP'
pass 'empty enumeration fails without launching PHP'
valid_fixture
mv "$fixture/src" "$scratch/saved-src"
run_helper failure "$scratch/bin:$default_path"
assert_startups 0 || fail 'failed enumeration launched PHP'
mv "$scratch/saved-src" "$fixture/src"
pass 'missing find root fails before PHP despite partial enumeration'

run_helper failure "$scratch/no-php"
assert_startups 0 || fail 'missing PHP unexpectedly launched the wrapper'
pass 'missing PHP fails'
run_helper success "$scratch/no-php" --help
assert_startups 0 || fail 'help launched PHP'
[[ -s $scratch/output ]] || fail 'help is empty'
pass 'help succeeds without PHP'
for argument in --unexpected --help; do
  if [[ $argument == --help ]]; then
    run_helper 2 "$scratch/no-php" --help extra
  else
    run_helper 2 "$scratch/no-php" "$argument"
  fi
  assert_startups 0 || fail 'usage error launched PHP'
  pass "usage rejection: $argument"
done

# Restore the original valid boundary fixture before the per-file mutation.
for file in "${special[@]}"; do printf '<?php true;\n' > "$fixture/$file"; done
printf "<?php file_put_contents(getenv('PHP_LINT_EXECUTION_SENTINEL'), 'executed');\n" \
  > "$fixture/library/sentinel.php"
sed 's/xargs -0 -r php -l/xargs -0 -r -n 1 php -l/' "$scratch/candidate.sh" \
  > "$fixture/ci/check-php-syntax.sh"
cmp -s "$scratch/candidate.sh" "$fixture/ci/check-php-syntax.sh" \
  && fail 'per-file negative control did not alter the scratch helper'
run_helper success "$scratch/bin:$default_path"
if assert_startups 1 2> "$scratch/negative-control"; then
  fail 'per-file mutation escaped the startup assertion'
fi
printf 'Negative control rejected: %s\n' "$(< "$scratch/negative-control")"
pass 'old per-file xargs behavior fails the one-startup assertion'
cp -- "$scratch/candidate.sh" "$fixture/ci/check-php-syntax.sh"
run_helper success "$scratch/bin:$default_path"
check_batch
pass 'restored candidate passes the full valid fixture'
printf 'PHP syntax batching controls: %s cases passed.\n' "$cases"
