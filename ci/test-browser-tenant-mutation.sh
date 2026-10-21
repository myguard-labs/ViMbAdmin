#!/usr/bin/env bash
# The exact fixture mutator must reject a missing or duplicated target guard.
set -euo pipefail
cd "$(dirname "$0")/.."
control_dir=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-tenant-mutation-control.XXXXXX")
trap 'rm -rf -- "${control_dir:?}"' EXIT
sed -n '/^apply_domain_authorization_mutation() {$/,/^}$/p' \
  tests/support/browser-application-fixture.sh >"$control_dir/function.sh"
# shellcheck disable=SC1091 # Extracted exact candidate function in the private root.
source "$control_dir/function.sh"
apply_domain_authorization_mutation src/Kernel/Controller/DomainController.php "$control_dir/one.php"
[[ $(grep -cF 'if (false) {' "$control_dir/one.php") == 1 ]]
printf '%s\n' '<?php // no authorization guard' >"$control_dir/zero.php"
cat src/Kernel/Controller/DomainController.php src/Kernel/Controller/DomainController.php >"$control_dir/two.php"
for count in zero two; do
  status=0
  apply_domain_authorization_mutation "$control_dir/$count.php" "$control_dir/mutant.php" \
    >"$control_dir/$count.log" 2>&1 || status=$?
  if [[ $status == 0 ]] || ! grep -qxF 'FAIL: domain authorization mutation must replace exactly one guard' "$control_dir/$count.log"; then
    echo 'FAIL: mutator did not diagnose the non-unique guard' >&2
    exit 1
  fi
done
echo 'OK: tenant authorization mutator accepts one guard and diagnoses zero or two guards'
