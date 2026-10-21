#!/usr/bin/env bash
# Executable CI entry point for the real ViMbAdmin auth/error browser fixture.
set -euo pipefail
cd "$(dirname "$0")/.."

negative_log=$(mktemp "${TMPDIR:-/tmp}/vimbadmin-browser-engine-negative.XXXXXX")
negative_status=0
VIMBADMIN_BROWSER=deliberately-invalid \
  bash tests/support/browser-application-fixture.sh >"$negative_log" 2>&1 || negative_status=$?
if [[ $negative_status != 64 ]] ||
  ! grep -qxF 'FAIL: unsupported VIMBADMIN_BROWSER=deliberately-invalid' "$negative_log"; then
  echo 'FAIL: invalid browser application engine negative control did not observe exit 64' >&2
  sed -n '1,80p' "$negative_log" >&2
  rm -f -- "$negative_log"
  exit 1
fi
rm -f -- "$negative_log"
echo 'OK: invalid browser application engine rejected with exit 64'

exec bash tests/support/browser-application-fixture.sh "$@"
