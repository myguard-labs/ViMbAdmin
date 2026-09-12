#!/usr/bin/env bash

# Browser regression for the first-party Constraint Validation integration.

set -euo pipefail

cd "$(dirname "$0")/.."

source tests/support/resolve-bundle-v.sh

browser=${CHROMIUM_BIN:-}
readonly http_runner=.github/scripts/run-chrome-http-fixture.sh
if [[ -z $browser ]]; then
  browser=$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)
fi
if [[ -z $browser ]]; then
  echo 'FAIL: Chromium is required for the native validation regression' >&2
  exit 2
fi

tmp=$(mktemp -d /tmp/vimbadmin-validate-group.XXXXXX)
cleanup() {
  rm -rf "${tmp:?}"
}
trap cleanup EXIT

bundle_file=$(resolve_bundle_v) || exit $?
cp public/css/800-bootstrap.css public/js/120-vimbadmin.validation.js "public/js/$bundle_file" "$tmp/"

cat >"$tmp/regression.html" <<'HTML'
<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="800-bootstrap.css">
<script src="120-vimbadmin.validation.js" defer></script>
</head><body>
<form id="grouped-form">
  <input id="first" class="form-control" name="first" required data-validation-group="pair">
  <input id="second" class="form-control" name="second" required data-validation-group="pair">
  <input id="optional" class="form-control" name="optional">
  <input id="malformed" name="malformed" required data-validation-group="">
</form>
<form id="bypass-form">
  <input required>
  <button id="bypass" type="submit" formnovalidate>Bypass validation</button>
</form>
<form id="novalidate-form" novalidate><input required></form>
<form id="interactive-form">
  <input id="native-required" required>
  <input id="interactive-legacy" class="required">
  <input id="hidden-control" type="hidden" required value="token">
  <button type="submit">Submit</button>
</form>
<pre id="output">PENDING</pre>
<script>
var failures = [];
function check(name, test) {
    try {
        if (!test()) throw new Error('false oracle');
    } catch (error) {
        failures.push(name + ': ' + error.message);
    }
}

window.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('grouped-form');
    var first = document.getElementById('first');
    var second = document.getElementById('second');
    var optional = document.getElementById('optional');
    var malformed = document.getElementById('malformed');
    var bypass = document.getElementById('bypass');
    var interactiveForm = document.getElementById('interactive-form');
    var nativeRequired = document.getElementById('native-required');
    var interactiveLegacy = document.getElementById('interactive-legacy');
    var hiddenControl = document.getElementById('hidden-control');
    var interactiveSubmits = 0;
    var groupedSubmits = 0;
    var bypassSubmits = 0;
    var novalidateSubmits = 0;
    var interactivePrevented = null;
    var groupedPrevented = null;
    var bypassPrevented = null;
    var novalidatePrevented = null;
    var staleBackground = null;

    // The production listener was registered by the deferred asset before
    // this one. Record its decision, then cancel every successful submit so
    // the real requestSubmit()/click() paths cannot navigate away.
    document.addEventListener('submit', function(event) {
        if (event.target === interactiveForm) {
            interactiveSubmits++;
            interactivePrevented = event.defaultPrevented;
        }
        if (event.target === form) {
            groupedSubmits++;
            groupedPrevented = event.defaultPrevented;
        }
        if (event.target === bypass.form) {
            bypassSubmits++;
            bypassPrevented = event.defaultPrevented;
        }
        if (event.target.id === 'novalidate-form') {
            novalidateSubmits++;
            novalidatePrevented = event.defaultPrevented;
        }
        event.preventDefault();
    });

    check('precondition: browser blocks mixed invalid controls before submit', function() {
        interactiveForm.requestSubmit();
        return interactiveSubmits === 0 && interactiveForm.classList.contains('was-validated') &&
            nativeRequired.classList.contains('is-invalid');
    });
    nativeRequired.value = 'valid';
    check('unconstrained legacy class does not invent client-side validity', function() {
        interactiveForm.requestSubmit();
        return !interactiveLegacy.required && interactiveSubmits === 1 && interactivePrevented === false &&
            !interactiveLegacy.classList.contains('is-valid') &&
            !interactiveLegacy.classList.contains('is-invalid');
    });
    check('barred hidden control receives no Bootstrap validity class', function() {
        return !hiddenControl.willValidate && !hiddenControl.classList.contains('is-valid') &&
            !hiddenControl.classList.contains('is-invalid');
    });

    check('real invalid submission is blocked before submit and receives Bootstrap state', function() {
        form.requestSubmit();
        return groupedSubmits === 0 && form.classList.contains('was-validated') &&
            first.classList.contains('is-invalid') && second.classList.contains('is-invalid');
    });

    form.classList.remove('was-validated');
    first.value = 'now valid';
    check('precondition: valid grouped sibling still has stale invalid state', function() {
        staleBackground = getComputedStyle(first).backgroundImage;
        return first.checkValidity() && first.classList.contains('is-invalid') &&
            staleBackground !== 'none';
    });
    second.value = 'also valid';
    second.dispatchEvent(new Event('input', { bubbles: true }));
    check('grouped sibling clears stale error state once valid', function() {
        return !first.classList.contains('is-invalid') && first.classList.contains('is-valid') &&
            getComputedStyle(first).backgroundImage !== staleBackground;
    });

    check('empty optional field is valid at the boundary', function() {
        optional.dispatchEvent(new Event('input', { bubbles: true }));
        return optional.checkValidity() && !optional.classList.contains('is-valid') &&
            !optional.classList.contains('is-invalid');
    });

    check('empty group metadata does not couple malformed entries', function() {
        malformed.dispatchEvent(new Event('input', { bubbles: true }));
        return malformed.classList.contains('is-invalid') &&
            !optional.classList.contains('is-invalid');
    });

    check('formnovalidate submitter preserves the native validation bypass', function() {
        bypass.click();
        return bypassSubmits === 1 && bypassPrevented === false &&
            !bypass.form.classList.contains('was-validated');
    });

    check('novalidate form preserves the native validation bypass', function() {
        var novalidateForm = document.getElementById('novalidate-form');
        novalidateForm.requestSubmit();
        return novalidateSubmits === 1 && novalidatePrevented === false &&
            !novalidateForm.classList.contains('was-validated');
    });

    malformed.value = 'valid';
    check('valid submit is not cancelled', function() {
        form.requestSubmit();
        return groupedSubmits === 1 && groupedPrevented === false;
    });

    document.getElementById('output').textContent = JSON.stringify({ failures: failures });
    document.body.dataset.verdict = failures.length ? 'FAIL' : 'PASS';
});
</script></body></html>
HTML

sed "s#120-vimbadmin.validation.js#$bundle_file#" "$tmp/regression.html" >"$tmp/bundle.html"

run_case() {
  local page=$1
  local output=$tmp/output-$page
  local -a chrome_args=(
    --headless --disable-gpu --virtual-time-budget=3000
    --user-data-dir="$tmp/profile" --dump-dom
    "http://127.0.0.1:8765/$page"
  )
  if [[ $browser == *run-headless-chrome.sh ]]; then
    if ! "$browser" "${chrome_args[@]}" >"$output" 2>&1; then
      cat "$output" >&2
      exit 1
    fi
  else
    CHROME_BIN=$browser "$http_runner" "$tmp" "${chrome_args[@]}" >"$output" 2>&1
  fi
  if ! grep -q 'data-verdict="PASS"' "$output"; then
    grep -o '<pre id="output">[^<]*' "$output" | sed 's/<pre id="output">//' >&2 || true
    exit 1
  fi
}

run_case regression.html
run_case bundle.html

echo 'OK: source and bundle validation cover grouped, boundary, malformed and native-submit paths'
