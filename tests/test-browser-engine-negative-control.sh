#!/usr/bin/env bash
# Purpose: prove the existing compatibility lane reports a broken fixture red
# on the selected CI engine. Uses its button-disabled mutation, never source edits.
# Usage: VIMBADMIN_BROWSER=chromium|firefox|webkit bash tests/test-browser-engine-negative-control.sh
# Output: engine-attributed assertion; side effects: temporary captured output.
# Limits: 90 seconds per lane. No dry-run; --help describes the invocation.
set -euo pipefail
if [[ ${1:-} == --help ]]; then
  sed -n '2,6p' "$0"
  exit 0
fi
cd "$(dirname "$0")/.."
case ${VIMBADMIN_BROWSER:-} in
chromium | firefox | webkit) ;;
*)
  echo 'FAIL: select chromium, firefox or webkit' >&2
  exit 64
  ;;
esac
output=$(mktemp)
trap 'rm -f -- "$output"' EXIT
status=0
VIMBADMIN_MUTATION=button-disabled \
  CHROMIUM_BIN="$PWD/.github/scripts/run-headless-chrome.sh" \
  timeout 90 bash tests/test-datatables-dependency-free-compat.sh >"$output" 2>&1 || status=$?
if [[ $status != 1 ]] ||
  ! grep -qF 'a button restored after the work completes is enabled again' "$output"; then
  cat "$output" >&2
  echo "[$VIMBADMIN_BROWSER] FAIL: broken lane did not fail its button assertion (exit $status)" >&2
  exit 1
fi
printf '[%s] OK: broken compatibility lane observed red: ' "$VIMBADMIN_BROWSER"
cat "$output"
