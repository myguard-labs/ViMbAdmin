#!/usr/bin/env bash
set -euo pipefail

root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
readonly root
fixture=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-workflow-timeouts.XXXXXX")
trap 'rm -rf -- "$fixture"' EXIT
mkdir -p "$fixture/.github/workflows"
cp -- "$root"/.github/workflows/{ci,regression,security,static-analysis}.yml \
  "$fixture/.github/workflows/"

run_contract() {
  (
    cd -- "$fixture"
    unset WORKFLOW_RUNTIME_SECURITY_WORKFLOW WORKFLOW_RUNTIME_REGRESSION_WORKFLOW
    bash "$root/.github/scripts/assert-workflow-runtime.sh"
  ) >"$fixture/output" 2>&1
}

expect_rejected() {
  local label=$1 message=$2
  if run_contract; then
    printf 'Workflow runtime contract accepted %s.\n' "$label" >&2
    exit 1
  fi
  if ! grep -qF -- "$message" "$fixture/output"; then
    cat "$fixture/output" >&2
    exit 1
  fi
}

expect_accepted() {
  if ! run_contract; then
    cat "$fixture/output" >&2
    exit 1
  fi
}

expect_accepted

# Every job is checked independently while its peers retain valid timeouts.
# Include the two previously bounded jobs so later removals cannot regress.
for entry in ci:lint ci:audit regression:assets regression:static \
  regression:unit regression:cache-wiring regression:schema-drift \
  security:semgrep static-analysis:phpstan static-analysis:psalm-taint \
  static-analysis:php-cs-fixer; do
  workflow=${entry%%:*}
  job=${entry#*:}
  path=$fixture/.github/workflows/$workflow.yml
  sed "/^  $job:/,/^    timeout-minutes:/ { /^    timeout-minutes:/d; }" \
    "$root/.github/workflows/$workflow.yml" >"$path"
  expect_rejected "a missing timeout for $entry" \
    "job $job needs exactly one job-level timeout-minutes (found 0)"
  cp -- "$root/.github/workflows/$workflow.yml" "$path"
done

readonly regression=$fixture/.github/workflows/regression.yml
for value in 1 360 '10 # runner headroom'; do
  sed "0,/^    timeout-minutes:/s/^    timeout-minutes:.*/    timeout-minutes: $value/" \
    "$root/.github/workflows/regression.yml" >"$regression"
  expect_accepted
done

for value in '' 0 -1 361 1.5 true '"10"' "'10'" "\${{ 10 }}" null 01; do
  sed "0,/^    timeout-minutes:/s/^    timeout-minutes:.*/    timeout-minutes: $value/" \
    "$root/.github/workflows/regression.yml" >"$regression"
  expect_rejected "timeout value [$value]" \
    'job assets timeout-minutes must be a literal integer from 1 to 360'
done

cp -- "$root/.github/workflows/regression.yml" "$regression"
sed -i '/^    timeout-minutes:/a\    timeout-minutes: 10' "$regression"
expect_rejected 'duplicate job timeouts' \
  'job assets needs exactly one job-level timeout-minutes (found 2)'

cp -- "$root/.github/workflows/regression.yml" "$regression"
sed -i '0,/^    timeout-minutes:/s/^    timeout-minutes:.*/    # timeout-minutes: 10/' "$regression"
expect_rejected 'a commented-out timeout' \
  'job assets needs exactly one job-level timeout-minutes (found 0)'

cp -- "$root/.github/workflows/regression.yml" "$regression"
sed -i '/^    timeout-minutes:/d; /      - name: Prepare PHP container for checkout/a\        timeout-minutes: 10' \
  "$regression"
expect_rejected 'step timeouts without job timeouts' \
  'job unit needs exactly one job-level timeout-minutes (found 0)'

cp -- "$root/.github/workflows/regression.yml" "$regression"
sed -i 's/^  unit:$/  "unit":/' "$regression"
expect_rejected 'an unsupported job declaration' 'unsupported job declaration'

cp -- "$root/.github/workflows/regression.yml" "$regression"
sed -i 's/^jobs:$/"jobs":/' "$regression"
expect_rejected 'an unsupported jobs layout' \
  'no jobs found in the supported two-space layout'

cp -- "$root/.github/workflows/regression.yml" "$regression"
printf '\nenv:\n  TIMEOUT_CONTROL: 1\n' >>"$regression"
expect_accepted

cp -- "$root/.github/workflows/regression.yml" "$regression"
expect_accepted
printf 'Workflow job timeout controls passed (11 jobs, boundaries and malformed values).\n'
