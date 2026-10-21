#!/usr/bin/env bash
# Executable CI entry point for the real ViMbAdmin auth/error browser fixture.
set -euo pipefail
cd "$(dirname "$0")/.."

control_only=0
if [[ ${1:-} == --preflight-cleanup-control-only ]]; then
  control_only=1
  shift
fi

negative_log=$(mktemp "${TMPDIR:-/tmp}/vimbadmin-browser-engine-negative.XXXXXX")
control_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-browser-preflight-control.XXXXXX")
fixture_probe=$(mktemp -d /tmp/vimbadmin-browser-application.XXXXXX)
rmdir "$fixture_probe"
cleanup() {
  rm -f -- "$negative_log"
  if [[ $fixture_probe == /tmp/vimbadmin-browser-application.* && -d $fixture_probe && ! -L $fixture_probe ]]; then
    rm -rf -- "${fixture_probe:?}"
  fi
  if [[ -d $control_root && ! -L $control_root ]]; then
    rm -rf -- "${control_root:?}"
  fi
}
trap cleanup EXIT

negative_status=0
VIMBADMIN_BROWSER=deliberately-invalid \
  bash tests/support/browser-application-fixture.sh >"$negative_log" 2>&1 || negative_status=$?
if [[ $negative_status != 64 ]] ||
  ! grep -qxF 'FAIL: unsupported VIMBADMIN_BROWSER=deliberately-invalid' "$negative_log"; then
  echo 'FAIL: invalid browser application engine negative control did not observe exit 64' >&2
  sed -n '1,80p' "$negative_log" >&2
  exit 1
fi
echo 'OK: invalid browser application engine rejected with exit 64'

stub_bin=$control_root/bin
preflight_log=$control_root/preflight.log
docker_log=$control_root/docker.log
mkdir -p "$stub_bin"
for command_name in composer curl php python3 realpath sha256sum tar dirname mkdir chmod install rm; do
  ln -s "$(command -v "$command_name")" "$stub_bin/$command_name"
done
# shellcheck disable=SC2016 # Preserve expansions for the generated Docker stub.
printf '%s\n' '#!/bin/sh' \
  'printf "%s\\n" "${1:-}" >>"$VIMBADMIN_PREFLIGHT_CONTROL_DOCKER_LOG"' \
  'if [ "${1:-}" = ps ]; then exit 0; fi' \
  'exit 71' >"$stub_bin/docker"
# shellcheck disable=SC2016 # Preserve expansions for the generated mktemp stub.
printf '%s\n' '#!/bin/sh' \
  'if [ "$#" -eq 2 ] && [ "$1" = -d ] && [ "$2" = /tmp/vimbadmin-browser-application.XXXXXX ]; then' \
  '  mkdir -p "$VIMBADMIN_PREFLIGHT_CONTROL_FIXTURE_ROOT"' \
  '  printf "%s\\n" "$VIMBADMIN_PREFLIGHT_CONTROL_FIXTURE_ROOT"' \
  '  exit 0' \
  'fi' \
  'exit 70' >"$stub_bin/mktemp"
chmod 500 "$stub_bin/docker" "$stub_bin/mktemp"

preflight_status=0
PATH="$stub_bin" VIMBADMIN_PREFLIGHT_CONTROL_FIXTURE_ROOT="$fixture_probe" \
  VIMBADMIN_PREFLIGHT_CONTROL_DOCKER_LOG="$docker_log" \
  /usr/bin/bash tests/support/browser-application-fixture.sh \
  --evidence-dir "$control_root/evidence" >"$preflight_log" 2>&1 || preflight_status=$?
if [[ "$preflight_status" != 1 ]] ||
  ! grep -qxF 'FAIL: required browser application fixture command is unavailable: openssl' "$preflight_log" ||
  [[ -e $fixture_probe ]] ||
  [[ -e $fixture_probe/db-password ]] ||
  [[ -e $fixture_probe/verifier-token ]] ||
  [[ -e $fixture_probe/verifier-curl.conf ]] ||
  [[ -e $docker_log ]]; then
  echo 'FAIL: missing openssl preflight left fixture root or private secret files' >&2
  sed -n '1,80p' "$preflight_log" >&2
  exit 1
fi
echo 'OK: missing openssl preflight leaves no fixture root or private secret files'

printf '%s\n' '#!/bin/sh' 'exit 70' >"$stub_bin/openssl"
chmod 500 "$stub_bin/openssl"
startup_log=$control_root/startup.log
startup_evidence=$control_root/startup-evidence
startup_status=0
PATH="$stub_bin" VIMBADMIN_PREFLIGHT_CONTROL_FIXTURE_ROOT="$fixture_probe" \
  VIMBADMIN_PREFLIGHT_CONTROL_DOCKER_LOG="$docker_log" \
  /usr/bin/bash tests/support/browser-application-fixture.sh \
  --evidence-dir "$startup_evidence" >"$startup_log" 2>&1 || startup_status=$?
if [[ "$startup_status" != 70 ]] ||
  [[ -e $fixture_probe ]] ||
  [[ -e $fixture_probe/db-password ]] ||
  [[ -e $fixture_probe/verifier-token ]] ||
  [[ -e $fixture_probe/verifier-curl.conf ]] ||
  ! grep -qxF 'cleanup_original_status=70' "$startup_evidence/logs/99-cleanup.log" ||
  ! grep -qxF 'label_filtered_remaining=0' "$startup_evidence/logs/99-cleanup.log" ||
  ! grep -qxF 'recorded_ids_remaining=0' "$startup_evidence/logs/99-cleanup.log"; then
  echo 'FAIL: failing openssl startup left fixture root or private secret files' >&2
  sed -n '1,80p' "$startup_log" >&2
  sed -n '1,80p' "$startup_evidence/logs/99-cleanup.log" >&2 || true
  exit 1
fi
echo 'OK: failing openssl startup removes fixture root and private secret files'

if ((control_only)); then
  exit 0
fi

cleanup
if [[ -e $negative_log || -L $negative_log ||
  -e $fixture_probe || -L $fixture_probe ||
  -e $control_root || -L $control_root ]]; then
  echo 'FAIL: browser fixture wrapper cleanup left temporary control artifacts' >&2
  exit 1
fi
trap - EXIT
exec bash tests/support/browser-application-fixture.sh "$@"
