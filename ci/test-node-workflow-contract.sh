#!/usr/bin/env bash
set -euo pipefail

root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
readonly root
readonly setup_node='actions/setup-node@53b83947a5a98c8d113130e565377fae1a50d02f'
readonly node_major=22
readonly regression=${NODE_CONTRACT_REGRESSION_WORKFLOW:-$root/.github/workflows/regression.yml}
readonly static_analysis=${NODE_CONTRACT_STATIC_ANALYSIS_WORKFLOW:-$root/.github/workflows/static-analysis.yml}

fail() {
  printf 'Node workflow contract failed: %s\n' "$1" >&2
  exit 1
}

job_body() {
  local workflow=$1 job=$2
  awk -v job="$job" '
    $0 == "  " job ":" { found = 1 }
    found && $0 ~ /^  [A-Za-z_][A-Za-z0-9_-]*:/ && $0 != "  " job ":" { exit }
    found { print }
    END { if (!found) exit 2 }
  ' "$workflow"
}

check_job() {
  local workflow=$1 job=$2 consumer=$3 body setup_block setup_count pin_count cache_count setup_line consumer_line
  if ! body=$(job_body "$workflow" "$job"); then
    fail "$(basename "$workflow") job $job is missing or uses an unsupported declaration"
  fi

  setup_count=$(grep -cF -- 'uses: actions/setup-node@' <<<"$body" || true)
  [[ $setup_count == 1 ]] ||
    fail "$(basename "$workflow") job $job must use setup-node exactly once (found $setup_count)"

  grep -qF -- "uses: $setup_node" <<<"$body" ||
    fail "$(basename "$workflow") job $job must pin actions/setup-node to $setup_node"

  setup_block=$(awk -v action="      - uses: $setup_node" '
    $0 == action { found = 1 }
    found && printed && /^      - / { exit }
    found { print; printed = 1 }
  ' <<<"$body")

  pin_count=$(grep -cE -- "^[[:space:]]+node-version: '$node_major'[[:space:]]*$" <<<"$setup_block" || true)
  [[ $pin_count == 1 ]] ||
    fail "$(basename "$workflow") job $job must pin node-version '$node_major' exactly once (found $pin_count)"

  cache_count=$(grep -cE -- '^[[:space:]]+package-manager-cache: false[[:space:]]*$' <<<"$setup_block" || true)
  [[ $cache_count == 1 ]] ||
    fail "$(basename "$workflow") job $job must disable setup-node package-manager-cache exactly once (found $cache_count)"

  setup_line=$(grep -nF -- "uses: $setup_node" <<<"$body" | cut -d: -f1)
  consumer_line=$(grep -nF -- "$consumer" <<<"$body" | head -1 | cut -d: -f1)
  [[ -n $consumer_line ]] || fail "$(basename "$workflow") job $job has no expected Node consumer: $consumer"
  ((setup_line < consumer_line)) ||
    fail "$(basename "$workflow") job $job must provision Node before: $consumer"
}

check_job "$regression" assets 'npm ci --prefix bin --ignore-scripts'
check_job "$static_analysis" refactor-metrics 'npm ci --ignore-scripts --prefix ci/refactor-metrics'

if [[ ${NODE_CONTRACT_FIXTURE_MODE:-0} != 1 ]]; then
  fixture=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-node-workflow.XXXXXX")
  trap 'rm -rf -- "$fixture"' EXIT
  cp -- "$regression" "$fixture/regression.yml"
  cp -- "$static_analysis" "$fixture/static-analysis.yml"

  run_fixture() {
    NODE_CONTRACT_FIXTURE_MODE=1 \
      NODE_CONTRACT_REGRESSION_WORKFLOW="$fixture/regression.yml" \
      NODE_CONTRACT_STATIC_ANALYSIS_WORKFLOW="$fixture/static-analysis.yml" \
      bash "${BASH_SOURCE[0]}" >"$fixture/output" 2>&1
  }

  expect_rejected() {
    local label=$1 assertion=$2
    if run_fixture; then
      fail "fixture accepted $label"
    fi
    grep -qF -- "$assertion" "$fixture/output" || {
      cat "$fixture/output" >&2
      fail "fixture rejected $label without the expected assertion"
    }
    cp -- "$regression" "$fixture/regression.yml"
    cp -- "$static_analysis" "$fixture/static-analysis.yml"
  }

  sed -i "s/node-version: '22'/node-version: '22.x'/" "$fixture/regression.yml"
  expect_rejected "a non-literal major boundary" \
    "regression.yml job assets must pin node-version '22' exactly once (found 0)"

  sed -i '0,/actions\/setup-node@/s/@[0-9a-f]\{40\}/@0000000000000000000000000000000000000000/' \
    "$fixture/regression.yml"
  expect_rejected "a mismatched action revision" \
    "regression.yml job assets must pin actions/setup-node to $setup_node"

  sed -i '0,/package-manager-cache: false/s/package-manager-cache: false/package-manager-cache: true/' \
    "$fixture/regression.yml"
  expect_rejected "implicit package-manager cache behavior" \
    'regression.yml job assets must disable setup-node package-manager-cache exactly once (found 0)'

  boundary_block=$(sed -n '/      # actions\/setup-node v6.3.0/,+4p' "$fixture/regression.yml")
  sed -i '/      # actions\/setup-node v6.3.0/,+4d' "$fixture/regression.yml"
  awk -v block="$boundary_block" '
    /^  static:$/ { in_static = 1 }
    in_static && /^    steps:$/ { print; print block; in_static = 0; next }
    { print }
  ' "$fixture/regression.yml" >"$fixture/regression.with-boundary.yml"
  mv -- "$fixture/regression.with-boundary.yml" "$fixture/regression.yml"
  expect_rejected "an identical pin in the neighbouring static job boundary" \
    'regression.yml job assets must use setup-node exactly once (found 0)'
fi

printf "Node workflow contract passed (assets and refactor-metrics use Node %s).\n" "$node_major"
