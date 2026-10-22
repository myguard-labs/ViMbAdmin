#!/usr/bin/env bash
# Exercise the exact disposable-source mutator with one, zero and two rollback sites.
set -euo pipefail
cd "$(dirname "$0")/.."
control_dir=$(mktemp -d /tmp/vimbadmin-operations-mutation-control.XXXXXX)
trap 'rm -rf -- "${control_dir:?}"' EXIT
# shellcheck disable=SC2218 # sed is temporarily shadowed later to inject the postcondition fault.
sed -n '/^apply_operations_rollback_mutation() {$/,/^}$/p' \
  tests/support/browser-application-fixture.sh >"$control_dir/function.sh"
# shellcheck disable=SC1091 # Extracted exact candidate function, in private state.
source "$control_dir/function.sh"
apply_operations_rollback_mutation src/Kernel/Controller/MaintenanceController.php "$control_dir/one.php"
source_commits=$(grep -oF "\$conn->commit();" src/Kernel/Controller/MaintenanceController.php | wc -l || true)
mutated_commits=$(grep -oF "\$conn->commit();" "$control_dir/one.php" | wc -l || true)
if ((mutated_commits != source_commits + 1)); then
  echo 'FAIL: operations mutator did not add one commit occurrence to the source baseline' >&2
  exit 1
fi
printf '%s\n' '<?php // absent rollback' >"$control_dir/zero.php"
# shellcheck disable=SC2218 # sed is temporarily shadowed later to inject the postcondition fault.
sed -n 'p' src/Kernel/Controller/MaintenanceController.php \
  src/Kernel/Controller/MaintenanceController.php >"$control_dir/two.php"
{
  # shellcheck disable=SC2016 # Emit literal PHP rollback statements into a duplicate-occurrence fixture.
  printf '%s\n' '<?php $conn->rollBack(); $conn->rollBack();'
} >"$control_dir/two-same-line.php"
for count in zero two two-same-line; do
  status=0
  apply_operations_rollback_mutation "$control_dir/$count.php" "$control_dir/mutant.php" \
    >"$control_dir/$count.log" 2>&1 || status=$?
  if [[ $status == 0 ]] || ! grep -qxF 'FAIL: operations rollback mutation must replace exactly one rollback' "$control_dir/$count.log"; then
    echo 'FAIL: operations mutator did not diagnose a non-unique rollback' >&2
    exit 1
  fi
done
status=0
apply_operations_rollback_mutation "$control_dir/missing-source.php" "$control_dir/missing-mutant.php" \
  >"$control_dir/missing-source.log" 2>&1 || status=$?
if [[ $status == 0 ]] || ! grep -qxF \
  'FAIL: operations rollback mutation could not read rollback occurrences in source (grep status 2)' \
  "$control_dir/missing-source.log"; then
  echo 'FAIL: operations mutator did not diagnose an unreadable source' >&2
  exit 1
fi
# shellcheck disable=SC2016 # Emit a literal PHP rollback for the postcondition fault.
{ printf '%s\n' '<?php $conn->rollBack();'; } >"$control_dir/postcondition.php"
# shellcheck disable=SC2317 # This bounded shadow is invoked by the helper during the test.
sed() {
  command cat "${@: -1}"
}
status=0
apply_operations_rollback_mutation "$control_dir/postcondition.php" \
  "$control_dir/postcondition-mutated.php" >"$control_dir/postcondition.log" 2>&1 || status=$?
unset -f sed
if [[ $status == 0 ]] || ! grep -qxF \
  'FAIL: operations rollback mutation must add exactly one commit and remove the rollback' \
  "$control_dir/postcondition.log"; then
  echo 'FAIL: operations mutator did not diagnose the controlled postcondition failure' >&2
  exit 1
fi
{
  printf '%s\n' '<?php'
  # shellcheck disable=SC2016 # Emit literal PHP statements into the occurrence-count boundary fixture.
  printf '%s\n' '$conn->commit(); $conn->rollBack();'
} >"$control_dir/same-line-commit-rollback.php"
apply_operations_rollback_mutation "$control_dir/same-line-commit-rollback.php" \
  "$control_dir/same-line-commit-rollback-mutated.php"
same_line_commits=$(grep -oF "\$conn->commit();" "$control_dir/same-line-commit-rollback-mutated.php" | wc -l || true)
if ((same_line_commits != 2)); then
  echo 'FAIL: operations mutator did not preserve both same-line commit occurrences' >&2
  exit 1
fi
if grep -qF "\$conn->rollBack();" "$control_dir/same-line-commit-rollback-mutated.php"; then
  echo 'FAIL: operations mutator left the rollback in the same-line commit boundary fixture' >&2
  exit 1
fi
{
  printf '%s\n' '<?php'
  # shellcheck disable=SC2016 # Emit literal PHP statements into the boundary fixture.
  printf '%s\n' '$conn->commit();' '$conn->commit();' '$conn->rollBack();'
} >"$control_dir/multiple-preexisting-commits.php"
apply_operations_rollback_mutation "$control_dir/multiple-preexisting-commits.php" \
  "$control_dir/multiple-preexisting-commits-mutated.php"
multiple_commits=$(grep -oF "\$conn->commit();" "$control_dir/multiple-preexisting-commits-mutated.php" | wc -l || true)
if ((multiple_commits != 3)); then
  echo 'FAIL: operations mutator did not preserve multiple preexisting commit occurrences' >&2
  exit 1
fi
if grep -qF "\$conn->rollBack();" "$control_dir/multiple-preexisting-commits-mutated.php"; then
  echo 'FAIL: operations mutator left the rollback in the multiple-commit boundary fixture' >&2
  exit 1
fi
echo 'OK: operations rollback mutator accepts one rollback, adds one commit, rejects zero or two'
