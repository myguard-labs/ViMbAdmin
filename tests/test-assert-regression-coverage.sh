#!/usr/bin/env bash
set -euo pipefail

# Source the new controls without another shell/interpreter startup.
# shellcheck disable=SC1091 # This CI helper is explicitly linted with its caller.
source ci/test-regression-browser-ownership.sh

# Proves the coverage guard reads the unit runner's exclusions rather than a
# duplicated list. The mutation below must leave test-date.php unowned and
# therefore make the guard fail.
guard=$PWD/tests/assert-regression-coverage.sh
runner=$PWD/tests/run-unit-tests.sh
phpstan_runner=$PWD/tests/run-phpstan-tests.sh
test_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-regression-coverage.XXXXXX")

cleanup() {
  rm -rf -- "${test_root:?}"
}
trap cleanup EXIT

mkdir -p "$test_root/tests" "$test_root/.github/workflows"
cp -- "$guard" "$runner" "$phpstan_runner" "$test_root/tests/"
chmod +x "$test_root/tests/assert-regression-coverage.sh" \
  "$test_root/tests/run-unit-tests.sh" \
  "$test_root/tests/run-phpstan-tests.sh"

cat >"$test_root/.github/workflows/ci.yml" <<'YAML'
name: Dependency fixture
on: push
jobs:
  lint:
    runs-on: ubuntu-latest
    steps:
      - run: php ci/test-dependency-refresh.php
YAML
cp -- "$test_root/.github/workflows/ci.yml" "$test_root/.github/workflows/ci.yml.valid"

cat >"$test_root/.github/workflows/regression.yml" <<'YAML'
name: Regression fixture
on: push
jobs:
  static:
    runs-on: ubuntu-latest
    steps:
      - run: node --version
      - run: bash tests/test-resolve-bundle-v.sh
      - run: node ci/test-refactor-ui-events.cjs
      - run: bash ci/test-asset-cache-version-boundary.sh
  unit:
    runs-on: ubuntu-latest
    steps:
      - run: bash tests/run-unit-tests.sh
      - run: php ci/test-refactor-runtime-baseline.php
      - run: php ci/test-refactor-input-contract.php
      - run: php ci/test-refactor-autoload-contract.php
      - run: php ci/test-refactor-list-contract.php
      - run: php ci/test-refactor-doveadm-contract.php
      - run: php ci/test-refactor-queue-database-guard.php
      - run: php ci/test-refactor-controller-resources.php
      - run: php ci/test-refactor-list-templates.php
  cache:
    runs-on: ubuntu-latest
    steps:
      - run: php tests/test-cache-bootstrap.php
      - run: php tests/test-kernel-em-factory.php
      - run: php tests/test-kernel-smarty-view.php
      - run: php tests/test-oss-message.php
  schema-drift:
    runs-on: ubuntu-latest
    steps:
      - run: bash ci/test-refactor-runtime-port.sh
      - run: php tests/test-schema-no-pending.php
      - run: php ci/test-refactor-preference-contract.php
      - run: php ci/test-refactor-queue-lifecycle.php
      - run: php ci/test-refactor-service-audit-log.php
  mailbox-queue:
    runs-on: ubuntu-latest
    steps:
      - run: php tests/test-mailbox-queue-atomic-mariadb.php
YAML

cat >"$test_root/.github/workflows/static-analysis.yml" <<'YAML'
name: Static-analysis fixture
on: push
jobs:
  static:
    runs-on: ubuntu-latest
    steps:
      - name: Trust checkout for tracked-file and base operations
        run: |
          git config --global --add safe.directory "$GITHUB_WORKSPACE"
          git -C "$GITHUB_WORKSPACE" rev-parse --is-inside-work-tree >/dev/null
      - name: Select immutable PHPStan base
        env:
          PHPSTAN_PULL_REQUEST_BASE_SHA: ${{ github.event.pull_request.base.sha }}
          PHPSTAN_PUSH_BEFORE_SHA: ${{ github.event.before }}
          PHPSTAN_WORKFLOW_SHA: ${{ github.sha }}
        run: |
          case "$GITHUB_EVENT_NAME" in
            pull_request) base="$PHPSTAN_PULL_REQUEST_BASE_SHA" ;;
            push) base="$PHPSTAN_PUSH_BEFORE_SHA" ;;
            workflow_dispatch) base="$PHPSTAN_WORKFLOW_SHA" ;;
            *)
              echo "PHPStan immutable base revision is unavailable for $GITHUB_EVENT_NAME" >&2
              exit 2
              ;;
          esac
          if [ -z "$base" ]; then
            echo "PHPStan immutable base revision is unavailable" >&2
            exit 2
          fi
          printf 'PHPSTAN_BASE_SHA=%s\n' "$base" >>"$GITHUB_ENV"
      - name: Fetch PHPStan pull-request base
        if: github.event_name == 'pull_request'
        env:
          PHPSTAN_BASE_SHA: ${{ github.event.pull_request.base.sha }}
        run: git fetch --no-tags --depth=1 origin "$PHPSTAN_BASE_SHA"
      - name: Assert every tracked PHP test has a CI owner
        run: bash tests/assert-regression-coverage.sh
      - run: bash tests/run-phpstan-tests.sh
      - run: php ci/test-formatter-selection.php
      - run: bash ci/test-refactor-metrics.sh
YAML

cp -- "$test_root/.github/workflows/regression.yml" "$test_root/regression.yml.valid"
cp -- "$test_root/.github/workflows/static-analysis.yml" "$test_root/static-analysis.yml.valid"

for test in \
  test-cache-bootstrap.php \
  test-kernel-em-factory.php \
  test-kernel-smarty-view.php \
  test-mailbox-queue-atomic-mariadb.php \
  test-oss-message.php \
  test-schema-no-pending.php \
  test-date.php; do
  printf '%s\n' '<?php' >"$test_root/tests/$test"
done
git -C "$test_root" init -q
git -C "$test_root" config user.email 'test@example.invalid'
git -C "$test_root" config user.name 'Regression coverage test'
git -C "$test_root" add tests .github regression.yml.valid static-analysis.yml.valid
git -C "$test_root" commit -q --no-gpg-sign -m fixture

# A mutation that matches nothing is a silent no-op: the guard is then handed an
# unmodified runner and "passes" while asserting nothing. That regression has
# happened once, when the runner's invocation grew a `tee` pipeline and two seds
# below stopped matching. Fail loudly instead of asserting on a no-op.
mutate_runner() {
  local expression=$1 target="$test_root/tests/run-unit-tests.sh"

  cp -- "$target" "$test_root/runner-before-mutation"
  sed -i "$expression" "$target"
  if cmp -s "$test_root/runner-before-mutation" "$target"; then
    printf 'Self-test mutation matched nothing (runner shape changed?): %s\n' \
      "$expression" >&2
    exit 1
  fi
}

run_guard() {
  if command -v actionlint >/dev/null; then
    actionlint "$test_root/.github/workflows/regression.yml" \
      "$test_root/.github/workflows/static-analysis.yml" \
      >"$test_root/actionlint-output" 2>&1 || {
      cat "$test_root/actionlint-output" >&2
      exit 1
    }
  fi
  if (cd "$test_root" && bash tests/assert-regression-coverage.sh) >"$test_root/output" 2>&1; then
    guard_status=0
  else
    guard_status=$?
  fi
}

fixture_status=$(git -C "$test_root" status --short)
if [[ -n $fixture_status ]]; then
  printf 'Fixture unexpectedly became dirty.\n' >&2
  exit 1
fi
run_guard
[[ $guard_status -eq 0 ]] || {
  cat "$test_root/output" >&2
  exit 1
}

cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i '/git config --global --add safe.directory/d' \
  "$test_root/.github/workflows/static-analysis.yml"
run_guard
[[ $guard_status -ne 0 ]] || {
  printf 'Coverage guard accepted PHPStan tracked-file discovery without Git trust.\n' >&2
  exit 1
}
grep -qF 'Static-analysis workflow must trust Git before tracked-file discovery.' \
  "$test_root/output" || {
  cat "$test_root/output" >&2
  exit 1
}

for event_shape in \
  "pull_request) base=\"\$PHPSTAN_PULL_REQUEST_BASE_SHA\"" \
  "push) base=\"\$PHPSTAN_PUSH_BEFORE_SHA\"" \
  "workflow_dispatch) base=\"\$PHPSTAN_WORKFLOW_SHA\""; do
  cp -- "$test_root/static-analysis.yml.valid" \
    "$test_root/.github/workflows/static-analysis.yml"
  sed -i "\\|$event_shape|d" \
    "$test_root/.github/workflows/static-analysis.yml"
  run_guard
  [[ $guard_status -ne 0 ]] || {
    printf 'Coverage guard accepted PHPStan without event base shape: %s\n' \
      "$event_shape" >&2
    exit 1
  }
  grep -qF 'Static-analysis workflow is missing PHPStan base component:' \
    "$test_root/output" || {
    cat "$test_root/output" >&2
    exit 1
  }
done

cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"

# shellcheck disable=SC2016 # Match the runner's literal loop variable.
mutate_runner '/^[[:space:]]*php "\$test" | tee "\$test_output"$/d'
run_guard
[[ $guard_status -ne 0 ]] || {
  printf 'Coverage guard accepted a unit runner that discovers tests without executing them.\n' >&2
  exit 1
}
grep -qF 'Unit test runner discovery or requested subset is incorrect.' \
  "$test_root/output" || {
  cat "$test_root/output" >&2
  exit 1
}
cp -- "$runner" "$test_root/tests/run-unit-tests.sh"

cat >"$test_root/tests/run-unit-tests.sh" <<'SH'
#!/usr/bin/env bash
set -euo pipefail

readonly excluded_tests=(
  tests/test-cache-bootstrap.php
  tests/test-kernel-em-factory.php
  tests/test-kernel-smarty-view.php
  tests/test-oss-message.php
  tests/test-schema-no-pending.php
)
if [[ ${1:-} == --print-excluded-tests ]]; then
  printf '%s\n' "${excluded_tests[@]}"
  exit 0
fi

# git ls-files 'tests/test-*.php'
if false; then
  test=tests/test-runner-contract.php
  php "$test"
fi
SH
chmod +x "$test_root/tests/run-unit-tests.sh"
run_guard
[[ $guard_status -ne 0 ]] || {
  printf 'Coverage guard accepted discovery and execution prose in dead code.\n' >&2
  exit 1
}
grep -qF 'Unit test runner discovery or requested subset is incorrect.' \
  "$test_root/output" || {
  cat "$test_root/output" >&2
  exit 1
}
cp -- "$runner" "$test_root/tests/run-unit-tests.sh"

mutate_runner "s|git ls-files 'tests/test-\\*.php'|find tests -name 'test-*.php' -print|"
run_guard
[[ $guard_status -ne 0 ]] || {
  printf 'Coverage guard accepted filesystem discovery of an untracked test.\n' >&2
  exit 1
}
grep -qF 'Unit test runner discovery or requested subset is incorrect.' \
  "$test_root/output" || {
  cat "$test_root/output" >&2
  exit 1
}
cp -- "$runner" "$test_root/tests/run-unit-tests.sh"

# A runner that ignores an explicit subset still discovers the full manifest,
# but defeats focused local and CI invocations. The executable contract must
# reject that regression rather than accepting the discovery loop alone.
mutate_runner '/^[[:space:]]*selected_tests=("\$@")$/d'
run_guard
[[ $guard_status -ne 0 ]] || {
  printf 'Coverage guard accepted a unit runner that ignores its requested subset.\n' >&2
  exit 1
}
grep -qF 'Unit test runner discovery or requested subset is incorrect.' \
  "$test_root/output" || {
  cat "$test_root/output" >&2
  exit 1
}
cp -- "$runner" "$test_root/tests/run-unit-tests.sh"

# shellcheck disable=SC2016 # Duplicate the literal runner invocation.
mutate_runner '/^[[:space:]]*php "$test" | tee "$test_output"$/a\  php "$test" | tee -a "$test_output"'
run_guard
[[ $guard_status -ne 0 ]] || {
  printf 'Coverage guard accepted duplicate execution of a tracked test.\n' >&2
  exit 1
}
grep -qF 'Unit test runner discovery or requested subset is incorrect.' \
  "$test_root/output" || {
  cat "$test_root/output" >&2
  exit 1
}
cp -- "$runner" "$test_root/tests/run-unit-tests.sh"

expect_unreachable() {
  local test=$1

  run_guard
  [[ $guard_status -ne 0 ]] || {
    printf 'Coverage guard accepted an unexecuted test: %s\n' "$test" >&2
    exit 1
  }
  grep -qF "Dedicated regression test is not reachable: $test" \
    "$test_root/output" || {
    cat "$test_root/output" >&2
    exit 1
  }
}

expect_owner_rejected() {
  local label=$1 message=$2

  run_guard
  [[ $guard_status -ne 0 ]] || {
    printf 'Coverage guard accepted a disabled owner: %s\n' "$label" >&2
    exit 1
  }
  grep -qF "$message" "$test_root/output" || {
    cat "$test_root/output" >&2
    exit 1
  }
}

# This owner must run in the supported PHP unit matrix, not just somewhere in
# the workflow. Each defect is observed by the actual maintained guard, then
# restored to a green fixture before trying the next independent defect.
for input_owner_defect in missing conditional masked echoed wrong-job wrong-workflow conditional-job; do
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  case "$input_owner_defect" in
  missing)
    sed -i '\|run: php ci/test-refactor-input-contract.php|d' "$test_root/.github/workflows/regression.yml"
    ;;
  conditional)
    sed -i '\|run: php ci/test-refactor-input-contract.php|a\        if: false' "$test_root/.github/workflows/regression.yml"
    ;;
  masked)
    sed -i '\|run: php ci/test-refactor-input-contract.php|a\        continue-on-error: true' "$test_root/.github/workflows/regression.yml"
    ;;
  echoed)
    sed -i 's|run: php ci/test-refactor-input-contract.php|run: echo php ci/test-refactor-input-contract.php|' "$test_root/.github/workflows/regression.yml"
    ;;
  wrong-job)
    sed -i '\|run: php ci/test-refactor-input-contract.php|d; /run: php tests\/test-cache-bootstrap.php/a\      - run: php ci/test-refactor-input-contract.php' "$test_root/.github/workflows/regression.yml"
    ;;
  wrong-workflow)
    sed -i '\|run: php ci/test-refactor-input-contract.php|d' "$test_root/.github/workflows/regression.yml"
    sed -i '/run: php ci\/test-formatter-selection.php/a\      - run: php ci/test-refactor-input-contract.php' "$test_root/.github/workflows/static-analysis.yml"
    ;;
  conditional-job)
    sed -i '/^  unit:$/a\    if: false' "$test_root/.github/workflows/regression.yml"
    ;;
  esac
  if cmp -s "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"; then
    printf 'Controller input owner mutation matched nothing: %s\n' "$input_owner_defect" >&2
    exit 1
  fi
  expect_owner_rejected "controller input $input_owner_defect" \
    'Regression unit job does not invoke the controller string input contract.'
  printf 'Controller input owner rejected %s (guard exit %d).\n' "$input_owner_defect" "$guard_status"
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  run_guard
  [[ $guard_status -eq 0 ]] || {
    cat "$test_root/output" >&2
    exit 1
  }
done

# VC-S01's retained-adapter discovery contract must remain an unconditional
# owner in the supported PHP unit matrix. Each defect is observed by the
# maintained coverage guard, then the exact valid fixture is restored.
for autoload_owner_defect in missing conditional masked echoed wrong-job wrong-workflow; do
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  case "$autoload_owner_defect" in
  missing)
    sed -i '\|run: php ci/test-refactor-autoload-contract.php|d' "$test_root/.github/workflows/regression.yml"
    ;;
  conditional)
    sed -i '\|run: php ci/test-refactor-autoload-contract.php|a\        if: false' "$test_root/.github/workflows/regression.yml"
    ;;
  masked)
    sed -i '\|run: php ci/test-refactor-autoload-contract.php|a\        continue-on-error: true' "$test_root/.github/workflows/regression.yml"
    ;;
  echoed)
    sed -i 's|run: php ci/test-refactor-autoload-contract.php|run: echo php ci/test-refactor-autoload-contract.php|' "$test_root/.github/workflows/regression.yml"
    ;;
  wrong-job)
    sed -i '\|run: php ci/test-refactor-autoload-contract.php|d; /run: php tests\/test-cache-bootstrap.php/a\      - run: php ci/test-refactor-autoload-contract.php' "$test_root/.github/workflows/regression.yml"
    ;;
  wrong-workflow)
    sed -i '\|run: php ci/test-refactor-autoload-contract.php|d' "$test_root/.github/workflows/regression.yml"
    sed -i '/run: php ci\/test-formatter-selection.php/a\      - run: php ci/test-refactor-autoload-contract.php' "$test_root/.github/workflows/static-analysis.yml"
    ;;
  esac
  if cmp -s "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"; then
    printf 'Autoload discovery owner mutation matched nothing: %s\n' "$autoload_owner_defect" >&2
    exit 1
  fi
  expect_owner_rejected "autoload discovery $autoload_owner_defect" \
    'Regression unit job does not invoke the autoload discovery contract.'
  printf 'Autoload discovery owner rejected %s (guard exit %d).\n' "$autoload_owner_defect" "$guard_status"
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  run_guard
  [[ $guard_status -eq 0 ]] || {
    cat "$test_root/output" >&2
    exit 1
  }
done

# VC-C03's source-row contract must remain an unconditional unit-matrix owner.
# Each named defect is observed red through the maintained coverage guard and
# the exact valid fixture is restored before the next mutation.
for list_owner_defect in missing conditional masked echoed wrong-job wrong-workflow; do
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  case "$list_owner_defect" in
  missing)
    sed -i '\|run: php ci/test-refactor-list-contract.php|d' "$test_root/.github/workflows/regression.yml"
    ;;
  conditional)
    sed -i '\|run: php ci/test-refactor-list-contract.php|a\        if: false' "$test_root/.github/workflows/regression.yml"
    ;;
  masked)
    sed -i '\|run: php ci/test-refactor-list-contract.php|a\        continue-on-error: true' "$test_root/.github/workflows/regression.yml"
    ;;
  echoed)
    sed -i 's|run: php ci/test-refactor-list-contract.php|run: echo php ci/test-refactor-list-contract.php|' "$test_root/.github/workflows/regression.yml"
    ;;
  wrong-job)
    sed -i '\|run: php ci/test-refactor-list-contract.php|d; /run: php tests\/test-cache-bootstrap.php/a\      - run: php ci/test-refactor-list-contract.php' "$test_root/.github/workflows/regression.yml"
    ;;
  wrong-workflow)
    sed -i '\|run: php ci/test-refactor-list-contract.php|d' "$test_root/.github/workflows/regression.yml"
    sed -i '/run: php ci\/test-formatter-selection.php/a\      - run: php ci/test-refactor-list-contract.php' "$test_root/.github/workflows/static-analysis.yml"
    ;;
  esac
  if cmp -s "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"; then
    printf 'List pagination owner mutation matched nothing: %s\n' "$list_owner_defect" >&2
    exit 1
  fi
  expect_owner_rejected "list pagination $list_owner_defect" \
    'Regression unit job does not invoke the list pagination contract.'
  printf 'List pagination owner rejected %s (guard exit %d).\n' "$list_owner_defect" "$guard_status"
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  run_guard
  [[ $guard_status -eq 0 ]] || {
    cat "$test_root/output" >&2
    exit 1
  }
done

# VC-Q03's backend-result characterization must remain an unconditional owner
# in the supported PHP unit matrix. Observe the missing, conditional and
# misdirected owner shapes, restoring the exact valid fixture after each one.
for doveadm_owner_defect in missing conditional wrong-job; do
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  case "$doveadm_owner_defect" in
  missing)
    sed -i '\|run: php ci/test-refactor-doveadm-contract.php|d' "$test_root/.github/workflows/regression.yml"
    ;;
  conditional)
    sed -i '\|run: php ci/test-refactor-doveadm-contract.php|a\        if: false' "$test_root/.github/workflows/regression.yml"
    ;;
  wrong-job)
    sed -i '\|run: php ci/test-refactor-doveadm-contract.php|d; /run: php tests\/test-cache-bootstrap.php/a\      - run: php ci/test-refactor-doveadm-contract.php' "$test_root/.github/workflows/regression.yml"
    ;;
  esac
  if cmp -s "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"; then
    printf 'Doveadm transport owner mutation matched nothing: %s\n' "$doveadm_owner_defect" >&2
    exit 1
  fi
  expect_owner_rejected "Doveadm transport $doveadm_owner_defect" \
    'Regression unit job does not invoke the Doveadm transport contract.'
  printf 'Doveadm transport owner rejected %s (guard exit %d).\n' "$doveadm_owner_defect" "$guard_status"
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  run_guard
  [[ $guard_status -eq 0 ]] || {
    cat "$test_root/output" >&2
    exit 1
  }
done

# The destructive lifecycle fixture's target guard must remain an
# unconditional owner in the supported PHP unit matrix. Observe representative
# missing, conditional and misdirected owner shapes, restoring green each time.
for queue_database_guard_owner_defect in missing conditional wrong-job; do
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  case "$queue_database_guard_owner_defect" in
  missing)
    sed -i '\|run: php ci/test-refactor-queue-database-guard.php|d' "$test_root/.github/workflows/regression.yml"
    ;;
  conditional)
    sed -i '\|run: php ci/test-refactor-queue-database-guard.php|a\        if: false' "$test_root/.github/workflows/regression.yml"
    ;;
  wrong-job)
    sed -i '\|run: php ci/test-refactor-queue-database-guard.php|d; /run: php tests\/test-cache-bootstrap.php/a\      - run: php ci/test-refactor-queue-database-guard.php' "$test_root/.github/workflows/regression.yml"
    ;;
  esac
  if cmp -s "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"; then
    printf 'Queue database guard owner mutation matched nothing: %s\n' "$queue_database_guard_owner_defect" >&2
    exit 1
  fi
  expect_owner_rejected "queue database guard $queue_database_guard_owner_defect" \
    'Regression unit job does not invoke the queue database guard contract.'
  printf 'Queue database guard owner rejected %s (guard exit %d).\n' \
    "$queue_database_guard_owner_defect" "$guard_status"
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  run_guard
  [[ $guard_status -eq 0 ]] || {
    cat "$test_root/output" >&2
    exit 1
  }
done

# The four-controller concrete-resource contract must remain an unconditional
# owner in the supported PHP unit matrix. Observe missing, conditional and
# misdirected owner shapes, restoring green each time.
for controller_resource_owner_defect in missing conditional wrong-job; do
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  case "$controller_resource_owner_defect" in
  missing)
    sed -i '\|run: php ci/test-refactor-controller-resources.php|d' "$test_root/.github/workflows/regression.yml"
    ;;
  conditional)
    sed -i '\|run: php ci/test-refactor-controller-resources.php|a\        if: false' "$test_root/.github/workflows/regression.yml"
    ;;
  wrong-job)
    sed -i '\|run: php ci/test-refactor-controller-resources.php|d; /run: php tests\/test-cache-bootstrap.php/a\      - run: php ci/test-refactor-controller-resources.php' "$test_root/.github/workflows/regression.yml"
    ;;
  esac
  if cmp -s "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"; then
    printf 'Concrete controller resource owner mutation matched nothing: %s\n' \
      "$controller_resource_owner_defect" >&2
    exit 1
  fi
  expect_owner_rejected "concrete controller resource $controller_resource_owner_defect" \
    'Regression unit job does not invoke the concrete controller resource contract.'
  printf 'Concrete controller resource owner rejected %s (guard exit %d).\n' \
    "$controller_resource_owner_defect" "$guard_status"
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  run_guard
  [[ $guard_status -eq 0 ]] || {
    cat "$test_root/output" >&2
    exit 1
  }
done

# VC-S02's real Smarty render contract must remain an unconditional owner in
# the supported PHP unit matrix. Observe missing, conditional and misdirected
# owner shapes, restoring the exact valid fixture after each mutation.
for list_template_owner_defect in missing conditional wrong-job; do
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  case "$list_template_owner_defect" in
  missing)
    sed -i '\|run: php ci/test-refactor-list-templates.php|d' "$test_root/.github/workflows/regression.yml"
    ;;
  conditional)
    sed -i '\|run: php ci/test-refactor-list-templates.php|a\        if: false' "$test_root/.github/workflows/regression.yml"
    ;;
  wrong-job)
    sed -i '\|run: php ci/test-refactor-list-templates.php|d; /run: php tests\/test-cache-bootstrap.php/a\      - run: php ci/test-refactor-list-templates.php' "$test_root/.github/workflows/regression.yml"
    ;;
  esac
  if cmp -s "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"; then
    printf 'List-template owner mutation matched nothing: %s\n' \
      "$list_template_owner_defect" >&2
    exit 1
  fi
  expect_owner_rejected "list-template $list_template_owner_defect" \
    'Regression unit job does not invoke the list-template render contract.'
  printf 'List-template owner rejected %s (guard exit %d).\n' \
    "$list_template_owner_defect" "$guard_status"
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  run_guard
  [[ $guard_status -eq 0 ]] || {
    cat "$test_root/output" >&2
    exit 1
  }
done

# VC-E02's real persistence contract must remain an unconditional owner in the
# MariaDB-backed schema job. Each disabled/misdirected shape is observed red,
# then the exact valid fixture is restored before the next mutation.
for preference_owner_defect in missing conditional masked echoed wrong-job wrong-workflow conditional-job; do
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  case "$preference_owner_defect" in
  missing)
    sed -i '\|run: php ci/test-refactor-preference-contract.php|d' "$test_root/.github/workflows/regression.yml"
    ;;
  conditional)
    sed -i '\|run: php ci/test-refactor-preference-contract.php|a\        if: false' "$test_root/.github/workflows/regression.yml"
    ;;
  masked)
    sed -i '\|run: php ci/test-refactor-preference-contract.php|a\        continue-on-error: true' "$test_root/.github/workflows/regression.yml"
    ;;
  echoed)
    sed -i 's|run: php ci/test-refactor-preference-contract.php|run: echo php ci/test-refactor-preference-contract.php|' "$test_root/.github/workflows/regression.yml"
    ;;
  wrong-job)
    sed -i '\|run: php ci/test-refactor-preference-contract.php|d; /run: php tests\/test-cache-bootstrap.php/a\      - run: php ci/test-refactor-preference-contract.php' "$test_root/.github/workflows/regression.yml"
    ;;
  wrong-workflow)
    sed -i '\|run: php ci/test-refactor-preference-contract.php|d' "$test_root/.github/workflows/regression.yml"
    sed -i '/run: php ci\/test-formatter-selection.php/a\      - run: php ci/test-refactor-preference-contract.php' "$test_root/.github/workflows/static-analysis.yml"
    ;;
  conditional-job)
    sed -i '/^  schema-drift:$/a\    if: false' "$test_root/.github/workflows/regression.yml"
    ;;
  esac
  if cmp -s "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"; then
    printf 'Preference reuse owner mutation matched nothing: %s\n' "$preference_owner_defect" >&2
    exit 1
  fi
  expect_owner_rejected "preference reuse $preference_owner_defect" \
    'Regression schema-drift job does not invoke the preference reuse contract.'
  printf 'Preference reuse owner rejected %s (guard exit %d).\n' "$preference_owner_defect" "$guard_status"
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  run_guard
  [[ $guard_status -eq 0 ]] || {
    cat "$test_root/output" >&2
    exit 1
  }
done

# VC-Q01's execution characterization must remain an unconditional owner in
# the MariaDB-backed schema job. Exercise the maintained coverage guard against
# the representative missing, conditional and misdirected owner shapes.
for queue_owner_defect in missing conditional wrong-job; do
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  case "$queue_owner_defect" in
  missing)
    sed -i '\|run: php ci/test-refactor-queue-lifecycle.php|d' "$test_root/.github/workflows/regression.yml"
    ;;
  conditional)
    sed -i '\|run: php ci/test-refactor-queue-lifecycle.php|a\        if: false' "$test_root/.github/workflows/regression.yml"
    ;;
  wrong-job)
    sed -i '\|run: php ci/test-refactor-queue-lifecycle.php|d; /run: php tests\/test-cache-bootstrap.php/a\      - run: php ci/test-refactor-queue-lifecycle.php' "$test_root/.github/workflows/regression.yml"
    ;;
  esac
  if cmp -s "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"; then
    printf 'Queue lifecycle owner mutation matched nothing: %s\n' "$queue_owner_defect" >&2
    exit 1
  fi
  expect_owner_rejected "queue lifecycle $queue_owner_defect" \
    'Regression schema-drift job does not invoke the queue lifecycle contract.'
  printf 'Queue lifecycle owner rejected %s (guard exit %d).\n' "$queue_owner_defect" "$guard_status"
  cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
  cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
  run_guard
  [[ $guard_status -eq 0 ]] || {
    cat "$test_root/output" >&2
    exit 1
  }
done

sed -i "s|php tests/test-cache-bootstrap.php|php -r 'echo \\\"tests/test-cache-bootstrap.php\\\";'|" \
  "$test_root/.github/workflows/regression.yml"
expect_unreachable tests/test-cache-bootstrap.php

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|php tests/test-cache-bootstrap.php|php tests/test-cache-bootstrap.php.suffix|' \
  "$test_root/.github/workflows/regression.yml"
expect_unreachable tests/test-cache-bootstrap.php

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|bash tests/run-unit-tests.sh|echo disabled # tests/run-unit-tests.sh|' \
  "$test_root/.github/workflows/regression.yml"
run_guard
[[ $guard_status -ne 0 ]] || {
  printf 'Coverage guard accepted a disabled unit runner.\n' >&2
  exit 1
}
grep -qF 'Regression workflow does not invoke the unit test runner.' \
  "$test_root/output" || {
  cat "$test_root/output" >&2
  exit 1
}

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|bash tests/test-resolve-bundle-v.sh|echo disabled # tests/test-resolve-bundle-v.sh|' \
  "$test_root/.github/workflows/regression.yml"
run_guard
[[ $guard_status -ne 0 ]] || {
  printf 'Coverage guard accepted a disabled bundle resolver test.\n' >&2
  exit 1
}
grep -qF 'Regression workflow does not invoke the bundle resolver test.' \
  "$test_root/output" || {
  cat "$test_root/output" >&2
  exit 1
}

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i 's|bash tests/run-phpstan-tests.sh|echo tests/test-phpstan-*.php|' \
  "$test_root/.github/workflows/static-analysis.yml"
expect_owner_rejected 'disabled PHPStan test runner' \
  'Static-analysis workflow does not invoke the PHPStan test runner.'

cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i 's|php ci/test-formatter-selection.php|echo php ci/test-formatter-selection.php|' \
  "$test_root/.github/workflows/static-analysis.yml"
expect_owner_rejected 'disabled formatter selection regression' \
  'Static-analysis workflow does not invoke the formatter selection regression.'

cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i 's|bash ci/test-refactor-metrics.sh|echo bash ci/test-refactor-metrics.sh|' \
  "$test_root/.github/workflows/static-analysis.yml"
expect_owner_rejected 'disabled refactor metrics regression' \
  'Static-analysis workflow does not invoke the refactor metrics regression.'

cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|php ci/test-refactor-runtime-baseline.php|echo php ci/test-refactor-runtime-baseline.php|' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'disabled real adapter runtime baseline' \
  'Regression workflow does not invoke the real adapter runtime baseline.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i '\|run: bash ci/test-refactor-runtime-port.sh|d' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'missing runtime fixture port contract' \
  'Regression workflow does not invoke the runtime fixture port contract.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i '\|run: bash ci/test-refactor-runtime-port.sh|a\        if: false' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'conditional runtime fixture port contract' \
  'Regression workflow does not invoke the runtime fixture port contract.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i '\|run: bash ci/test-refactor-runtime-port.sh|d; /run: bash tests\/run-unit-tests.sh/a\      - run: bash ci/test-refactor-runtime-port.sh' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'runtime fixture port contract in wrong job' \
  'Regression workflow does not invoke the runtime fixture port contract.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|node ci/test-refactor-ui-events.cjs|echo node ci/test-refactor-ui-events.cjs|' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'disabled request-helper UI contract' \
  'Regression static job does not invoke the request-helper UI contract.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|      - run: node ci/test-refactor-ui-events.cjs|      - run: node ci/test-refactor-ui-events.cjs\n        if: false|' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'conditional request-helper UI contract' \
  'Regression static job does not invoke the request-helper UI contract.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i '\|      - run: node ci/test-refactor-ui-events.cjs|d; /run: bash tests\/run-unit-tests.sh/a\      - run: node ci/test-refactor-ui-events.cjs' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'request-helper UI contract in wrong job' \
  'Regression static job does not invoke the request-helper UI contract.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|bash ci/test-asset-cache-version-boundary.sh|echo bash ci/test-asset-cache-version-boundary.sh|' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'disabled stale-asset cache boundary' \
  'Regression static job does not invoke the stale-asset cache boundary.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|      - run: bash ci/test-asset-cache-version-boundary.sh|      - run: bash ci/test-asset-cache-version-boundary.sh\n        if: false|' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'conditional stale-asset cache boundary' \
  'Regression static job does not invoke the stale-asset cache boundary.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i '\|      - run: bash ci/test-asset-cache-version-boundary.sh|d; /run: bash tests\/run-unit-tests.sh/a\      - run: bash ci/test-asset-cache-version-boundary.sh' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'stale-asset cache boundary in wrong job' \
  'Regression static job does not invoke the stale-asset cache boundary.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|php ci/test-refactor-service-audit-log.php|echo php ci/test-refactor-service-audit-log.php|' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'disabled service audit-log contract' \
  'Regression schema-drift job does not invoke the service audit-log contract.'

cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|php tests/test-cache-bootstrap.php|echo php tests/test-cache-bootstrap.php|' \
  "$test_root/.github/workflows/regression.yml"
expect_unreachable tests/test-cache-bootstrap.php

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|      - run: bash tests/run-unit-tests.sh|      - run: bash tests/run-unit-tests.sh\n        if: ${{ false }}|' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'conditional unit runner' \
  'Regression workflow does not invoke the unit test runner.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|      - run: bash tests/run-unit-tests.sh|      - if: ${{ false }}\n        run: bash tests/run-unit-tests.sh|' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'unit runner with an initial conditional field' \
  'Regression workflow does not invoke the unit test runner.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|      - run: bash tests/run-unit-tests.sh|      - run: bash tests/run-unit-tests.sh\n        "if": false|' \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'unit runner with a quoted trailing condition' \
  'Regression workflow does not invoke the unit test runner.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i "s|      - run: bash tests/run-unit-tests.sh|      - 'if': false\\n        run: bash tests/run-unit-tests.sh|" \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'unit runner with a quoted initial condition' \
  'Regression workflow does not invoke the unit test runner.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|      - run: php tests/test-cache-bootstrap.php|      - run: php tests/test-cache-bootstrap.php\n        if: ${{ false }}|' \
  "$test_root/.github/workflows/regression.yml"
expect_unreachable tests/test-cache-bootstrap.php

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|      - run: php tests/test-cache-bootstrap.php|      - if: ${{ false }}\n        run: php tests/test-cache-bootstrap.php|' \
  "$test_root/.github/workflows/regression.yml"
expect_unreachable tests/test-cache-bootstrap.php

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i 's|      - run: php tests/test-cache-bootstrap.php|      - run: php tests/test-cache-bootstrap.php\n        "if": false|' \
  "$test_root/.github/workflows/regression.yml"
expect_unreachable tests/test-cache-bootstrap.php

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i "s|      - run: php tests/test-cache-bootstrap.php|      - 'if': false\\n        run: php tests/test-cache-bootstrap.php|" \
  "$test_root/.github/workflows/regression.yml"
expect_unreachable tests/test-cache-bootstrap.php

cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i 's|      - run: bash tests/run-phpstan-tests.sh|      - run: bash tests/run-phpstan-tests.sh\n        if: ${{ false }}|' \
  "$test_root/.github/workflows/static-analysis.yml"
expect_owner_rejected 'conditional PHPStan runner' \
  'Static-analysis workflow does not invoke the PHPStan test runner.'

cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i 's|      - run: bash tests/run-phpstan-tests.sh|      - if: ${{ false }}\n        run: bash tests/run-phpstan-tests.sh|' \
  "$test_root/.github/workflows/static-analysis.yml"
expect_owner_rejected 'PHPStan runner with an initial conditional field' \
  'Static-analysis workflow does not invoke the PHPStan test runner.'

cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i 's|      - run: bash tests/run-phpstan-tests.sh|      - run: bash tests/run-phpstan-tests.sh\n        "if": false|' \
  "$test_root/.github/workflows/static-analysis.yml"
expect_owner_rejected 'PHPStan runner with a quoted trailing condition' \
  'Static-analysis workflow does not invoke the PHPStan test runner.'

cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i "s|      - run: bash tests/run-phpstan-tests.sh|      - 'if': false\\n        run: bash tests/run-phpstan-tests.sh|" \
  "$test_root/.github/workflows/static-analysis.yml"
expect_owner_rejected 'PHPStan runner with a quoted initial condition' \
  'Static-analysis workflow does not invoke the PHPStan test runner.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
sed -i "s@      - run: bash tests/run-unit-tests.sh@      - run: |\\n          if [[ -n \${UNSET_ENV:-} ]]; then\\n            bash tests/run-unit-tests.sh\\n          fi@" \
  "$test_root/.github/workflows/regression.yml"
expect_owner_rejected 'unit runner shell conditional' \
  'Regression workflow does not invoke the unit test runner.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i "s@      - run: bash tests/run-phpstan-tests.sh@      - run: |\\n          if [[ -n \${UNSET_ENV:-} ]]; then\\n            bash tests/run-phpstan-tests.sh\\n          fi@" \
  "$test_root/.github/workflows/static-analysis.yml"
expect_owner_rejected 'PHPStan runner shell conditional' \
  'Static-analysis workflow does not invoke the PHPStan test runner.'

cp -- "$test_root/regression.yml.valid" "$test_root/.github/workflows/regression.yml"
cp -- "$test_root/static-analysis.yml.valid" "$test_root/.github/workflows/static-analysis.yml"
sed -i '/readonly excluded_tests=(/a\  tests/test-date.php' \
  "$test_root/tests/run-unit-tests.sh"
expect_unreachable tests/test-date.php

printf 'Regression coverage guard rejects an excluded test without an owner.\n'

# A disabled dependency refresh owner must not count as execution.
sed -i 's|php ci/test-dependency-refresh.php|echo php ci/test-dependency-refresh.php|' \
  "$test_root/.github/workflows/ci.yml"
run_guard
[[ $guard_status -ne 0 ]] || {
  printf 'Coverage guard accepted a disabled dependency refresh owner.\n' >&2
  exit 1
}
grep -qF 'CI workflow does not invoke the dependency refresh regression.' \
  "$test_root/output"
cp -- "$test_root/.github/workflows/ci.yml.valid" "$test_root/.github/workflows/ci.yml"

# The full unit run must execute its browser-diagnostic regression; requested
# PHP subsets must remain subsets. Exercise the maintained discovery probe.
cp -- "$runner" "$test_root/tests/run-unit-tests.sh"
mutate_runner 's|^  node ci/test-browser-auth-diagnostics.cjs$|  :|'
run_guard
[[ $guard_status -ne 0 ]] || {
  printf 'Coverage guard accepted a missing browser-diagnostic regression.\n' >&2
  exit 1
}
grep -qF 'Unit test runner discovery or requested subset is incorrect.' \
  "$test_root/output" || {
  cat "$test_root/output" >&2
  exit 1
}
cp -- "$runner" "$test_root/tests/run-unit-tests.sh"
