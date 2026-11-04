#!/usr/bin/env bash
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
fixture=$(mktemp -d "${TMPDIR:-/tmp}/vimbadmin-psalm-preflight.XXXXXX")
trap 'rm -rf -- "${fixture:?}"' EXIT
preflight=$root/.github/scripts/assert-psalm-optional-models.sh
# Keep the preflight list aligned with every configured optional stub, plus
# the Predis directory's required interface. No Psalm or downloader is invoked.
sed -n 's/.*name="var\/tmp\/psalm-optional\/\([^"]*\)".*/\1/p' "$root/psalm.xml" >"$fixture/inputs"
mapfile -t inputs <"$fixture/inputs"
[[ ${#inputs[@]} -gt 0 ]]
for i in "${!inputs[@]}"; do
  [[ ${inputs[i]} != predis/src ]] || inputs[i]=predis/src/ClientInterface.php
done
for input in "${inputs[@]}"; do
  mkdir -p "$fixture/var/tmp/psalm-optional/$(dirname "$input")"
  printf '<?php\n' >"$fixture/var/tmp/psalm-optional/$input"
done
bash "$preflight" "$fixture"
for input in "${inputs[@]}"; do
  path=$fixture/var/tmp/psalm-optional/$input
  mv "$path" "$path.saved"
  for kind in missing empty directory; do
    case $kind in
      missing) ;;
      empty) : >"$path" ;;
      directory) mkdir "$path"; printf 'fixture\n' >"$path/child" ;;
    esac
    if bash "$preflight" "$fixture" >"$fixture/error" 2>&1; then
      echo "$kind model accepted: $input" >&2; exit 1
    fi
    grep -qF "Missing Psalm optional model: $input" "$fixture/error"
    grep -qF 'setup-php-container.sh psalm' "$fixture/error"
    case $kind in
      empty) rm -- "$path" ;;
      directory) rm -- "$path/child"; rmdir "$path" ;;
    esac
  done
  mv "$path.saved" "$path"
done
# Each standalone caller fails with the same actionable message before tool
# discovery, baseline writes, proxy checks, PHP analysis or any download.
mkdir -p "$fixture/repo"/{ci,tests,.github/scripts}
cp "$preflight" "$fixture/repo/.github/scripts/"
for caller in ci/test-psalm-entrypoint-contract.sh ci/test-psalm-proxies-ignored.sh \
  tests/regenerate-psalm-taint-baseline.sh; do
  cp "$root/$caller" "$fixture/repo/$caller"
  if PSALM_BIN="$fixture/never-called" bash "$fixture/repo/$caller" >"$fixture/error" 2>&1; then
    echo "Caller accepted absent optional models: $caller" >&2; exit 1
  fi
  grep -qF 'Missing Psalm optional model:' "$fixture/error"
  grep -qF 'setup-php-container.sh psalm' "$fixture/error"
done
printf 'Psalm model preflight controls passed (%s inputs: missing/empty/directory cases and three callers).\n' "${#inputs[@]}"
