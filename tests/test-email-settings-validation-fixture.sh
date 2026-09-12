#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

browser=${CHROMIUM_BIN:-}
readonly http_runner=.github/scripts/run-chrome-http-fixture.sh
if [[ -z $browser ]]; then
  browser=$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)
fi
if [[ -z $browser ]]; then
  echo 'FAIL: Chromium is required for the email-settings validation regression' >&2
  exit 2
fi

tmp=$(mktemp -d /tmp/vimbadmin-validate-group.XXXXXX)
cleanup() {
  rm -rf "${tmp:?}"
}
trap cleanup EXIT

cp public/css/800-bootstrap.css public/js/100-jquery.js public/js/120-vimbadmin.validation.js "$tmp/"
awk '
  /^jQuery\( document \)\.on\( .change., .#type./ { emit = 1 }
  emit { print }
  emit && /^} \);$/ { blocks++; if (blocks == 2) exit }
' application/views/mailbox/js/list.js >"$tmp/email-settings.js"
if [[ $(grep -c '^jQuery( document ).on' "$tmp/email-settings.js") -ne 2 ]]; then
  echo 'FAIL: could not extract both production email-settings handlers' >&2
  exit 1
fi

cat >"$tmp/regression.html" <<'HTML'
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="800-bootstrap.css">
<script src="100-jquery.js"></script><script src="120-vimbadmin.validation.js"></script>
</head><body>
<div id="modal_dialog">
  <form id="email_settings_form" action="/email-settings">
    <select id="type" name="type" class="form-select" required>
      <option value="local" selected>Local</option><option value="other">Other</option>
    </select>
    <div id="other_email" style="display:none">
      <input id="email" name="email" class="form-control">
    </div>
  </form>
  <div id="esfooter"><button id="modal_dialog_save">Send</button><button id="modal_dialog_cancel">Close</button></div>
</div>
<pre id="output">PENDING</pre>
<script>
var failures = [], ajaxCalls = 0, response = '<div class="modal-header">server error</div>';
function check(name, test) { try { if (!test()) throw new Error('false oracle'); } catch (e) { failures.push(name + ': ' + e.message); } }
var dialog = { modal: function() {} };
function tt_throbber() { return { appendTo: function() { return this; }, start: function() { return this; } }; }
function ossAjaxErrorHandler() {}
jQuery.fx.off = true;
jQuery.ajax = function(options) { ajaxCalls++; options.success(response); };
</script>
<script src="email-settings.js"></script>
<script>
jQuery(function() {
  var type = jQuery('#type'), email = document.getElementById('email');
  check('initial local recipient hides and does not require email', function() { return !email.required && jQuery('#other_email').is(':hidden'); });
  type.val('other').trigger('change');
  check('other recipient reveals and requires email', function() { return email.required && !jQuery('#other_email').is(':hidden'); });
  jQuery('#modal_dialog_save').trigger('click');
  check('invalid save is styled and does not call AJAX', function() { return ajaxCalls === 0 && email.classList.contains('is-invalid') && getComputedStyle(email).backgroundImage !== 'none'; });
  email.value = 'person@example.test';
  jQuery('#modal_dialog_save').trigger('click');
  check('valid save calls AJAX and renders server error fragment', function() { return ajaxCalls === 1 && jQuery('#modal_dialog .modal-header').text() === 'server error'; });
  jQuery('#modal_dialog').html('<form id="email_settings_form"><select id="type"><option value="other">Other</option><option value="local">Local</option></select><div id="other_email"><input id="email" required></div></form>');
  jQuery('#type').val('local').trigger('change');
  check('local transition hides and removes email requirement after rerender', function() { return !document.getElementById('email').required && jQuery('#other_email').is(':hidden'); });
  document.getElementById('output').textContent = JSON.stringify({ failures: failures });
  document.body.dataset.verdict = failures.length ? 'FAIL' : 'PASS';
});
</script></body></html>
HTML

output=$tmp/output.html
chrome_args=(--headless --disable-gpu --virtual-time-budget=3000 --user-data-dir="$tmp/profile" --dump-dom http://127.0.0.1:8765/regression.html)
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
echo 'OK: email-settings transitions and AJAX validation run through production handlers'
