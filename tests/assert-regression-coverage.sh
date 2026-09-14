#!/usr/bin/env bash
set -euo pipefail

# Keep the partition in one executable guard. A newly tracked test is either
# picked up by the unit glob, by the PHPStan glob, or rejected here when it is
# accidentally added to a dedicated-job exclusion without a corresponding CI
# command.
readonly unit_runner=tests/run-unit-tests.sh
readonly phpstan_runner=tests/run-phpstan-tests.sh
readonly static_workflow=.github/workflows/static-analysis.yml
readonly regression_workflow=.github/workflows/regression.yml

static_workflow_has_phpstan_runtime_contract() {
  local trust_line owner_line required

  trust_line=$(grep -nF \
    "git config --global --add safe.directory \"\$GITHUB_WORKSPACE\"" \
    "$static_workflow" | head -n1 | cut -d: -f1)
  owner_line=$(grep -nF \
    'name: Assert every tracked PHP test has a CI owner' \
    "$static_workflow" | head -n1 | cut -d: -f1)
  if [[ -z $trust_line || -z $owner_line || $trust_line -ge $owner_line ]]; then
    printf 'Static-analysis workflow must trust Git before tracked-file discovery.\n' \
      >&2
    return 1
  fi

  for required in \
    'name: Select immutable PHPStan base' \
    "pull_request) base=\"\$PHPSTAN_PULL_REQUEST_BASE_SHA\"" \
    "push) base=\"\$PHPSTAN_PUSH_BEFORE_SHA\"" \
    "workflow_dispatch) base=\"\$PHPSTAN_WORKFLOW_SHA\"" \
    'PHPStan immutable base revision is unavailable' \
    'PHPSTAN_BASE_SHA=' \
    "\$GITHUB_ENV" \
    "git fetch --no-tags --depth=1 origin \"\$PHPSTAN_BASE_SHA\""; do
    if ! grep -qF -- "$required" "$static_workflow"; then
      printf 'Static-analysis workflow is missing PHPStan base component: %s\n' \
        "$required" >&2
      return 1
    fi
  done
}

for runner in "$unit_runner" "$phpstan_runner"; do
  [[ -x $runner ]] || {
    printf 'Test runner is missing or not executable: %s\n' "$runner" >&2
    exit 1
  }
done

static_workflow_has_phpstan_runtime_contract || exit 1

unit_runner_executes_tracked_tests_and_subset() {
  local harness_root runner_path stub_path output
  local git_log php_log

  harness_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-unit-runner.XXXXXX")
  runner_path=$PWD/$unit_runner
  stub_path=$harness_root/bin
  output=$harness_root/output
  git_log=$harness_root/git.log
  php_log=$harness_root/php.log
  mkdir -p "$stub_path" "$harness_root/tests"
  : >"$git_log"
  : >"$php_log"
  : >"$harness_root/tests/test-untracked-sentinel.php"

  cat >"$stub_path/git" <<'SH'
#!/bin/sh
if [ "$#" -ne 2 ] || [ "$1" != ls-files ] || \
  [ "$2" != 'tests/test-*.php' ]; then
  printf 'Unexpected git argv in unit runner.\n' >&2
  exit 97
fi
printf '%s\n' 'ls-files tests/test-*.php' >> "$VIMBADMIN_RUNNER_GIT_LOG"
printf '%s\n' \
  'tests/test-runner-contract-b.php' \
  'tests/test-runner-contract-a.php'
SH

  cat >"$stub_path/php" <<'SH'
#!/bin/sh
if [ "$#" -ne 1 ]; then
  printf 'Unexpected php argv in unit runner: %s\n' "$*" >&2
  exit 98
fi
case "$1" in
  tests/test-runner-contract-a.php|tests/test-runner-contract-b.php) ;;
  *)
    printf 'Unexpected php argv in unit runner: %s\n' "$*" >&2
    exit 98
    ;;
esac
printf '%s\n' "$1" >> "$VIMBADMIN_RUNNER_PHP_LOG"
# The runner requires a terminal verdict line before it scores a test a pass,
# so the stub must emit one; this harness asserts discovery, not verdicts.
printf 'ALL PASSED\n'
SH
  chmod +x "$stub_path/git" "$stub_path/php"

  if ! (cd "$harness_root" &&
    PATH="$stub_path:/usr/bin:/bin" \
      VIMBADMIN_RUNNER_GIT_LOG="$git_log" \
      VIMBADMIN_RUNNER_PHP_LOG="$php_log" \
      "$runner_path") >"$output" 2>&1; then
    rm -rf -- "$harness_root"
    return 1
  fi

  if [[ $(wc -l <"$git_log") -ne 1 ]] ||
    ! grep -qxF 'ls-files tests/test-*.php' "$git_log" ||
    [[ $(wc -l <"$php_log") -ne 2 ]] ||
    [[ $(grep -cxF 'tests/test-runner-contract-a.php' "$php_log") -ne 1 ]] ||
    [[ $(grep -cxF 'tests/test-runner-contract-b.php' "$php_log") -ne 1 ]]; then
    rm -rf -- "$harness_root"
    return 1
  fi

  : >"$git_log"
  : >"$php_log"
  if ! (cd "$harness_root" &&
    PATH="$stub_path:/usr/bin:/bin" \
      VIMBADMIN_RUNNER_GIT_LOG="$git_log" \
      VIMBADMIN_RUNNER_PHP_LOG="$php_log" \
      "$runner_path" tests/test-runner-contract-b.php) \
    >"$output" 2>&1; then
    rm -rf -- "$harness_root"
    return 1
  fi

  if [[ $(wc -l <"$git_log") -ne 1 ]] ||
    [[ $(wc -l <"$php_log") -ne 1 ]] ||
    ! grep -qxF 'tests/test-runner-contract-b.php' "$php_log"; then
    rm -rf -- "$harness_root"
    return 1
  fi

  rm -rf -- "$harness_root"
}

if ! unit_runner_executes_tracked_tests_and_subset; then
  printf 'Unit test runner discovery or requested subset is incorrect.\n' >&2
  exit 1
fi

if ! grep -qF "git ls-files 'tests/test-phpstan-*.php'" "$phpstan_runner" ||
  ! grep -qF "php \"\$test\"" "$phpstan_runner"; then
  printf 'PHPStan test runner does not discover and execute tracked PHPStan tests.\n' >&2
  exit 1
fi

workflow_has_unconditional_owner_step() {
  local workflow=$1 kind=$2 target=$3

  awk -v kind="$kind" -v target="$target" '
    function indent(line, leading) {
      leading = line
      sub(/[^ ].*$/, "", leading)
      return length(leading)
    }
    function normalize(line) {
      sub(/[[:space:]]#.*/, "", line)
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", line)
      return line
    }
    function inspect_field(line) {
      line = normalize(line)
      if (line == "" || line ~ /^name:[[:space:]]*/ ||
          line ~ /^env:[[:space:]]*/) return
      if (line ~ /^run:[[:space:]]*/) {
        run_count++
        sub(/^run:[[:space:]]*/, "", line)
        run = line
        return
      }
      approved_shape = 0
    }
    function matches_owner(line, fields, count, field_index) {
      line = normalize(line)
      if (kind == "command") return line == target
      count = split(line, fields, /[[:space:]]+/)
      if (fields[1] != "php") return 0
      for (field_index = 2; field_index < count; field_index++) {
        if (fields[field_index] == "-d" || fields[field_index] == "--define") {
          field_index++
          continue
        }
        if (fields[field_index] ~ /^-d[^[:space:]]+$/ ||
            fields[field_index] ~ /^--define=[^[:space:]]+$/) continue
        return 0
      }
      return fields[count] == target
    }
    function finish_step() {
      if (in_step && approved_shape && run_count == 1 &&
          matches_owner(run)) found = 1
      in_step = 0
    }
    {
      current_indent = indent($0)

      if (in_step && $0 !~ /^[[:space:]]*$/ &&
          current_indent <= step_indent) finish_step()
      if (in_steps && $0 !~ /^[[:space:]]*$/ &&
          current_indent <= steps_indent) in_steps = 0

      if ($0 ~ /^[[:space:]]+steps:[[:space:]]*$/) {
        in_steps = 1
        steps_indent = current_indent
        next
      }
      if (!in_steps) next

      if (current_indent == steps_indent + 2 &&
          $0 ~ /^[[:space:]]+-[[:space:]]+/) {
        finish_step()
        in_step = 1
        step_indent = current_indent
        approved_shape = 1
        run_count = 0
        line = $0
        sub(/^[[:space:]]+-[[:space:]]+/, "", line)
        inspect_field(line)
        next
      }
      if (!in_step || current_indent != step_indent + 2) next
      inspect_field($0)
    }
    END {
      finish_step()
      exit found ? 0 : 1
    }
  ' "$workflow"
}

workflow_has_unconditional_owner_step "$regression_workflow" command \
  'bash tests/run-unit-tests.sh' || {
  printf 'Regression workflow does not invoke the unit test runner.\n' >&2
  exit 1
}
workflow_has_unconditional_owner_step "$regression_workflow" command \
  'bash tests/test-resolve-bundle-v.sh' || {
  printf 'Regression workflow does not invoke the bundle resolver test.\n' >&2
  exit 1
}
workflow_has_unconditional_owner_step "$static_workflow" command \
  'bash tests/run-phpstan-tests.sh' || {
  printf 'Static-analysis workflow does not invoke the PHPStan test runner.\n' >&2
  exit 1
}

workflow_runs_php_test() {
  local test=$1

  workflow_has_unconditional_owner_step "$regression_workflow" php "$test"
}

manifest=$(mktemp)
excluded_manifest=$(mktemp)
cleanup() {
  rm -f "$manifest" "$excluded_manifest"
}
trap cleanup EXIT

if ! git ls-files 'tests/test-*.php' | sort >"$manifest"; then
  printf 'Could not enumerate tracked PHP tests.\n' >&2
  exit 1
fi

if ! "$unit_runner" --print-excluded-tests >"$excluded_manifest"; then
  printf 'Could not enumerate unit-runner exclusions.\n' >&2
  exit 1
fi

declare -A excluded_tests=()
while IFS= read -r test; do
  [[ -n $test ]] || {
    printf 'Unit test runner reported an empty exclusion.\n' >&2
    exit 1
  }
  [[ -z ${excluded_tests[$test]+x} ]] || {
    printf 'Unit test runner reports a duplicate exclusion: %s\n' "$test" >&2
    exit 1
  }
  grep -qxF "$test" "$manifest" || {
    printf 'Unit test runner excludes an untracked PHP test: %s\n' "$test" >&2
    exit 1
  }
  workflow_runs_php_test "$test" || {
    printf 'Dedicated regression test is not reachable: %s\n' "$test" >&2
    exit 1
  }
  excluded_tests[$test]=1
done <"$excluded_manifest"

while IFS= read -r test; do
  case "$test" in
  tests/test-phpstan-*.php)
    workflow_has_unconditional_owner_step "$static_workflow" command \
      'bash tests/run-phpstan-tests.sh' || exit 1
    ;;
  *)
    if [[ -n ${excluded_tests[$test]+x} ]]; then
      workflow_runs_php_test "$test" || exit 1
    else
      # The unit runner's tracked-test glob covers all remaining tests.
      grep -qF "git ls-files 'tests/test-*.php'" "$unit_runner" || exit 1
    fi
    ;;
  esac
done <"$manifest"

printf 'All tracked PHP regression tests are assigned to a CI job.\n'
