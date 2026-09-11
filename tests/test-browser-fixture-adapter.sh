#!/usr/bin/env bash
# Purpose: exercise engine identity, DOM completion, HTTP confinement and errors.
# Usage: VIMBADMIN_BROWSER=chromium|firefox|webkit bash tests/test-browser-fixture-adapter.sh
# Inputs: the pinned browser image, built by regression.yml. Output: assertions.
# Side effects: one private fixture directory, removed on exit; isolated browsers.
# Limits: each invocation is bounded to 40 seconds. No dry-run; --help is read-only.
set -euo pipefail
if [[ ${1:-} == --help ]]; then
  sed -n '2,6p' "$0"
  exit 0
fi
cd "$(dirname "$0")/.."
node tests/test-browser-fixture-cleanup.cjs
case ${VIMBADMIN_BROWSER:-} in
chromium | firefox | webkit) ;;
*)
  echo 'FAIL: select chromium, firefox or webkit' >&2
  exit 64
  ;;
esac
root=$(mktemp -d /tmp/vimbadmin-browser-adapter.XXXXXX)
cleanup() {
  local status=$?
  if ((status != 0)); then
    printf '[%s] FAIL: adapter test exited %s\n' "$VIMBADMIN_BROWSER" "$status" >&2
    for capture in "$root/errors" "$root/output"; do
      if [[ -f $capture ]]; then
        cat "$capture" >&2
      fi
    done
  fi
  rm -rf -- "$root"
  return "$status"
}
trap cleanup EXIT
readonly runner=$PWD/.github/scripts/run-headless-chrome.sh
# Assert the actual user agent, so an adapter that silently runs Chromium for
# every matrix entry cannot turn the Firefox/WebKit checks green.
printf '%s\n' '<!doctype html><body><script>
const ua = navigator.userAgent;
const engine = /Firefox\//.test(ua) ? "firefox" : /Chrome\//.test(ua) ? "chromium" : /AppleWebKit\//.test(ua) ? "webkit" : "unknown";
document.body.dataset.testResult = engine === new URL(location.href).searchParams.get("engine") ? "pass" : "fail";
document.body.dataset.testFailures = engine;
</script></body>' >"$root/regression.html"
printf '%s\n' '<!doctype html><body>No terminal verdict</body>' >"$root/incomplete.html"
# The symlink target is read only inside the confined browser container.
# Both out-of-root requests must reach realpath successfully to exercise its
# containment predicate rather than passing via a missing-file error.
ln -s /etc/os-release "$root/outside.txt"
ln -s regression.html "$root/inside.html"
printf '%s' 'fixture resource' >"$root/valid.txt"
# JavaScript template literals below must reach the browser unchanged.
# shellcheck disable=SC2016
printf '%s\n' '<!doctype html><body><script>
const cases = [
  ["outside symlink", "/outside.txt", 404],
  ["encoded traversal", "/%2e%2e%2f%2e%2e%2fetc%2fos-release", 404],
  ["malformed escape", "/%zz", 404],
  ["missing resource", "/missing.txt", 404],
  ["ordinary resource", "/valid.txt", 200],
  ["encoded resource", "/%76alid.txt", 200]
];
Promise.all(cases.map(async ([name, url, expected]) => {
  const response = await fetch(url);
  const body = await response.text();
  return response.status === expected && (expected !== 200 || body === "fixture resource")
    ? null : `${name}: expected ${expected}, got ${response.status}`;
})).then(results => {
  const failures = results.filter(Boolean);
  document.body.dataset.testFailures = JSON.stringify(failures);
  document.body.dataset.testResult = failures.length ? "fail" : "pass";
}).catch(error => {
  document.body.dataset.testFailures = error.message;
  document.body.dataset.testResult = "fail";
});
</script></body>' >"$root/boundary.html"
run_fixture() {
  timeout 40 "$runner" --user-data-dir="$root/profile" --dump-dom "$@" \
    >"$root/output" 2>"$root/errors"
}
for origin in "file://$root" 'http://127.0.0.1:8765'; do
  run_fixture "$origin/regression.html?engine=$VIMBADMIN_BROWSER"
  grep -qF 'data-test-result="pass"' "$root/output"
  grep -qF "[$VIMBADMIN_BROWSER] fixture verdict: pass $VIMBADMIN_BROWSER" "$root/errors"
done
run_fixture "file://$root/inside.html?engine=$VIMBADMIN_BROWSER"
grep -qF 'data-test-result="pass"' "$root/output"
grep -qF "[$VIMBADMIN_BROWSER] fixture verdict: pass $VIMBADMIN_BROWSER" "$root/errors"
# Require the containment error: a navigation or missing-verdict failure also
# exits 1 when a lexical-only guard lets the escaping symlink reach the browser.
status=0
run_fixture "file://$root/outside.txt" || status=$?
if [[ $status != 1 ]] || ! grep -qxF "[$VIMBADMIN_BROWSER] FAIL: file URL is outside the fixture root" "$root/errors"; then
  echo "[$VIMBADMIN_BROWSER] FAIL: file symlink escape did not report containment rejection (status $status)" >&2
  exit 1
fi
echo "[$VIMBADMIN_BROWSER] OK: file symlink escape denied; in-root symlink preserves query"
run_fixture 'http://127.0.0.1:8765/boundary.html'
grep -qF 'data-test-result="pass"' "$root/output"
grep -qF "[$VIMBADMIN_BROWSER] fixture verdict: pass []" "$root/errors"
echo "[$VIMBADMIN_BROWSER] OK: HTTP symlink/traversal denied; valid and malformed near-misses"
# Keep Chrome's dump-dom contract: a terminal FAIL is dumped for the lane's
# assertion, while a browser/transport/missing-verdict error exits non-zero.
run_fixture "file://$root/regression.html?engine=deliberately-wrong"
if grep -qF 'data-test-result="pass"' "$root/output"; then
  echo "[$VIMBADMIN_BROWSER] FAIL: engine mismatch was accepted" >&2
  exit 1
fi
grep -qF "[$VIMBADMIN_BROWSER] fixture verdict: fail $VIMBADMIN_BROWSER" "$root/errors"
printf '[%s] engine mismatch observed red: ' "$VIMBADMIN_BROWSER"
cat "$root/errors"
for target in "file://$root/incomplete.html" 'http://127.0.0.1:8765/missing.html' 'file:///etc/passwd' 'http://example.invalid/'; do
  status=0
  run_fixture "$target" || status=$?
  if [[ $status != 1 ]]; then
    echo "[$VIMBADMIN_BROWSER] FAIL: invalid fixture returned $status: $target" >&2
    exit 1
  fi
  grep -qF "[$VIMBADMIN_BROWSER] FAIL:" "$root/errors"
done
echo "[$VIMBADMIN_BROWSER] OK: actual engine, file/HTTP verdicts and fail-closed errors"
