#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
control_dir=$(mktemp -d)
trap 'rm -rf -- "${control_dir:?}"' EXIT

cat >"$control_dir/git" <<'GIT'
#!/usr/bin/env bash
if [[ ${1:-} == ls-files ]]; then
  printf 'fixture: tracked-file enumeration failed\n' >&2
  exit 42
fi
exec /usr/bin/git "$@"
GIT
chmod +x "$control_dir/git"

if PATH="$control_dir:$PATH" bash "$repo_root/tests/test-php-cs-fixer-contract.sh" >"$control_dir/negative.log" 2>&1; then
  printf 'Formatter contract accepted failed Git enumeration.\n' >&2
  exit 1
fi
if ! grep -qF 'Could not enumerate tracked PHP source files.' "$control_dir/negative.log"; then
  printf 'Formatter contract failed for a reason other than Git enumeration.\n' >&2
  exit 1
fi

bash "$repo_root/tests/test-php-cs-fixer-contract.sh" >"$control_dir/positive.log" 2>&1
grep -qFx 'ALL PASSED' "$control_dir/positive.log"
printf 'ALL PASSED: failed Git enumeration is rejected and normal source selection passes\n'
