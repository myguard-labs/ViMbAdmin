#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

tmp=$(mktemp -d /tmp/vimbadmin-pinned-assets.XXXXXX)
readonly tmp
readonly source=public/js/990-vimbadmin.js
readonly js_bundle=public/js/min.bundle-v29.js
readonly css_bundle=public/css/min.bundle-v29.css
readonly js_header=application/views/header-js.phtml
readonly css_header=application/views/header-css.phtml
readonly files=("$source" "$js_bundle" "$css_bundle" "$js_header" "$css_header")

restore() {
  for path in "${files[@]}"; do
    cp "$tmp/${path//\//__}" "$path"
  done
}
cleanup() {
  restore
  rm -rf -- "$tmp"
}
trap cleanup EXIT

for path in "${files[@]}"; do
  cp "$path" "$tmp/${path//\//__}"
done

compare_generated() {
  cmp "$tmp/${js_bundle//\//__}" "$js_bundle" &&
    cmp "$tmp/${css_bundle//\//__}" "$css_bundle" &&
    cmp "$tmp/${js_header//\//__}" "$js_header" &&
    cmp "$tmp/${css_header//\//__}" "$css_header"
}

php bin/minify-bundle.php --version 29 --quiet
compare_generated || {
  echo 'FAIL: pinned toolchain did not reproduce committed bundles and headers' >&2
  exit 1
}
echo 'ok   pinned toolchain reproduces committed bundles and headers'

printf '\nwindow.__vimbadminAssetMutation = true;\n' >>"$source"
php bin/minify-bundle.php --version 29 --js-only --quiet
if compare_generated; then
  echo 'FAIL: source-only mutation did not change generated assets' >&2
  exit 1
fi
echo 'ok   negative control: source-only mutation changes generated JS bundle'

restore
php bin/minify-bundle.php --version 29 --quiet
compare_generated || {
  echo 'FAIL: restored source did not reproduce committed generated assets' >&2
  exit 1
}
echo 'ok   restored source reproduces committed generated assets'
