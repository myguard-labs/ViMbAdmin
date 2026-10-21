#!/usr/bin/env bash
# Observe the original cold-controller fatal with dependency registration off.
set -euo pipefail
cd "$(dirname "$0")/.."
control_dir=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-entry-autoload-control.XXXXXX")
printf 'control_fixture=%s\n' "$control_dir"
cleanup() {
  local status=$? name
  trap - EXIT
  if ((status == 0)); then
    rm -rf -- "${control_dir:?}"
  else
    printf 'Failed control evidence retained: %s\n' "$control_dir" >&2
    # These four probes use synthetic configuration and never contain secrets.
    for name in baseline.log mutant.log probe-mutant.log restored.log; do
      [[ -f $control_dir/$name ]] || continue
      printf 'Control diagnostic: %s (up to 40 lines / 8192 bytes)\n' "$name" >&2
      head -c 8192 -- "$control_dir/$name" | sed -n '1,40p' >&2 || :
    done
  fi
  exit "$status"
}
trap cleanup EXIT
php ci/test-entry-autoload.php >"$control_dir/baseline.log" 2>&1
awk '
  /if \(!\$probe->canHandle\(\$path, static function/ {
    count++; skip=1; print "    if (!$probe->canHandle($path)) {"; next
  }
  skip && /    \}\)\) \{/ { skip=0; next }
  !skip { print }
  END { if (count != 1 || skip) exit 1 }
' public/index.php >"$control_dir/index-mutant.php"
stat --printf='source_artifact_mtime=%y source_artifact_bytes=%s\n' "$control_dir/index-mutant.php"
sha256sum "$control_dir/index-mutant.php"
status=0
VIMBADMIN_ENTRY_AUTOLOAD_CANDIDATE="$control_dir/index-mutant.php" \
  php ci/test-entry-autoload.php >"$control_dir/mutant.log" 2>&1 || status=$?
if [[ $status == 0 ]] || ! grep -qF 'because class Entities\Admin is not available' "$control_dir/mutant.log"; then
  echo 'FAIL: cold controller autoload mutation did not reach the signature fatal' >&2
  exit 1
fi
printf '%s\n' 'control_command=VIMBADMIN_ENTRY_AUTOLOAD_CANDIDATE=<private-mutant> php ci/test-entry-autoload.php'
# shellcheck disable=SC2016 # Replace literal PHP variables in the private helper.
sed "s/'repositoryFile' => \$repository ?/'repositoryFile' => \$entity ?/" \
  ci/support/entry-autoload-probe.php >"$control_dir/probe-mutant.php"
grep -qF "'repositoryFile' => \$entity ?" "$control_dir/probe-mutant.php"
stat --printf='source_artifact_mtime=%y source_artifact_bytes=%s\n' "$control_dir/probe-mutant.php"
sha256sum "$control_dir/probe-mutant.php"
status=0
VIMBADMIN_ENTRY_AUTOLOAD_PROBE="$control_dir/probe-mutant.php" \
  php ci/test-entry-autoload.php >"$control_dir/probe-mutant.log" 2>&1 || status=$?
if [[ $status == 0 ]] || ! grep -qF 'Repositories\Domain' "$control_dir/probe-mutant.log"; then
  echo 'FAIL: missing-repository mutation did not reach the reflection failure' >&2
  exit 1
fi
printf '%s\n' 'control_command=VIMBADMIN_ENTRY_AUTOLOAD_PROBE=<private-mutant> php ci/test-entry-autoload.php'
php ci/test-entry-autoload.php >"$control_dir/restored.log" 2>&1
# Preserve complete logs in the caller's captured output before successful
# fixture cleanup; a failure retains its private directory for inspection.
cat "$control_dir/baseline.log" "$control_dir/mutant.log" "$control_dir/probe-mutant.log" "$control_dir/restored.log"
echo 'OK: cold controller autoload control observed red and restored green'
