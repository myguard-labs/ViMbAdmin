#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
tmp=$(mktemp -d /tmp/vimbadmin-minify-tools.XXXXXX)
readonly tmp

restore() {
  if [[ -f $tmp/compiler.jar ]]; then
    mv "$tmp/compiler.jar" bin/compiler.jar
  fi
  if [[ -d $tmp/node_modules ]]; then
    mv "$tmp/node_modules" bin/node_modules
  fi
}
cleanup() {
  restore
  rm -rf -- "$tmp"
}
trap cleanup EXIT

mv bin/compiler.jar "$tmp/compiler.jar"
php bin/minify-bundle.php --version 29 --css-only --quiet
if php bin/minify-bundle.php --version 29 --js-only --quiet >"$tmp/js-missing" 2>&1; then
  echo 'FAIL: JS-only build accepted a missing Closure Compiler' >&2
  exit 1
fi
grep -qF 'Closure Compiler digest mismatch' "$tmp/js-missing"
mv "$tmp/compiler.jar" bin/compiler.jar
echo 'ok   CSS-only requires no JS tool; JS-only rejects a missing compiler'

mv bin/node_modules "$tmp/node_modules"
php bin/minify-bundle.php --version 29 --js-only --quiet
if php bin/minify-bundle.php --version 29 --css-only --quiet >"$tmp/css-missing" 2>&1; then
  echo 'FAIL: CSS-only build accepted a missing clean-css installation' >&2
  exit 1
fi
grep -qF 'clean-css CLI not found' "$tmp/css-missing"
mv "$tmp/node_modules" bin/node_modules
echo 'ok   JS-only requires no CSS tool; CSS-only rejects missing clean-css'

php bin/minify-bundle.php --version 29 --quiet
echo 'ok   combined build accepts both restored pinned tools'
