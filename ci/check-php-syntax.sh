#!/usr/bin/env bash
# Purpose: lint the application PHP tree in native PHP 8.3+ batches.
# Usage: bash ci/check-php-syntax.sh [--help], from the repository root.
# Inputs: application, library, public, bin, src. Output: failure diagnostics.
# Side effects: temporary manifest, removed on exit; source is never executed.
# No dry-run needed. Extend the find roots when adding a shipped source tree.
set -euo pipefail
if [[ ${1:-} == --help && $# == 1 ]]; then
  sed -n '2,6s/^# //p' "$0"
  exit 0
fi
[[ $# == 0 ]] || { echo 'Unexpected arguments; use --help.' >&2; exit 2; }
manifest=$(mktemp)
trap 'rm -f "$manifest"' EXIT
find application library public bin src -name '*.php' -print0 > "$manifest"
[[ -s $manifest ]] || { echo 'No PHP files found.' >&2; exit 1; }
# PHP 8.3+ accepts multiple files after -l. xargs splits only at ARG_MAX.
xargs -0 -r php -l < "$manifest" > /dev/null
echo 'PHP syntax check passed.'
