#!/usr/bin/env bash

# VIM-A15.59: upgrading vendored jQuery Validation from 1.21.0 to 1.22.0.
#
# public/js/120-jquery.validate.js:503 (v1.21.0) calls
# `v.currentElements.push( cleanElement )` in Validator.element()'s grouped-
# field path (reached when a field belongs to a `groups:` set and a sibling
# group member is already recorded invalid). jQuery 4.0.0 removed
# push/sort/splice from jQuery.fn (VIM-A15.29), so that call throws
# `TypeError: v.currentElements.push is not a function` for any form using
# grouped fields. Upstream 1.22.0 replaces it with
# `currentElements.pushStack( cleanElement )` at its line 506.
#
# tests/test-jquery-migrate-compat.sh's validation check uses a single
# ungrouped field and never reaches this path, so it stays green against the
# bug. This fixture drives the grouped-field path specifically: two fields are
# assigned to the same `groups` entry, the first is made invalid, then the
# second is validated -- which is exactly the call sequence that reaches
# `Validator.element()`'s grouped branch and invokes `currentElements.push`/
# `pushStack` on the second field's cleanElement.
#
# Loads the real vendored public/js/100-jquery.js and
# public/js/120-jquery.validate.js -- no stub of the library under test.

set -euo pipefail

cd "$(dirname "$0")/.."

browser=${CHROMIUM_BIN:-}
readonly http_runner=.github/scripts/run-chrome-http-fixture.sh
if [[ -z $browser ]]; then
  browser=$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)
fi
if [[ -z $browser ]]; then
  echo 'FAIL: Chromium is required for the jQuery Validation group regression' >&2
  exit 2
fi

tmp=$(mktemp -d /tmp/vimbadmin-validate-group.XXXXXX)
cleanup() {
  rm -rf "$tmp"
}
trap cleanup EXIT

for asset in 100-jquery.js 120-jquery.validate.js; do
  if ! cp "public/js/$asset" "$tmp/$asset" 2>/dev/null; then
    echo "FAIL: required asset public/js/$asset not found" >&2
    exit 1
  fi
done

cat >"$tmp/regression.html" <<'HTML'
<!doctype html><html><head><meta charset="utf-8">
<script src="100-jquery.js"></script>
<script src="120-jquery.validate.js"></script>
</head><body>
<form id="grouped-form">
  <input id="first" name="first" type="text">
  <input id="second" name="second" type="text">
</form>
<pre id="output">PENDING</pre>
<script>
var failures = [];
function check(name, test) {
    try {
        if (!test()) throw new Error('false oracle');
    } catch (error) {
        // A TypeError from a symbol this migration deleted (jQuery.fn.push,
        // removed in jQuery 4.0.0) is the exact failure being guarded against,
        // so report it distinctly from a false oracle.
        failures.push(name + ': threw ' + (error && error.name ? error.name : 'Error') +
            ': ' + (error && error.message ? error.message : String(error)));
    }
}

$(function() {
    // No animations are used by this fixture, but keep parity with the
    // sibling migration fixture's guard against requestAnimationFrame never
    // stepping under --virtual-time-budget.
    $.fx.off = true;

    var validator = $('#grouped-form').validate({
        groups: { pair: 'first second' },
        rules: {
            first:  { required: true },
            second: { required: true }
        }
    });

    check('jQuery 4.0.0 loaded', function() { return $.fn.jquery === '4.0.0'; });

    // Reach Validator.element()'s grouped-field branch: mark "first" invalid,
    // then validate "second" -- a same-group sibling with no value of its own.
    // element() looks up this.groups['second'], finds it shares the group with
    // "first", and since "first" is already in v.invalid it re-validates
    // "first" via v.currentElements.push/pushStack(cleanElement). Only this
    // exact sequence (group entry + an already-invalid sibling) reaches that
    // line; validating a single ungrouped field never does.
    check('first field reports required when checked directly', function() {
        return !validator.element('#first');
    });
    check('validating grouped sibling does not throw and reports required', function() {
        return !validator.element('#second');
    });
    check('grouped sibling revalidation recorded the first field invalid', function() {
        return validator.invalid.first === true;
    });

    // Fill both fields and confirm the group re-validates clean without
    // throwing either -- the same code path, opposite outcome.
    $('#first').val('a');
    $('#second').val('b');
    check('grouped fields validate clean once both are filled', function() {
        return validator.element('#first') && validator.element('#second');
    });

    document.getElementById('output').textContent = JSON.stringify({ failures: failures });
    document.body.dataset.verdict = failures.length ? 'FAIL' : 'PASS';
});
</script></body></html>
HTML

run_case() {
  local output=$tmp/output.html
  chrome_args=(
    --headless --disable-gpu --virtual-time-budget=3000
    --user-data-dir="$tmp/profile" --dump-dom
    "http://127.0.0.1:8765/regression.html"
  )
  if [[ $browser == *run-headless-chrome.sh ]]; then
    "$browser" "${chrome_args[@]}" >"$output" 2>&1
  else
    CHROME_BIN=$browser "$http_runner" "$tmp" "${chrome_args[@]}" >"$output" 2>&1
  fi
  if ! grep -q 'data-verdict="PASS"' "$output"; then
    grep -o '<pre id="output">[^<]*' "$output" | sed 's/<pre id="output">//' >&2 || true
    return 1
  fi
}

run_case

echo 'OK: jQuery Validation grouped-field path does not throw under jQuery 4'
