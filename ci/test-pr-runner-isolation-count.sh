#!/usr/bin/env bash
set -euo pipefail

root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
readonly root
fixture=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-pr-job-count.XXXXXX")
trap 'rm -rf -- "$fixture"' EXIT
mkdir "$fixture/workflows"
# This fixture uses the published two-line data format, with no host secrets.
printf '%s\n%s\n' builder02-docker-01 /opt/actions-runner >"$fixture/slot-source"
if [[ $(id -u) == 0 ]]; then
  install -o 0 -g 0 -m 0444 "$fixture/slot-source" "$fixture/slot"
else
  sudo -n install -o 0 -g 0 -m 0444 "$fixture/slot-source" "$fixture/slot"
fi
export RUNNER_NAME=builder02-docker-01 PR_RUNNER_SLOT_FILE="$fixture/slot"
PR_RUNNER_EXPECTED_SHA=$(git -C "$root" -c safe.directory="$root" rev-parse HEAD)
export PR_RUNNER_EXPECTED_SHA
cp -- "$root"/.github/workflows/{ci,regression,security,static-analysis}.yml "$fixture/workflows/"

run_guard() {
  PR_RUNNER_WORKFLOW_DIR="$fixture/workflows" GITHUB_EVENT_NAME=pull_request \
    RUNNER_ENVIRONMENT=github-hosted \
    bash "$root/.github/scripts/assert-pr-runner-isolation.sh" >"$fixture/output" 2>&1
}

run_guard
grep -qF 'All pull-request jobs use authorized' "$fixture/output"

cat >>"$fixture/workflows/regression.yml" <<'YAML'
  count_control:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1
        with:
          ref: ${{ github.event.pull_request.head.sha || github.sha }}
          persist-credentials: false
      - run: bash .github/scripts/assert-pr-runner-isolation.sh
        env:
          PR_RUNNER_EXPECTED_SHA: >-
            ${{ github.event.pull_request.head.sha || github.sha }}
YAML
if run_guard; then
  printf 'Isolation guard accepted a fourteenth PR job.\n' >&2
  exit 1
fi
grep -qFx 'PR-triggered job count is 14; expected 13.' "$fixture/output"
if grep -qF 'Every PR-triggered job must use the authorized Docker selector' "$fixture/output"; then
  printf 'A valid extra job was misreported as a per-job defect.\n' >&2
  exit 1
fi

bash "$root/ci/test-pr-checkout-trust.sh"

printf 'PR runner job-count controls passed.\n'
