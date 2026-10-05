#!/usr/bin/env bash
set -euo pipefail

readonly contract=$PWD/.github/scripts/assert-workflow-runtime.sh
readonly finding_contract=$PWD/.github/scripts/assert-semgrep-findings.sh
fixture_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-workflow-runtime.XXXXXX")
readonly fixture_root
readonly fixture=$fixture_root/security.yml
readonly regression_fixture=$fixture_root/regression.yml

cleanup() {
  rm -rf -- "$fixture_root"
}
trap cleanup EXIT

run_contract() {
  WORKFLOW_RUNTIME_SECURITY_WORKFLOW="$fixture" \
    WORKFLOW_RUNTIME_REGRESSION_WORKFLOW="$regression_fixture" \
    bash "$contract" \
    >"$fixture_root/output" 2>&1
}

expect_rejected() {
  local label=$1 message=$2

  if run_contract; then
    printf 'Workflow runtime contract accepted %s.\n' "$label" >&2
    exit 1
  fi
  grep -qF "$message" \
    "$fixture_root/output" || {
    cat "$fixture_root/output" >&2
    exit 1
  }
}

expect_actionlint_valid_rejected() {
  local label=$1 message=$2

  if command -v actionlint >/dev/null; then
    actionlint -ignore 'label ".*" is unknown' "$fixture" \
      >"$fixture_root/actionlint-output" 2>&1 || {
      cat "$fixture_root/actionlint-output" >&2
      exit 1
    }
  fi
  expect_rejected "$label" "$message"
}

cp -- .github/workflows/security.yml "$fixture"
cp -- .github/workflows/regression.yml "$regression_fixture"
run_contract

bash ci/test-workflow-timeouts.sh

cp -- .github/workflows/regression.yml "$regression_fixture"
sed -i '0,/mirror\.gcr\.io\/library\/php@sha256:/s//docker.io\/library\/php@sha256:/' \
  "$regression_fixture"
expect_rejected 'a runtime image outside the approved mirror' \
  'Workflow runtime images must use approved registry-mirror digests:'
cp -- .github/workflows/regression.yml "$regression_fixture"

sed -i '0,/^    container:$/ { /^    container:$/ { N; s/^    container:\n      image:.*$/    container: php:8.4/; } }' \
  "$regression_fixture"
grep -qFx '    container: php:8.4' "$regression_fixture" || {
  printf 'Scalar container fixture mutation did not apply.\n' >&2
  exit 1
}
if command -v actionlint >/dev/null; then
  actionlint "$regression_fixture"
fi
expect_rejected 'an actionlint-valid scalar container image' \
  'scalar or inline container syntax is unsupported'
cp -- .github/workflows/regression.yml "$regression_fixture"

sed -i '/      - name: Smarty 5 JS-template escaping/i\
      - name: Disallowed PHP setup action\
        uses: shivammathur/setup-php@bf6b4fbd49ca58e4608c9c89fba0b8d90bd2a39f\
' "$regression_fixture"
if command -v actionlint >/dev/null; then
  actionlint "$regression_fixture"
fi
expect_rejected 'an action blocked by repository policy' \
  'The repository action policy does not allow shivammathur/setup-php.'
cp -- .github/workflows/regression.yml "$regression_fixture"

sed -i '/          persist-credentials: false/c\        env:\n          persist-credentials: false' "$fixture"
expect_rejected 'persist-credentials under env' \
  'Security workflow checkout must disable persisted credentials.'

cp -- .github/workflows/security.yml "$fixture"
sed -i '/          persist-credentials: false/a\          persist-credentials: false' "$fixture"
expect_rejected 'duplicate persist-credentials input' \
  'Security workflow checkout must disable persisted credentials.'

cp -- .github/workflows/security.yml "$fixture"
sed -i '/          persist-credentials: false/d' "$fixture"
expect_actionlint_valid_rejected 'missing persist-credentials input' \
  'Security workflow checkout must disable persisted credentials.'

cp -- .github/workflows/security.yml "$fixture"
sed -i 's/^        with:$/        "with":/' "$fixture"
sed -i '/          fetch-depth: 0/{N;s/          fetch-depth: 0\n          persist-credentials: false/          '\''persist-credentials'\'': false\n          fetch-depth: 0/;}' "$fixture"
expect_actionlint_valid_rejected 'quoted and reordered checkout inputs' \
  'Security workflow checkout must disable persisted credentials.'

checkout_ref=$(sed -n \
  's/^      - uses: actions\/checkout@\([0-9a-f]\{40\}\)$/\1/p' \
  .github/workflows/security.yml)
readonly checkout_ref
cp -- .github/workflows/security.yml "$fixture"
sed -i "/          persist-credentials: false/a\\
      - name: Second checkout without credential hardening\\
        uses: actions/checkout@$checkout_ref" "$fixture"
expect_actionlint_valid_rejected 'name-first second checkout' \
  'Security workflow checkout must disable persisted credentials.'

cp -- .github/workflows/security.yml "$fixture"
sed -i '/        shell: bash/d' "$fixture"
expect_rejected 'missing Semgrep Bash shell default' \
  'Security Semgrep workflow must run every inline command with Bash.'

cp -- .github/workflows/security.yml "$fixture"
sed -i '/      - name: Test Semgrep baseline event policy/a\        shell: /bin/sh -e {0}' \
  "$fixture"
expect_actionlint_valid_rejected 'non-Bash Semgrep step override' \
  'Security Semgrep steps may only override their shell with Bash.'

cp -- .github/workflows/security.yml "$fixture"
sed -i '/      - name: Test Semgrep baseline event policy/a\        "shell": /bin/sh -e {0}' \
  "$fixture"
expect_actionlint_valid_rejected 'quoted non-Bash Semgrep step override' \
  'Security Semgrep steps may only override their shell with Bash.'

cp -- .github/workflows/security.yml "$fixture"
sed -i 's/^    steps:$/    "steps":/' "$fixture"
expect_actionlint_valid_rejected 'quoted Semgrep steps key' \
  'Security Semgrep steps may only override their shell with Bash.'

stub_path=$fixture_root/bin
finding_tmp=$fixture_root/finding-tmp
mv_failure_tmp=$fixture_root/mv-failure-tmp
mkdir -p "$stub_path" "$finding_tmp" "$mv_failure_tmp"

cat >"$stub_path/semgrep" <<'SH'
#!/bin/sh
output=
while [ "$#" -gt 0 ]; do
  if [ "$1" = --output ]; then
    shift
    output=${1:?missing Semgrep output path}
  fi
  shift
done
[ -n "$output" ] || exit 99
if [ "${VIMBADMIN_SEMGREP_FINDING:-0}" = 1 ]; then
  printf '%s\n' '{"results":[{"check_id":"fixture"}]}' > "$output"
else
  : > "$output"
fi
exit "${VIMBADMIN_SEMGREP_STATUS:?missing Semgrep status}"
SH

cat >"$stub_path/mv" <<'SH'
#!/bin/sh
if [ "${VIMBADMIN_MV_FAIL:-0}" = 1 ]; then
  shift
  printf '%s\n' "$1" >> "$VIMBADMIN_MV_LOG"
  exit 91
fi
PATH=/usr/bin:/bin
export PATH
exec mv "$@"
SH
chmod +x "$stub_path/semgrep" "$stub_path/mv"

run_finding_contract() {
  local temporary_dir=$1 status=$2 finding=$3 mv_fail=${4:-0}

  TMPDIR="$temporary_dir" \
    PATH="$stub_path:/usr/bin:/bin" \
    VIMBADMIN_SEMGREP_STATUS="$status" \
    VIMBADMIN_SEMGREP_FINDING="$finding" \
    VIMBADMIN_MV_FAIL="$mv_fail" \
    VIMBADMIN_MV_LOG="$fixture_root/mv.log" \
    bash "$finding_contract" >"$fixture_root/finding-output" 2>&1
}

run_finding_contract "$finding_tmp" 1 1

if run_finding_contract "$finding_tmp" 2 1; then
  printf 'Semgrep negative control accepted an execution error.\n' >&2
  exit 1
fi
grep -qF 'Semgrep negative control returned 2.' \
  "$fixture_root/finding-output" || {
  cat "$fixture_root/finding-output" >&2
  exit 1
}

if run_finding_contract "$finding_tmp" 1 0; then
  printf 'Semgrep negative control accepted an empty finding result.\n' >&2
  exit 1
fi
grep -qF 'Semgrep negative control produced no finding.' \
  "$fixture_root/finding-output" || {
  cat "$fixture_root/finding-output" >&2
  exit 1
}

: >"$fixture_root/mv.log"
if run_finding_contract "$mv_failure_tmp" 1 1 1; then
  printf 'Semgrep negative control accepted an mv failure.\n' >&2
  exit 1
fi
[[ $(wc -l <"$fixture_root/mv.log") -eq 1 ]] || {
  printf 'The injected mv failure did not identify exactly one fixture seed.\n' >&2
  exit 1
}
while IFS= read -r seed; do
  [[ $seed == "$mv_failure_tmp"/semgrep-negative.* ]] || {
    printf 'The injected mv failure did not identify a fixture seed: %s\n' \
      "$seed" >&2
    exit 1
  }
  [[ ! -e $seed ]] || {
    printf 'Semgrep fixture seed survived an mv failure: %s\n' "$seed" >&2
    exit 1
  }
done <"$fixture_root/mv.log"
if find "$mv_failure_tmp" -maxdepth 1 -name 'semgrep-negative.*' \
  -print -quit | grep -q .; then
  printf 'Semgrep temporary files survived an mv failure.\n' >&2
  exit 1
fi

printf 'Workflow runtime contract rejects unsafe checkout and Semgrep forms.\n'
