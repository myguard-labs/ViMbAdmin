#!/usr/bin/env bash
# Exercise shell-owned evidence lifecycle without running the PHP fixture.
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
scratch=$(mktemp -d "${TMPDIR:-/tmp}/flash-cleanup-contract.XXXXXX")
trap 'rm -rf -- "${scratch:?}"' EXIT
mkdir -p "$scratch/bin" "$scratch/tmp"
cat >"$scratch/bin/php" <<'PHP'
#!/usr/bin/env bash
printf marker >"$3/marker"
exit "${FAKE_PHP_STATUS:-0}"
PHP
chmod +x "$scratch/bin/php"
export PATH="$scratch/bin:$PATH" TMPDIR="$scratch/tmp"
unset FLASH_PROOF_DIR FAKE_PHP_STATUS
for scenario in success failure custom; do
  run_tmp="$scratch/tmp/$scenario"
  mkdir "$run_tmp"
  status=0
  case $scenario in
    success) TMPDIR=$run_tmp bash "$root/ci/test-psalm-flash-propagation.sh" --runtime-only >"$scratch/$scenario.log" 2>&1 || status=$? ;;
    failure) TMPDIR=$run_tmp FAKE_PHP_STATUS=17 bash "$root/ci/test-psalm-flash-propagation.sh" --runtime-only >"$scratch/$scenario.log" 2>&1 || status=$? ;;
    custom) TMPDIR=$run_tmp FLASH_PROOF_DIR="$run_tmp/custom" bash "$root/ci/test-psalm-flash-propagation.sh" --runtime-only >"$scratch/$scenario.log" 2>&1 || status=$? ;;
  esac
  case $scenario in
    success) [[ $status == 0 && -z $(find "$run_tmp" -mindepth 1 -print -quit) ]] || { echo 'FAIL: default success evidence was retained' >&2; exit 1; } ;;
    failure) [[ $status == 17 && -n $(find "$run_tmp" -name marker -print -quit) ]] || { echo 'FAIL: failure evidence was lost' >&2; exit 1; } ;;
    custom) [[ $status == 0 && -f $run_tmp/custom/marker ]] || { echo 'FAIL: custom evidence was lost' >&2; exit 1; } ;;
  esac
  printf 'OK: %s evidence lifecycle\n' "$scenario"
done
