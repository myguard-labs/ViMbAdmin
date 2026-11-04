#!/usr/bin/env bash
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
readonly root
fixture=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-selfhosted-policy.XXXXXX")
trap 'rm -rf -- "$fixture"' EXIT
mkdir "$fixture/workflows"
cp -- "$root"/.github/workflows/*.yml "$fixture/workflows/"
contract=${PR_RUNNER_TEST_CONTRACT:-$root/.github/scripts/assert-pr-runner-isolation.sh}
export PR_RUNNER_WORKFLOW_DIR="$fixture/workflows"
export PR_RUNNER_SLOT_FILE="$fixture/slot" RUNNER_ENVIRONMENT=self-hosted
export RUNNER_NAME=builder02-docker-01 GITHUB_EVENT_NAME=pull_request
PR_RUNNER_EXPECTED_SHA=$(git -C "$root" rev-parse HEAD)
export PR_RUNNER_EXPECTED_SHA

slot() {
  printf '%s\n' "$@" >"$fixture/source"
  if [[ $(id -u) == 0 ]]; then
    install -o 0 -g 0 -m 0444 "$fixture/source" "$fixture/slot"
  else
    sudo -n install -o 0 -g 0 -m 0444 "$fixture/source" "$fixture/slot"
  fi
}
run_guard() { (cd "$root" && bash "$contract") >"$fixture/output" 2>&1; }
reject() {
  local label=$1 expected=$2
  if run_guard; then
    printf 'FAIL %s: unsafe input accepted.\n' "$label" >&2
    exit 1
  fi
  if ! grep -qF "$expected" "$fixture/output"; then
    printf 'FAIL %s: wrong rejection.\n' "$label" >&2
    cat "$fixture/output" >&2
    exit 1
  fi
  printf 'PASS rejected %s\n' "$label"
}
restore() { cp -- "$root"/.github/workflows/*.yml "$fixture/workflows/"; }

for runner in builder02-docker-01 builder02-docker-02 builder03-docker-01 vi-local-ci-208c2d13-01; do
  RUNNER_NAME=$runner
  slot "$runner" /opt/actions-runner
  for event in pull_request push schedule workflow_dispatch; do
    GITHUB_EVENT_NAME=$event run_guard
  done
done
RUNNER_NAME=vi-local-ci-208c2d13-01
slot "$RUNNER_NAME" /opt/actions-runner
RUNNER_NAME=vi-local-ci-208c2d13-02 reject unknown-local-runner 'authorized Docker slot'
for legacy in builder02-docker-01 builder02-docker-02 builder03-docker-01; do
  RUNNER_NAME=$legacy reject local-metadata-legacy-runner 'does not match this runner'
  slot "$legacy" /opt/actions-runner
  reject legacy-metadata-local-runner 'does not match this runner'
  slot "$RUNNER_NAME" /opt/actions-runner
done
printf 'PASS local slot accepts four supported events and rejects cross-slot metadata\n'
RUNNER_NAME=builder02-docker-01
slot "$RUNNER_NAME" /opt/actions-runner
GITHUB_EVENT_NAME=pull_request
RUNNER_ENVIRONMENT=github-hosted reject hosted-runtime 'authorized self-hosted'
RUNNER_NAME=builder02-runner-01 reject non-docker-slot 'authorized Docker slot'
RUNNER_NAME=unknown reject unknown-runner 'authorized Docker slot'
GITHUB_EVENT_NAME=pull_request_target reject privileged-event 'ordinary supported event'
GITHUB_EVENT_NAME=workflow_run reject chained-event 'ordinary supported event'
GITHUB_EVENT_NAME='' reject missing-event 'ordinary supported event'
PR_RUNNER_SLOT_FILE="$fixture/absent" reject absent-metadata 'invalid published slot'
ln -s "$fixture/slot" "$fixture/link"
PR_RUNNER_SLOT_FILE="$fixture/link" reject symlink-metadata 'invalid published slot'
PR_RUNNER_SLOT_FILE="$fixture/source" reject writable-user-metadata 'invalid published slot'
slot builder03-docker-01 /opt/actions-runner
reject mismatched-slot 'does not match this runner'
slot "$RUNNER_NAME" /tmp/not-runner-state
reject wrong-namespace 'does not match this runner'
slot "$RUNNER_NAME" /opt/actions-runner extra
reject extra-metadata 'does not match this runner'
slot "$RUNNER_NAME"
reject truncated-metadata 'does not match this runner'
slot "$RUNNER_NAME" /opt/actions-runner
PR_RUNNER_EXPECTED_SHA=bad reject malformed-sha 'exact event head'
PR_RUNNER_EXPECTED_SHA=0000000000000000000000000000000000000000 reject wrong-head 'exact event head'

for mutation in selector missing-local-capability missing-jit missing-capability guard checkout credentials metadata expected trigger ordinary concurrency permissions host-mount extra-mount upload-if upload-permissions; do
  restore
  case $mutation in
  selector)
    sed -i '0,/runs-on: \[self-hosted, vimbadmin-owned-local-v1, docker, jit-env-input, vimbadmin-pristine-jit-v1\]/s//runs-on: ubuntu-24.04/' "$fixture/workflows/ci.yml"
    expected='Every PR-triggered job'
    ;;
  missing-local-capability)
    sed -i '0,/self-hosted, vimbadmin-owned-local-v1, docker/s//self-hosted, builder02, docker/' "$fixture/workflows/ci.yml"
    expected='Every PR-triggered job'
    ;;
  missing-jit)
    sed -i '0,/runs-on: \[self-hosted, vimbadmin-owned-local-v1, docker, jit-env-input, vimbadmin-pristine-jit-v1\]/s//runs-on: [self-hosted, vimbadmin-owned-local-v1, docker, vimbadmin-pristine-jit-v1]/' "$fixture/workflows/ci.yml"
    expected='Every PR-triggered job'
    ;;
  missing-capability)
    sed -i '0,/runs-on: \[self-hosted, vimbadmin-owned-local-v1, docker, jit-env-input, vimbadmin-pristine-jit-v1\]/s//runs-on: [self-hosted, vimbadmin-owned-local-v1, docker, jit-env-input]/' "$fixture/workflows/ci.yml"
    expected='Every PR-triggered job'
    ;;
  guard)
    sed -i '0,/run: bash \.github\/scripts\/assert-pr-runner-isolation\.sh/s//run: true/' "$fixture/workflows/ci.yml"
    expected='Every PR-triggered job'
    ;;
  checkout)
    sed -i '0,/ref: \${{ github.event.pull_request.head.sha || github.sha }}/s//ref: master/' "$fixture/workflows/ci.yml"
    expected='Every PR-triggered job'
    ;;
  credentials)
    sed -i '0,/persist-credentials: false/s//persist-credentials: true/' "$fixture/workflows/ci.yml"
    expected='Every PR-triggered job'
    ;;
  metadata)
    sed -i '0,/myguard-ci-slot:ro/s//myguard-ci-slot:rw/' "$fixture/workflows/ci.yml"
    expected='Every PR-triggered job'
    ;;
  expected)
    sed -i '0,/PR_RUNNER_EXPECTED_SHA:/s//UNUSED_EXPECTED_SHA:/' "$fixture/workflows/ci.yml"
    expected='Every PR-triggered job'
    ;;
  trigger)
    sed -i '0,/pull_request:/s//pull_request_target:/' "$fixture/workflows/ci.yml"
    expected='ordinary pull_request event'
    ;;
  ordinary)
    sed -i '0,/pull_request:/s//disabled_pull_request:/' "$fixture/workflows/ci.yml"
    expected='ordinary pull_request event'
    ;;
  concurrency)
    sed -i 's/${{ github.event_name }}-//' "$fixture/workflows/ci.yml"
    expected='isolate concurrency'
    ;;
  permissions)
    sed -i 's/contents: read/contents: write/' "$fixture/workflows/ci.yml"
    expected='least-privilege token policy'
    ;;
  upload-if)
    sed -i "s/github.event_name != 'pull_request' \&\&/true \&\&/" "$fixture/workflows/security.yml"
    expected='Every PR-triggered job'
    ;;
  upload-permissions)
    sed -i 's/security-events: write  # Only this non-PR job uploads SARIF./contents: write/' "$fixture/workflows/security.yml"
    expected='Every PR-triggered job'
    ;;
  extra-mount)
    sed -i '/volumes:/a\        - /home/runner/.ssh:/ssh:ro' "$fixture/workflows/ci.yml"
    expected='Every PR-triggered job'
    ;;
  host-mount)
    sed -i '/volumes:/a\        - /etc/ci-gh-token:/token:ro' "$fixture/workflows/ci.yml"
    expected='privileged trigger, mount, or credential'
    ;;
  esac
  reject "workflow-$mutation" "$expected"
done
restore
cp "$fixture/workflows/ci.yml" "$fixture/workflows/extra.yaml"
reject extra-workflow 'Unexpected workflow inventory'
rm "$fixture/workflows/extra.yaml"
run_guard
printf 'Self-hosted placement positive and negative controls passed.\n'
