#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
tmp=$(mktemp -d /tmp/vimbadmin-minify-tools.XXXXXX)
readonly tmp
readonly generated=(
  public/js/min.bundle-v29.js
  public/css/min.bundle-v29.css
  application/views/header-js.phtml
  application/views/header-css.phtml
)

restore() {
  if [[ -f $tmp/compiler.jar ]]; then
    mv "$tmp/compiler.jar" bin/compiler.jar
  fi
  if [[ -d $tmp/node_modules ]]; then
    mv "$tmp/node_modules" bin/node_modules
  fi
  for path in "${generated[@]}"; do
    cp "$tmp/${path//\//__}" "$path"
  done
}
cleanup() {
  restore
  rm -rf -- "$tmp"
}
trap cleanup EXIT

for path in "${generated[@]}"; do
  cp "$path" "$tmp/${path//\//__}"
done

mv bin/compiler.jar "$tmp/compiler.jar"
php bin/minify-bundle.php --version 29 --css-only --quiet
if php bin/minify-bundle.php --version 29 --js-only --quiet >"$tmp/js-missing" 2>&1; then
  echo 'FAIL: JS-only build accepted a missing Closure Compiler' >&2
  exit 1
fi
grep -qF 'Closure Compiler digest mismatch' "$tmp/js-missing"
mv "$tmp/compiler.jar" bin/compiler.jar
echo 'ok   CSS-only requires no JS tool; JS-only rejects a missing compiler'

cp bin/compiler.jar "$tmp/compiler.good.jar"
printf 'readable but not the pinned compiler\n' >bin/compiler.jar
if php bin/minify-bundle.php --version 29 --js-only --quiet >"$tmp/js-mismatch" 2>&1; then
  echo 'FAIL: JS-only build accepted a mismatched Closure Compiler' >&2
  exit 1
fi
expected_digest="$(sha256sum bin/compiler.jar | cut -d' ' -f1)"
cat >"$tmp/js-mismatch.expected" <<EOF
FATAL: Closure Compiler digest mismatch for $PWD/bin/compiler.jar.
       Expected SHA-256: 230a9e05a8a7d9daa083b1f6e86edba6eb1ec6402a6a258432fe4245cdc4a95f
       Actual SHA-256: $expected_digest
EOF
diff -u "$tmp/js-mismatch.expected" "$tmp/js-mismatch"
mv "$tmp/compiler.good.jar" bin/compiler.jar
php bin/minify-bundle.php --version 29 --js-only --quiet
echo 'ok   JS-only rejects a readable compiler with the wrong digest before execution'

mv bin/node_modules "$tmp/node_modules"
php bin/minify-bundle.php --version 29 --js-only --quiet
if php bin/minify-bundle.php --version 29 --css-only --quiet >"$tmp/css-missing" 2>&1; then
  echo 'FAIL: CSS-only build accepted a missing clean-css installation' >&2
  exit 1
fi
grep -qF 'clean-css CLI not found' "$tmp/css-missing"
mv "$tmp/node_modules" bin/node_modules
echo 'ok   JS-only requires no CSS tool; CSS-only rejects missing clean-css'

cp bin/node_modules/.package-lock.json "$tmp/installed-package-lock.good.json"
php -r '
$path = "bin/node_modules/.package-lock.json";
$lock = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$lock["packages"]["node_modules/clean-css-cli"]["version"] = "0.0.0-fixture";
file_put_contents($path, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
'
if php bin/minify-bundle.php --version 29 --css-only --quiet >"$tmp/css-mismatch" 2>&1; then
  echo 'FAIL: CSS-only build accepted a mismatched installed package lock' >&2
  exit 1
fi
cat >"$tmp/css-mismatch.expected" <<'EOF'
FATAL: installed clean-css dependency graph does not match bin/package-lock.json.
       Restore it with: npm ci --prefix bin
EOF
diff -u "$tmp/css-mismatch.expected" "$tmp/css-mismatch"
cp "$tmp/installed-package-lock.good.json" bin/node_modules/.package-lock.json
php bin/minify-bundle.php --version 29 --css-only --quiet
echo 'ok   CSS-only rejects a mismatched installed package lock before execution'

php bin/minify-bundle.php --version 29 --quiet
echo 'ok   combined build accepts both restored pinned tools'

if [[ ${VIMBADMIN_CORRUPT_COMBINED_OUTPUT:-0} == 1 ]]; then
  printf '\n/* corrupt combined-build fixture */\n' >>public/js/min.bundle-v29.js
fi
for path in "${generated[@]}"; do
  if ! cmp "$tmp/${path//\//__}" "$path"; then
    echo "FAIL: combined build changed tracked output: $path" >&2
    exit 1
  fi
done
echo 'ok   tool-selection probes preserve the committed comparison baseline'
