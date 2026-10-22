#!/usr/bin/env bash
# Exercise the exact disposable-source mutator with one, zero and two sites.
set -euo pipefail
cd "$(dirname "$0")/.."
control_dir=$(mktemp -d /tmp/vimbadmin-operations-mutation-control.XXXXXX)
trap 'rm -rf -- "${control_dir:?}"' EXIT
sed -n '/^apply_operations_rollback_mutation() {$/,/^}$/p' \
  tests/support/browser-application-fixture.sh >"$control_dir/function.sh"
# shellcheck disable=SC1091 # Extracted exact candidate function, in private state.
source "$control_dir/function.sh"
apply_operations_rollback_mutation src/Kernel/Controller/MaintenanceController.php "$control_dir/one.php"
[[ $(grep -cF "\$conn->commit();" "$control_dir/one.php") == 2 ]]
printf '%s\n' '<?php // absent rollback' >"$control_dir/zero.php"
sed -n 'p' src/Kernel/Controller/MaintenanceController.php \
  src/Kernel/Controller/MaintenanceController.php >"$control_dir/two.php"
for count in zero two; do
  status=0
  apply_operations_rollback_mutation "$control_dir/$count.php" "$control_dir/mutant.php" \
    >"$control_dir/$count.log" 2>&1 || status=$?
  if [[ $status == 0 ]] || ! grep -qxF 'FAIL: operations rollback mutation must replace exactly one rollback' "$control_dir/$count.log"; then
    echo 'FAIL: operations mutator did not diagnose a non-unique rollback' >&2
    exit 1
  fi
done
echo 'OK: operations rollback mutator accepts one site and rejects zero or two'
