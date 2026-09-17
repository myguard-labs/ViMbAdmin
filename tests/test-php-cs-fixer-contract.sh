#!/usr/bin/env bash
set -euo pipefail

# Contract for the PSR-12 formatting gate (.php-cs-fixer.dist.php):
#
#   1. the configuration keeps risky fixers disabled, so the gate may only ever
#      move whitespace and braces and never rewrite semantics;
#   2. every tracked PHP source path is covered by the finder;
#   3. the gate fails on deliberately unformatted input (negative control) and
#      passes on the same input once formatted (positive control) -- a check
#      that always passes would enforce nothing.
#
# The gate itself runs `php-cs-fixer check` over the working tree; this script
# proves the gate is real before that verdict is trusted.

repo_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
cd "$repo_root"

readonly config=.php-cs-fixer.dist.php

failures=0

# `check LABEL CONDITION...` runs CONDITION without tripping `set -e`, so a
# failing assertion is scored rather than aborting the remaining checks.
check() {
  local label=$1
  shift
  if "$@"; then
    printf '  ok   %s\n' "$label"
  else
    printf '  FAIL %s\n' "$label"
    failures=$((failures + 1))
  fi
}

# Predicates below are invoked indirectly through `check`.
# shellcheck disable=SC2317
contains() { [[ $1 == *"$2"* ]]; }
# shellcheck disable=SC2317
lacks() { [[ $1 != *"$2"* ]]; }
# shellcheck disable=SC2317
is_empty() { (($# == 0)); }
# shellcheck disable=SC2317
nonzero() { (($1 != 0)); }
# shellcheck disable=SC2317
zero() { (($1 == 0)); }

if ! command -v php-cs-fixer >/dev/null 2>&1; then
  printf 'php-cs-fixer is not installed; the formatting gate cannot be proven.\n' >&2
  exit 1
fi

if [[ ! -f $config ]]; then
  printf 'Missing formatting configuration: %s\n' "$config" >&2
  exit 1
fi

config_source=$(cat "$config")

# 1. Risky fixers must stay disabled.
check 'configuration disables risky fixers' \
  contains "$config_source" 'setRiskyAllowed(false)'
check 'configuration never enables risky fixers' \
  lacks "$config_source" 'setRiskyAllowed(true)'

# 2. Every tracked PHP path must be covered by the finder.
missing_dirs=()
while IFS= read -r dir; do
  [[ -n $dir ]] || continue
  contains "$config_source" "/$dir" || missing_dirs+=("$dir")
done < <(git ls-files '*.php' | awk -F/ 'NF > 1 {print $1}' | sort -u)

check "finder covers every tracked PHP directory (missing: ${missing_dirs[*]:-none})" \
  is_empty "${missing_dirs[@]}"

missing_files=()
while IFS= read -r file; do
  [[ -n $file ]] || continue
  # The configuration file is the contract itself, not a source it formats.
  [[ $file == "$config" ]] && continue
  contains "$config_source" "/$file" || missing_files+=("$file")
done < <(git ls-files '*.php' | awk -F/ 'NF == 1 {print $1}' | sort -u)

check "finder covers every tracked root-level PHP file (missing: ${missing_files[*]:-none})" \
  is_empty "${missing_files[@]}"

# 3. Controls. The negative control proves the gate rejects unformatted input;
#    the positive control proves that rejection came from the formatting rules
#    and not from a broken invocation.
control_dir=$(mktemp -d)
# shellcheck disable=SC2317  # invoked indirectly by the EXIT trap
cleanup() {
  rm -rf "$control_dir"
}
trap cleanup EXIT

mkdir -p "$control_dir/src"
# Allman braces and padded parentheses: PSR-12 violations with no semantic effect.
cat >"$control_dir/src/Unformatted.php" <<'CONTROL'
<?php

namespace Control;

class Unformatted
{
    public function check( $value )
    {
        if( $value )
        {
            return true;
        }
        return false;
    }
}
CONTROL

cat >"$control_dir/.php-cs-fixer.control.php" <<'CONTROL'
<?php

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules(['@PSR12' => true])
    ->setFinder((new PhpCsFixer\Finder())->in([__DIR__ . '/src'])->name('*.php'));
CONTROL

run_control_check() {
  local status=0
  php-cs-fixer check \
    --config "$control_dir/.php-cs-fixer.control.php" \
    --show-progress=none >/dev/null 2>&1 || status=$?
  printf '%d' "$status"
}

check 'negative control: unformatted source fails the gate' \
  nonzero "$(run_control_check)"

php-cs-fixer fix \
  --config "$control_dir/.php-cs-fixer.control.php" \
  --show-progress=none >/dev/null 2>&1

check 'positive control: formatted source passes the gate' \
  zero "$(run_control_check)"

if ((failures == 0)); then
  printf 'ALL PASSED\n'
  exit 0
fi

printf '%d FAILED\n' "$failures"
exit 1
