#!/usr/bin/env bash
set -euo pipefail

root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
readonly root
fixture=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-pr-job-count.XXXXXX")
trap 'rm -rf -- "$fixture"' EXIT
mkdir "$fixture/workflows"
cp -- "$root"/.github/workflows/{ci,regression,security,static-analysis}.yml "$fixture/workflows/"

run_guard() {
  PR_RUNNER_WORKFLOW_DIR="$fixture/workflows" GITHUB_EVENT_NAME=pull_request \
    RUNNER_ENVIRONMENT=github-hosted \
    bash "$root/.github/scripts/assert-pr-runner-isolation.sh" >"$fixture/output" 2>&1
}

run_guard
grep -qF 'All pull-request jobs are isolated' "$fixture/output"

cat >>"$fixture/workflows/regression.yml" <<'YAML'
  count_control:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1
        with:
          ref: ${{ github.event.pull_request.head.sha || github.sha }}
      - run: bash .github/scripts/assert-pr-runner-isolation.sh
YAML
if run_guard; then
  printf 'Isolation guard accepted a twelfth PR job.\n' >&2
  exit 1
fi
grep -qFx 'PR-triggered job count is 12; expected 11.' "$fixture/output"
if grep -qF 'Every PR-triggered job must use ubuntu-24.04' "$fixture/output"; then
  printf 'A valid extra job was misreported as a per-job defect.\n' >&2
  exit 1
fi

printf 'PR runner job-count controls passed.\n'
