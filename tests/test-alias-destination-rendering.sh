#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

browser="${CHROMIUM_BIN:-}"
if [[ -z "$browser" ]]; then
  browser="$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)"
fi
if [[ -z "$browser" ]]; then
  echo "FAIL: Chromium is required for the alias destination rendering regression" >&2
  exit 2
fi

tmp="$(mktemp -d /tmp/vimbadmin-alias-destination.XXXXXX)"
trap 'rm -rf "$tmp"' EXIT

cp public/js/min.bundle-v24.js "$tmp/min.bundle-v24.js"
bundle_uri="file://$tmp/min.bundle-v24.js"
if [[ -n ${PHP_RENDERER:-} ]]; then
  PHP_CONTAINER_WRITE_DIR=$tmp \
    "$PHP_RENDERER" tests/render-alias-list-fixture.php \
    "$tmp/regression.html" "$bundle_uri"
else
  php tests/render-alias-list-fixture.php "$tmp/regression.html" "$bundle_uri"
fi

# Execute the production formatters rather than test copies. Select them from
# the rendered server-side script so Smarty has resolved routes and CSRF data.
awk '
    /^function formatGoto\(/ { copying = 1 }
    copying { print }
' "$tmp/regression.html.server-side.js" >"$tmp/alias-formatters.js"

if ! grep -q '^function formatGoto' "$tmp/alias-formatters.js" ||
  ! grep -q '^function formatControlls' "$tmp/alias-formatters.js"; then
  echo "FAIL: could not extract the production alias formatters" >&2
  exit 2
fi

{
  printf '<script>\n'
  cat "$tmp/alias-formatters.js"
  cat <<'HTML'

const payload = '"<svg/onload=document.body.dataset.pwned=1>"@example.com';
const normalLongDestination = 'first.destination@example.com,second.destination@example.net';
const expectedDeleteAction = 'http://localhost/alias/delete';

const dynamicRows = document.createElement('table');
dynamicRows.innerHTML = '<tbody>' +
    '<tr id="alias_41"><td id="malicious-destination"></td>' +
        '<td id="dynamic-controls"></td></tr>' +
    '<tr id="alias_42"><td id="long-destination"></td></tr>' +
    '</tbody>';
document.body.appendChild(dynamicRows);
document.getElementById('dynamic-controls').innerHTML = formatControlls(41);

// This is the production list-data boundary: DataTables parses the JSON row,
// calls formatGoto(), then assigns the returned markup to the destination cell.
const response = JSON.parse(JSON.stringify({
    sEcho: 1,
    iTotalRecords: 2,
    iTotalDisplayRecords: 2,
    aaData: [
        { id: 41, goto: payload },
        { id: 42, goto: normalLongDestination }
    ]
}));

document.getElementById('malicious-destination').innerHTML =
    formatGoto(response.aaData[0].id, response.aaData[0].goto);
document.getElementById('long-destination').innerHTML =
    formatGoto(response.aaData[1].id, response.aaData[1].goto);

$(function () {
    $('#alias-goto-43').trigger('mouseenter');
    $('#delete-alias-41').trigger('click');

    setTimeout(function () {
        const failures = [];
        const malicious = document.getElementById('alias-goto-41');
        const normal = document.getElementById('alias-goto-42');
        const serverRendered = document.getElementById('alias-goto-43');

        if (document.body.dataset.pwned !== '0') failures.push('event handler executed');
        if (document.querySelector('svg')) failures.push('SVG element created');
        if (document.querySelector('[onload]')) failures.push('onload attribute created');
        if (!malicious) failures.push('destination element missing');
        if (malicious && malicious.textContent !== payload.slice(0, 50) + '...') {
            failures.push('literal malicious text changed');
        }
        if (malicious && malicious.title !== payload) failures.push('full malicious title changed');
        if (!normal) failures.push('normal destination element missing');
        if (normal && normal.textContent !== normalLongDestination.slice(0, 50) + '...') {
            failures.push('normal truncation changed');
        }
        if (normal && normal.title !== normalLongDestination.replace(/[,]/g, ', ')) {
            failures.push('normal full title unreadable');
        }
        if (!serverRendered) failures.push('server-rendered destination missing');
        if (serverRendered && serverRendered.textContent.trim() !== payload.slice(0, 50) + '...') {
            failures.push('server-rendered literal text changed');
        }
        if (serverRendered && serverRendered.title !== payload) {
            failures.push('server-rendered full title changed');
        }
        if (!document.getElementById('edit_alias_41')) failures.push('dynamic edit action removed');
        // VIM-D05: deletion is a POST carrying the token in the body, not a
        // GET link with the token in the URL. Assert that contract -- the
        // control is a submit button inside a CSRF-bearing form -- rather than
        // the href shape it deliberately no longer has.
        const dynamicDelete = document.getElementById('delete-alias-41');
        if (!dynamicDelete) failures.push('dynamic delete action removed');
        const deleteForm = dynamicDelete && dynamicDelete.closest('form');
        if (dynamicDelete && !deleteForm) {
            failures.push('dynamic delete action is not inside a form');
        }
        if (deleteForm && deleteForm.getAttribute('method').toLowerCase() !== 'post') {
            failures.push('dynamic delete form is not a POST');
        }
        if (deleteForm && deleteForm.action !== expectedDeleteAction) {
            failures.push('dynamic delete form action changed');
        }
        if (deleteForm) {
            const token = deleteForm.querySelector('input[name="csrf"]');
            if (!token || token.value !== 'test-csrf-token') {
                failures.push('dynamic delete action missing CSRF token');
            }
            const alid = deleteForm.querySelector('input[name="alid"]');
            if (!alid || alid.value !== '41') {
                failures.push('dynamic delete form lost its alias id');
            }
        }
        if (dynamicDelete && dynamicDelete.getAttribute('href')) {
            failures.push('dynamic delete action is still a GET link');
        }
        const confirmedDelete = document.getElementById('purge_dialog_delete');
        if (!confirmedDelete) failures.push('delete confirmation control removed');
        if (!document.getElementById('edit_alias_43')) failures.push('server-rendered edit action removed');
        if (!document.getElementById('delete-alias-43')) failures.push('server-rendered delete action removed');

        document.body.dataset.testResult = failures.length === 0 ? 'pass' : 'fail';
        document.body.dataset.testFailures = failures.join('; ');
    }, 100);
});
</script>
</body>
HTML
} >>"$tmp/regression.html"

"$browser" \
  --headless \
  --disable-gpu \
  --allow-file-access-from-files \
  --user-data-dir="$tmp/profile" \
  --virtual-time-budget=1000 \
  --dump-dom "file://$tmp/regression.html" >"$tmp/rendered.html" 2>"$tmp/chromium.log"

if ! grep -q 'data-test-result="pass"' "$tmp/rendered.html"; then
  failures="$(grep -o 'data-test-failures="[^"]*"' "$tmp/rendered.html" || true)"
  echo "FAIL: production alias destination formatter is unsafe: ${failures:-no browser verdict}" >&2
  exit 1
fi

echo "OK: browser rendered alias destinations as literal text with non-HTML titles"
