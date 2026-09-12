#!/usr/bin/env bash

# Browser regression for the first-party Constraint Validation integration.

set -euo pipefail

cd "$(dirname "$0")/.."

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

cp public/js/120-vimbadmin.validation.js "$tmp/"

cat >"$tmp/regression.html" <<'HTML'
<!doctype html><html><head><meta charset="utf-8">
<script src="120-vimbadmin.validation.js" defer></script>
</head><body>
<form id="grouped-form">
  <input id="first" name="first" required data-validation-group="pair">
  <input id="second" name="second" required data-validation-group="pair">
  <input id="optional" name="optional">
  <input id="malformed" name="malformed" required data-validation-group="">
</form>
<form id="legacy-form"><input id="legacy" class="required"></form>
<form id="bypass-form">
  <input required>
  <button id="bypass" type="submit" formnovalidate>Bypass validation</button>
</form>
<form id="novalidate-form" novalidate><input required></form>
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
    var legacy = document.getElementById('legacy');
    var bypass = document.getElementById('bypass');

    // The production listener was registered by the deferred asset before
    // this one. Record its decision, then cancel every synthetic submit so a
    // successful case cannot navigate away from the fixture.
    document.addEventListener('submit', function(event) {
        event.validationPrevented = event.defaultPrevented;
        event.preventDefault();
    });

    check('invalid submit is cancelled and receives Bootstrap state', function() {
        var submit = new Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(submit);
        return submit.validationPrevented && form.classList.contains('was-validated') &&
            first.classList.contains('is-invalid') && second.classList.contains('is-invalid');
    });

    first.value = 'now valid';
    check('precondition: valid grouped sibling still has stale invalid state', function() {
        return first.checkValidity() && first.classList.contains('is-invalid');
    });
    second.value = 'also valid';
    second.dispatchEvent(new Event('input', { bubbles: true }));
    check('grouped sibling clears stale error state once valid', function() {
        return !first.classList.contains('is-invalid') && first.classList.contains('is-valid');
    });

    check('empty optional field is valid at the boundary', function() {
        optional.dispatchEvent(new Event('input', { bubbles: true }));
        return optional.checkValidity() && optional.classList.contains('is-valid');
    });

    check('empty group metadata does not couple malformed entries', function() {
        malformed.dispatchEvent(new Event('input', { bubbles: true }));
        return malformed.classList.contains('is-invalid') &&
            !optional.classList.contains('is-invalid');
    });

    check('legacy required class maps to the native required constraint', function() {
        var submit = new Event('submit', { bubbles: true, cancelable: true });
        legacy.form.dispatchEvent(submit);
        return submit.validationPrevented && legacy.required && legacy.classList.contains('is-invalid');
    });

    check('formnovalidate submitter preserves the native validation bypass', function() {
        var submit = new SubmitEvent('submit', {
            bubbles: true, cancelable: true, submitter: bypass
        });
        bypass.form.dispatchEvent(submit);
        return !submit.validationPrevented && !bypass.form.classList.contains('was-validated');
    });

    check('novalidate form preserves the native validation bypass', function() {
        var novalidateForm = document.getElementById('novalidate-form');
        var submit = new Event('submit', { bubbles: true, cancelable: true });
        novalidateForm.dispatchEvent(submit);
        return !submit.validationPrevented && !novalidateForm.classList.contains('was-validated');
    });

    malformed.value = 'valid';
    check('valid submit is not cancelled', function() {
        var submit = new Event('submit', { bubbles: true, cancelable: true });
        form.dispatchEvent(submit);
        return !submit.validationPrevented;
    });

    document.getElementById('output').textContent = JSON.stringify({ failures: failures });
    document.body.dataset.verdict = failures.length ? 'FAIL' : 'PASS';
});
</script></body></html>
HTML

output=$tmp/output.html
chrome_args=(
  --headless --disable-gpu --virtual-time-budget=3000
  --user-data-dir="$tmp/profile" --dump-dom
  http://127.0.0.1:8765/regression.html
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

echo 'OK: native validation covers grouped, boundary, malformed and legacy-required paths'
