#!/usr/bin/env bash

# Exercise the exact stale-asset/new-view boundary in a real browser. The old
# URL is primed with the maintained source list minus the newly required helper,
# then the origin switches to current bytes while the browser retains the old
# immutable response. The current header's distinct URL must load fresh.
set -Eeuo pipefail

script_path=$(realpath "${BASH_SOURCE[0]}")
readonly script_path
cd "$(dirname "$script_path")/.."

# The path is resolved after changing to the repository root.
# shellcheck disable=SC1091
source tests/support/resolve-bundle-v.sh

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

browser=${CHROMIUM_BIN:-}
readonly http_runner=.github/scripts/run-chrome-http-fixture.sh
if [[ -z "$browser" ]]; then
  browser=$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)
fi
[[ -n "$browser" ]] || fail 'Chromium is required for the asset cache boundary regression'

tmp=$(mktemp -d /tmp/vimbadmin-datatables-dependency-free.XXXXXX)
container_name=''
cleanup() {
  local status=$?
  if [[ -n "$container_name" ]]; then
    docker rm -f "$container_name" >/dev/null 2>&1 || true
  fi
  if [[ "$status" -ne 0 && "${VIMBADMIN_CACHE_BOUNDARY_KEEP_TMP:-0}" == 1 ]]; then
    printf 'cache-boundary fixture retained: %s\n' "$tmp" >&2
    return "$status"
  fi
  rm -rf -- "${tmp:?}"
  return "$status"
}
trap cleanup EXIT

readonly fixture="$tmp/vimbadmin/subpath"
mkdir -p "$fixture/js"

# JS-only releases intentionally keep CSS on its independently versioned URL.
# Exercise the real resolver in private roots, including direct-call shell state.
check_asset_kinds() (
  local root="$tmp/asset-kinds" initial kind expected status saved_flags saved_nullglob
  mkdir -p "$root/public/js" "$root/public/css"
  _resolve_bundle_v_repo_root=$root
  for initial in off on; do
    shopt -u nullglob
    [[ "$initial" == off ]] || shopt -s nullglob
    saved_flags=$-
    saved_nullglob=$(shopt -p nullglob || true)
    for kind in js css; do
      expected=min.bundle-v30.js
      [[ "$kind" == js ]] || expected=min.bundle-v29.css
      : >"$root/public/$kind/$expected"
      if resolve_bundle_v "$kind" >"$root/output" 2>"$root/error"; then status=0; else status=$?; fi
      [[ $(shopt -p nullglob || true) == "$saved_nullglob" && "$-" == "$saved_flags" ]] || fail 'resolver changed shell options'
      [[ "$status" == 0 && $(<"$root/output") == "$expected" ]] || fail "$kind must resolve its independent version"
      rm "$root/public/$kind/$expected"
      if resolve_bundle_v "$kind" >"$root/output" 2>"$root/error"; then status=0; else status=$?; fi
      [[ $(shopt -p nullglob || true) == "$saved_nullglob" && "$-" == "$saved_flags" ]] || fail 'resolver changed shell options'
      [[ "$status" == 2 && ! -s "$root/output" ]] || fail "missing $kind must fail closed"
      grep -qF "no ${kind^^} bundle found" "$root/error" || fail "missing $kind diagnostic changed"
      : >"$root/public/$kind/$expected"
      : >"$root/public/$kind/min.bundle-v28.$kind"
      if resolve_bundle_v "$kind" >"$root/output" 2>"$root/error"; then status=0; else status=$?; fi
      [[ $(shopt -p nullglob || true) == "$saved_nullglob" && "$-" == "$saved_flags" ]] || fail 'resolver changed shell options'
      [[ "$status" == 2 && ! -s "$root/output" ]] || fail "ambiguous $kind must fail closed"
      grep -qF 'multiple matches found' "$root/error" || fail "ambiguous $kind diagnostic changed"
      rm "$root/public/$kind/min.bundle-v28.$kind"
      if shopt -q nullglob; then
        [[ "$initial" == on ]] || fail 'resolver changed nullglob from off'
      else
        [[ "$initial" == off ]] || fail 'resolver changed nullglob from on'
      fi
      [[ "$-" == "$saved_flags" ]] || fail 'resolver changed shell option flags'
    done
    [[ $(resolve_bundle_v) == min.bundle-v30.js ]] || fail 'default resolver must remain JS'
    for kind in invalid ''; do
      if resolve_bundle_v "$kind" >"$root/output" 2>"$root/error"; then status=0; else status=$?; fi
      [[ $(shopt -p nullglob || true) == "$saved_nullglob" && "$-" == "$saved_flags" ]] || fail 'resolver changed shell options'
      [[ "$status" == 2 && ! -s "$root/output" ]] || fail 'invalid asset kind must fail closed'
    done
    if resolve_bundle_v js css >"$root/output" 2>"$root/error"; then status=0; else status=$?; fi
    [[ $(shopt -p nullglob || true) == "$saved_nullglob" && "$-" == "$saved_flags" ]] || fail 'resolver changed shell options'
    [[ "$status" == 2 && ! -s "$root/output" ]] || fail 'multiple asset-kind arguments must fail closed'
    rm "$root/public/js/min.bundle-v30.js" "$root/public/css/min.bundle-v29.css"
  done
  echo 'ok   independent JS v30/CSS v29 resolution; missing, ambiguous and malformed kinds fail closed'
)
check_asset_kinds

bundle_file=$(resolve_bundle_v) || exit $?
header_bundle_list="$tmp/header-bundles"
sed -n 's|.*src="{genUrl}/js/\(min\.bundle-v[0-9][0-9]*\.js\)".*|\1|p' \
  application/views/header-js.phtml >"$header_bundle_list"
mapfile -t header_bundles <"$header_bundle_list"
[[ ${#header_bundles[@]} -eq 1 ]] || fail 'header-js.phtml must name exactly one minified JS bundle'
[[ ${header_bundles[0]} == "$bundle_file" ]] ||
  fail "header bundle ${header_bundles[0]} does not match resolver output $bundle_file"
[[ "$bundle_file" != min.bundle-v29.js ]] || fail 'maintained JS URL still reuses the stale v29 cache key'

shipped_bundle_list="$tmp/shipped-bundles"
find public/js -maxdepth 1 -type f -name 'min.bundle-v*.js' -printf '%f\n' |
  sort >"$shipped_bundle_list"
mapfile -t shipped_bundles <"$shipped_bundle_list"
[[ ${#shipped_bundles[@]} -eq 1 && ${shipped_bundles[0]} == "$bundle_file" ]] ||
  fail 'public/js must contain exactly the one header-selected JS bundle'

cp "public/js/$bundle_file" "$fixture/js/$bundle_file"
grep -qF 'vmDataTableRememberPageLength' "$fixture/js/$bundle_file" ||
  fail 'current bundle does not contain vmDataTableRememberPageLength'

# Assemble a faithful pre-helper asset from the generator's real input list.
# It stays in the private fixture; production retains only the current bundle.
js_input_list="$tmp/js-inputs"
php bin/minify-bundle.php --print-inputs | sed -n 's/^js  //p' >"$js_input_list"
mapfile -t js_inputs <"$js_input_list"
[[ ${#js_inputs[@]} -gt 0 ]] || fail 'the maintained generator returned no JS inputs'
: >"$fixture/stale-min.bundle-v29.js"
removed_helper=false
for asset in "${js_inputs[@]}"; do
  if [[ "$asset" == 990-vimbadmin.js ]]; then
    perl -0777 -pe '
      BEGIN { $removed = 0 }
      $removed += s{\nfunction vmDataTableRememberPageLength\( settings \)\n\{\n(?:    [^\n]*\n)+?\}\n}{\n};
      END { die "expected one page-length helper\n" unless $removed == 1 }
    ' "public/js/$asset" >>"$fixture/stale-min.bundle-v29.js"
    # Compare the complete transformed source with a line-bounded removal,
    # so the fixture cannot silently lose an adjacent helper or other bytes.
    perl -0777 -e '
      my ($source_path, $stale_path) = @ARGV;
      open my $source_file, "<", $source_path or die $!;
      my $source = <$source_file>;
      open my $stale_file, "<", $stale_path or die $!;
      my $stale = <$stale_file>;
      my $start_marker = "\nfunction vmDataTableRememberPageLength( settings )\n{\n";
      my $start = index($source, $start_marker);
      die "expected one page-length helper boundary\n"
        unless $start >= 0 && index($source, $start_marker, $start + 1) == -1;
      my $end = index($source, "\n}\n", $start + length($start_marker));
      die "expected page-length helper closing boundary\n" unless $end >= 0;
      my $expected = substr($source, 0, $start + 1) . substr($source, $end + 3);
      my $transformed = substr($stale, -length($expected));
      die "stale fixture removed bytes outside the intended helper\n"
        unless $transformed eq $expected;
      die "stale fixture lost adjacent vmDataTableApi\n"
        unless index($transformed, "function vmDataTableApi( table )") >= 0;
    ' "public/js/$asset" "$fixture/stale-min.bundle-v29.js"
    removed_helper=true
  else
    sed -n '1,$p' "public/js/$asset" >>"$fixture/stale-min.bundle-v29.js"
  fi
  printf '\n' >>"$fixture/stale-min.bundle-v29.js"
done
[[ "$removed_helper" == true ]] || fail '990-vimbadmin.js is not in the maintained JS input list'
if grep -qF 'vmDataTableRememberPageLength' "$fixture/stale-min.bundle-v29.js"; then
  fail 'stale fixture still defines the page-length callback'
fi

# Five dual-mode views and four single-mode views yield fourteen real bindings.
readonly callbacks="$fixture/view-length-callbacks.js"
printf 'var listLengthCallbacks = [\n' >"$callbacks"
binding_count=0
for view in \
  alias/js/list domain/js/list mailbox/js/list archive/js/list log/js/list \
  admin/js/list admin/js/domains domain/js/admins mailbox/js/aliases; do
  expected=1
  [[ "$view" =~ ^(alias|domain|mailbox|archive|log)/js/list$ ]] && expected=2
  found=$(awk '
    /drawCallback.*vmDataTableRememberPageLength/ {
      print "vmDataTableRememberPageLength,";
      count++;
    }
    END { if (!count) exit 1; }
  ' "application/views/$view.js" | tee -a "$callbacks" | wc -l)
  [[ "$found" -eq "$expected" ]] || fail "$view has $found page-length callbacks, expected $expected"
  binding_count=$((binding_count + found))
done
printf '];\n' >>"$callbacks"
[[ "$binding_count" -eq 14 ]] || fail "extracted $binding_count page-length callbacks, expected 14"

loader_tag=$(sed -n \
  '/src="{genUrl}\/js\/min\.bundle-v[0-9][0-9]*\.js"/ { s|{genUrl}|/vimbadmin/subpath|g; p; }' \
  application/views/header-js.phtml)
[[ -n "$loader_tag" ]] || fail 'could not render the maintained minified loader under a subpath'
fixed_loader_tag=$loader_tag
if [[ ${VIMBADMIN_CACHE_BOUNDARY_REUSE_STALE_URL:-0} == 1 ]]; then
  fixed_loader_tag=${fixed_loader_tag//$bundle_file/min.bundle-v29.js}
fi

# The router changes the origin's v29 bytes after the priming page. A second
# v29 request would therefore receive current bytes; observing the original
# ReferenceError plus exactly one router hit proves the browser reused cache.
cat >"$fixture/router.php" <<'PHP'
<?php
declare(strict_types=1);
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$root = __DIR__;
$state = $root . '/origin-current';
$log = $root . '/requests.log';
if ($path === '/vimbadmin/subpath/flip.php') {
    file_put_contents($state, "current\n");
    header('Cache-Control: no-store');
    echo "current\n";
    return true;
}
if ($path === '/vimbadmin/subpath/js/min.bundle-v29.js') {
    $current = is_file($state);
    file_put_contents($log, $current ? "v29-current\n" : "v29-stale\n", FILE_APPEND);
    header('Content-Type: application/javascript; charset=UTF-8');
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($current ? $root . '/js/@@CURRENT_BUNDLE@@' : $root . '/stale-min.bundle-v29.js');
    return true;
}
if ($path === '/vimbadmin/subpath/js/@@CURRENT_BUNDLE@@') {
    file_put_contents($log, "@@CURRENT_BUNDLE@@-network\n", FILE_APPEND);
    header('Content-Type: application/javascript; charset=UTF-8');
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($root . '/js/@@CURRENT_BUNDLE@@');
    return true;
}
return false;
PHP
sed -i "s/@@CURRENT_BUNDLE@@/$bundle_file/g" "$fixture/router.php"

cat >"$fixture/prime.html" <<'HTML'
<!doctype html><html><body><script>
window.cacheBoundaryErrors = [];
window.onerror = function(message, source, line, column, error) {
  window.cacheBoundaryErrors.push(error && error.name
    ? error.name + ': ' + error.message : String(message));
  return true;
};
</script>
<script src="/vimbadmin/subpath/js/min.bundle-v29.js"></script>
<script>
parent.postMessage({kind: 'phase', phase: 'prime', errors: window.cacheBoundaryErrors,
  helper: typeof vmDataTableRememberPageLength}, '*');
</script></body></html>
HTML

cat >"$fixture/stale-new-view.html" <<'HTML'
<!doctype html><html><body><script>
window.cacheBoundaryErrors = [];
window.onerror = function(message, source, line, column, error) {
  window.cacheBoundaryErrors.push(error && error.name
    ? error.name + ': ' + error.message : String(message));
  return true;
};
</script>
<script src="/vimbadmin/subpath/js/min.bundle-v29.js"></script>
<script src="/vimbadmin/subpath/view-length-callbacks.js"></script>
<script>
parent.postMessage({kind: 'phase', phase: 'stale', errors: window.cacheBoundaryErrors}, '*');
</script></body></html>
HTML

cat >"$fixture/fixed-new-view.html" <<HTML
<!doctype html><html><body>
<table id="cache-table"><thead><tr><th>Name</th></tr></thead><tbody></tbody></table>
<script>
window.cacheBoundaryErrors = [];
window.onerror = function(message, source, line, column, error) {
  window.cacheBoundaryErrors.push(error && error.name
    ? error.name + ': ' + error.message : String(message));
  return true;
};
</script>
$fixed_loader_tag
<script src="/vimbadmin/subpath/view-length-callbacks.js"></script>
<script>
if (window.cacheBoundaryErrors.length) {
  parent.postMessage({kind: 'phase', phase: 'fixed', errors: window.cacheBoundaryErrors}, '*');
} else {
  try {
    var element = document.getElementById('cache-table');
    var api = new DataTable(element, {
      data: [['one']], columns: [{title: 'Name'}], order: [], pageLength: 1,
      drawCallback: listLengthCallbacks[0]
    });
    parent.postMessage({kind: 'phase', phase: 'fixed', errors: [],
      helper: typeof vmDataTableRememberPageLength === 'function',
      bindings: listLengthCallbacks.length,
      bindingIdentity: listLengthCallbacks.every(function(callback) {
        return callback === vmDataTableRememberPageLength;
      }),
      elements: document.querySelectorAll('#cache-table').length,
      registrations: DataTable.isDataTable(element) ? 1 : 0,
      remembered: vm_prefs.iLength,
      subpath: location.pathname.indexOf('/vimbadmin/subpath/') === 0,
      loader: Array.from(document.scripts).some(function(script) {
        return script.src.endsWith('/vimbadmin/subpath/js/$bundle_file');
      })
    }, '*');
    api.destroy();
  } catch (error) {
    parent.postMessage({kind: 'phase', phase: 'fixed', errors: [error.name + ': ' + error.message]}, '*');
  }
}
</script></body></html>
HTML

cat >"$fixture/regression.html" <<'HTML'
<!doctype html><html><body data-verdict="PENDING">
<pre id="output">PENDING</pre>
<script>
'use strict';
const expectedStaleError = 'ReferenceError: vmDataTableRememberPageLength is not defined';
const results = {};
function loadPhase(name, page) {
  return new Promise((resolve, reject) => {
    const frame = document.createElement('iframe');
    const timeout = setTimeout(() => reject(new Error(name + ' phase timed out')), 4000);
    function receive(event) {
      if (event.source !== frame.contentWindow || !event.data || event.data.phase !== name) return;
      clearTimeout(timeout);
      window.removeEventListener('message', receive);
      frame.remove();
      resolve(event.data);
    }
    window.addEventListener('message', receive);
    frame.src = page;
    document.body.appendChild(frame);
  });
}
async function run() {
  results.prime = await loadPhase('prime', 'prime.html');
  const flip = await fetch('flip.php', {cache: 'no-store'});
  if (!flip.ok || (await flip.text()).trim() !== 'current') throw new Error('origin flip failed');
  results.stale = await loadPhase('stale', 'stale-new-view.html');
  results.fixed = await loadPhase('fixed', 'fixed-new-view.html');
  const failures = [];
  if (results.prime.errors.length || results.prime.helper !== 'undefined')
    failures.push('prime did not cache the complete pre-helper asset');
  if (results.stale.errors.length !== 1 || results.stale.errors[0] !== expectedStaleError)
    failures.push('stale phase did not preserve the witnessed ReferenceError');
  if (results.fixed.errors.length) failures.push('fixed error: ' + results.fixed.errors.join(' | '));
  if (!results.fixed.helper) failures.push('fixed helper unavailable');
  if (results.fixed.bindings !== 14 || !results.fixed.bindingIdentity)
    failures.push('fixed callbacks are not all fourteen production bindings');
  if (results.fixed.elements !== 1 || results.fixed.registrations !== 1)
    failures.push('fixed page did not register exactly one DataTable on one element');
  if (results.fixed.remembered !== 1) failures.push('fixed draw did not persist page length');
  if (!results.fixed.subpath || !results.fixed.loader) failures.push('fixed loader did not resolve under the subpath');
  document.getElementById('output').textContent = JSON.stringify({expectedStaleError, results, failures});
  document.body.dataset.verdict = failures.length ? 'FAIL' : 'PASS';
}
run().catch(error => {
  document.getElementById('output').textContent = error.name + ': ' + error.message;
  document.body.dataset.verdict = 'FAIL';
});
</script></body></html>
HTML

run_browser() {
  local output=$1
  local -a chrome_args=(
    --headless --disable-gpu --virtual-time-budget=10000
    --user-data-dir="$tmp/profile" --dump-dom
    http://127.0.0.1:8765/vimbadmin/subpath/regression.html
  )
  if [[ "$browser" == *run-headless-chrome.sh ]]; then
    local image run_label
    image=$(sed -n "s/^readonly image='\([^']*\)'/\1/p" "$browser")
    [[ -n "$image" ]] || fail 'could not resolve the pinned Chromium image from the maintained runner'
    container_name="vimbadmin-cache-boundary-$$-$RANDOM"
    run_label="vimbadmin-cache-boundary-${GITHUB_RUN_ID:-local}-$$"
    docker run --rm --cidfile "$tmp/browser.cid" \
      --name "$container_name" --label "myguard.run=$run_label" \
      --network none --cap-drop ALL --security-opt no-new-privileges \
      --user "$(id -u):$(id -g)" --env "HOME=$tmp" \
      --env "VIMBADMIN_FIXTURE_ROUTER=$fixture/router.php" \
      --mount "type=bind,src=$tmp,dst=$tmp" \
      --mount "type=bind,src=$PWD/$http_runner,dst=/usr/local/bin/run-chrome-http-fixture,readonly" \
      "$image" /usr/local/bin/run-chrome-http-fixture "$tmp" "${chrome_args[@]}" \
      >"$output" 2>&1
    container_name=''
  else
    VIMBADMIN_FIXTURE_ROUTER="$fixture/router.php" CHROME_BIN=$browser \
      "$http_runner" "$tmp" "${chrome_args[@]}" >"$output" 2>&1
  fi
}

output="$tmp/cache-boundary.html"
run_browser "$output"
if ! grep -q 'data-verdict="PASS"' "$output"; then
  grep -o '<pre id="output">[^<]*' "$output" | sed 's/<pre id="output">//' >&2 || true
  exit 1
fi
grep -qF 'ReferenceError: vmDataTableRememberPageLength is not defined' "$output"
[[ $(grep -c '^v29-' "$fixture/requests.log") -eq 1 ]] ||
  fail 'the browser requested the immutable v29 URL more than once'
grep -qxF 'v29-stale' "$fixture/requests.log" || fail 'the primed v29 response was not the stale asset'
grep -qxF "$bundle_file-network" "$fixture/requests.log" ||
  fail 'the distinct maintained URL was not freshly requested'

if [[ ${VIMBADMIN_CACHE_BOUNDARY_REUSE_STALE_URL:-0} != 1 ]]; then
  mutation_log="$tmp/reused-stale-url.log"
  if VIMBADMIN_CACHE_BOUNDARY_REUSE_STALE_URL=1 bash "$script_path" >"$mutation_log" 2>&1; then
    fail 'negative control passed when the fixed page reused the stale v29 URL'
  fi
  grep -qF 'fixed error: ReferenceError: vmDataTableRememberPageLength is not defined' "$mutation_log" ||
    fail 'negative control did not reach the witnessed missing-helper failure'
  echo 'ok   negative control: reusing cached v29 fails on the current view callbacks'
fi

echo "ok   cached v29 fails; $bundle_file loads fresh with 14 bindings and one registration"
