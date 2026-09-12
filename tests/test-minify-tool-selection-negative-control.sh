#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
tmp=$(mktemp -d /tmp/vimbadmin-minify-tools-negative.XXXXXX)
readonly tmp
readonly generated=(
  public/js/min.bundle-v29.js
  public/css/min.bundle-v29.css
  application/views/header-js.phtml
  application/views/header-css.phtml
)
trap 'rm -rf -- "$tmp"' EXIT

for path in "${generated[@]}"; do
  cp "$path" "$tmp/${path//\//__}"
done

if VIMBADMIN_CORRUPT_COMBINED_OUTPUT=1 bash tests/test-minify-tool-selection.sh >"$tmp/output" 2>&1; then
  echo 'FAIL: corrupt combined output passed its comparison' >&2
  exit 1
fi
grep -qF 'FAIL: combined build changed tracked output: public/js/min.bundle-v29.js' "$tmp/output"

for path in "${generated[@]}"; do
  cmp "$tmp/${path//\//__}" "$path"
done

echo 'ok   negative control: corrupt combined output fails before cleanup restores tracked outputs'
