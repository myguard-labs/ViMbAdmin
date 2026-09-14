#!/usr/bin/env bash
set -euo pipefail

# Proves the coverage guard reads the unit runner's exclusions rather than a
# duplicated list. The mutation below must leave test-date.php unowned and
# therefore make the guard fail.
guard=$PWD/tests/assert-regression-coverage.sh
runner=$PWD/tests/run-unit-tests.sh
phpstan_runner=$PWD/tests/run-phpstan-tests.sh
test_root=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-regression-coverage.XXXXXX")

cleanup() {
  rm -rf -- "$test_root"
}
trap cleanup EXIT

mkdir -p "$test_root/tests" "$test_root/.github/workflows"
cp -- "$guard" "$runner" "$phpstan_runner" "$test_root/tests/"
chmod +x "$test_root/tests/assert-regression-coverage.sh" \
  "$test_root/tests/run-unit-tests.sh" \
  "$test_root/tests/run-phpstan-tests.sh"

cat >"$test_root/.github/workflows/regression.yml" <<'YAML'
name: Regression fixture
on: push
jobs:
  unit:
    runs-on: ubuntu-latest
    steps:
      - run: bash tests/run-unit-tests.sh
      - run: bash tests/test-resolve-bundle-v.sh
  cache:
    runs-on: ubuntu-latest
    steps:
      - run: php tests/test-cache-bootstrap.php
      - run: php tests/test-kernel-em-factory.php
      - run: php tests/test-kernel-smarty-view.php
      - run: php tests/test-oss-message.php
  schema:
    runs-on: ubuntu-latest
    steps:
      - run: php tests/test-schema-no-pending.php
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

if git -C "$test_root" status --short | grep -q .; then
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
