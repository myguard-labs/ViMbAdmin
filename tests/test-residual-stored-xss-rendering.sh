#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

# Source the bundle resolver
source tests/support/resolve-bundle-v.sh

browser="${CHROMIUM_BIN:-}"
if [[ -z "$browser" ]]; then
  browser="$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)"
fi
if [[ -z "$browser" ]]; then
  echo "FAIL: Chromium is required for the residual stored-XSS regression" >&2
  exit 2
fi

tmp="$(mktemp -d /tmp/vimbadmin-residual-stored-xss.XXXXXX)"
trap 'rm -rf "$tmp"' EXIT

bundle_file=$(resolve_bundle_v) || exit $?
cp "public/js/$bundle_file" "$tmp/$bundle_file"
bundle_uri="file://$tmp/$bundle_file"
if [[ -n ${PHP_RENDERER:-} ]]; then
  PHP_CONTAINER_WRITE_DIR=$tmp \
    "$PHP_RENDERER" tests/render-residual-stored-xss-fixture.php \
    "$tmp/regression.html" "$bundle_uri"
else
  php tests/render-residual-stored-xss-fixture.php \
    "$tmp/regression.html" "$bundle_uri"
fi

cat <<'HTML' >>"$tmp/regression.html"
<script>
const payload = 'destination@example.test,'.repeat(3) +
    '"<svg/onload=document.body.dataset.pwned=1>"@example.test';
const dataTableErrors = [];

// VIM-A15.56a1: #log-fixture's #list_table is a real serverSide DataTable. The
// escaping under test lives in the server-rendered <tbody> the fixture ships,
// but a 2.x serverSide table clears that body and issues an XHR on init (1.x
// left the pre-rendered rows in place when its request failed). Capture the
// server-rendered cell text NOW, before DataTables can replace it, so the
// literal-text assertions below test the PHP escaping they were written for.
const serverRenderedLogCells = Array.from(
    document.querySelectorAll('#log-fixture td')
).map((cell) => cell.textContent.trim());
const serverRenderedLogHtml = (
    document.querySelector('#log-fixture') || { innerHTML: '' }
).innerHTML;

// Keep DataTables' normal error reporting observable without opening a modal
// alert in headless Chrome.
$.fn.dataTable.ext.sErrMode = $.fn.dataTable.ext.errMode = function (_settings, technicalNote, message) {
    dataTableErrors.push({ technicalNote, message });
};

// Bootstrap 5 binds its tooltip through its own EventHandler, which maps
// `mouseenter` onto the NATIVE `mouseover` (delegation is why: mouseenter does
// not bubble). jQuery's `.trigger('mouseenter')` dispatches a synthetic jQuery
// event that never reaches a native listener, so under Bootstrap 5 it opens no
// tooltip at all -- and the positive control then fails while the negative
// assertions below pass vacuously, which is the worst possible reading. Dispatch
// a real MouseEvent so this file tests the application rather than jQuery's
// event shim.
function openTooltip(el) {
    if (el) el.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
}

$(function () {
    openTooltip(document.getElementById('trusted-html-tooltip'));
    document.querySelectorAll('#mailbox-purge-fixture [id|="alias-goto"]').forEach(openTooltip);
    openTooltip(document.getElementById('log-message-91'));

    // VIM-A15.56a1: under DataTables 1.x, vmDataTableServerData() WAS the
    // fnServerData callback and ran the request itself, so this block called it
    // directly with (source, data, callback, minimum, selector, settings). 2.x
    // removed fnServerData; the shim now returns an `ajax` OBJECT and DataTables
    // owns the request lifecycle, its error reporting and its teardown. So drive
    // the real thing: initialise a server-side table over the stubbed transport
    // and assert the same three properties as before -- a legitimate empty
    // response reaches the table, a transport failure does not, and the failure
    // surfaces through DataTables' own error channel as technicalNote 7.
    const originalAjax = $.ajax;
    let emptyCallbacks = 0;
    let failureCallbacks = 0;
    let ajaxCalls = 0;

    $.ajax = function (options) {
        ajaxCalls++;
        if (options.url === '/legitimate-empty') {
            options.success({
                sEcho: 11,
                iTotalRecords: 0,
                iTotalDisplayRecords: 0,
                aaData: []
            });
        } else {
            options.error({ readyState: 4 }, 'error');
        }
        return { abort: function () {} };
    };

    // The shim's ajax object, driven through DataTables exactly as a migrated
    // view initialiser drives it.
    function drawServerSideTable(source, onData) {
        // Park the probe table in its own container so its cells cannot be
        // picked up by the document-wide `#log-fixture td` / `svg` / `[onload]`
        // sweeps below.
        const $host = $('<div class="serverside-probe" style="display:none"></div>');
        const $table = $('<table><thead><tr><th>Col</th></tr></thead><tbody></tbody></table>');
        $host.append($table);
        $('body').append($host);
        const api = $table.DataTable({
            serverSide: true,
            paging: false,
            searching: false,
            info: false,
            ajax: vmDataTableServerData(source, 3, '#list_table'),
            drawCallback: function () { if (onData) onData(this.api()); }
        });
        return { api: api, $table: $table };
    }

    const emptyTable = drawServerSideTable('/legitimate-empty', function (api) {
        if (api.rows().count() === 0) emptyCallbacks++;
    });
    const errorsBeforeFailure = dataTableErrors.length;
    const failedTable = drawServerSideTable('/transport-failure', function () {
        failureCallbacks++;
    });
    const surfacedTransportFailure = dataTableErrors.length === errorsBeforeFailure + 1
        && dataTableErrors[dataTableErrors.length - 1].technicalNote === 7;
    emptyTable.api.destroy();
    failedTable.api.destroy();
    $('.serverside-probe').remove();
    $.ajax = originalAjax;

    setTimeout(function () {
        const failures = [];
        const purgeDestination = document.querySelector(
            '#mailbox-purge-fixture [id|="alias-goto"]'
        );
        const logCell = serverRenderedLogCells.find((text) => text === payload);

        if (!document.getElementById('trusted-tooltip-content')) {
            failures.push('positive HTML-tooltip control did not render');
        }
        if (document.body.dataset.pwned !== '0') failures.push('event handler executed');
        if (document.querySelector('svg')) failures.push('SVG element created');
        if (document.querySelector('[onload]')) failures.push('onload attribute created');
        if (!purgeDestination) failures.push('purge destination missing');
        if (purgeDestination && purgeDestination.textContent.trim() !== payload.slice(0, 50) + '...') {
            failures.push('purge destination text changed');
        }
        if (purgeDestination && purgeDestination.title !== payload.replace(/[,]/g, ', ')) {
            failures.push('purge destination native title changed');
        }
        if (purgeDestination && purgeDestination.classList.contains('have-tooltip-long')) {
            failures.push('purge destination retained HTML tooltip');
        }
        if (!logCell) failures.push('log data was not rendered as literal text');
        if (/have-tooltip-long/.test(serverRenderedLogHtml)) {
            failures.push('log data reached an HTML tooltip');
        }
        if (ajaxCalls !== 2) failures.push('empty and failed requests did not use AJAX');
        if (emptyCallbacks !== 1) failures.push('legitimate empty response did not reach callback');
        if (failureCallbacks !== 0) failures.push('transport failure reached success callback');
        if (!surfacedTransportFailure) {
            failures.push('transport failure did not surface as a DataTables AJAX error');
        }

        document.body.dataset.testResult = failures.length === 0 ? 'pass' : 'fail';
        document.body.dataset.testFailures = failures.join('; ');
    }, 100);
});
</script>
</body>
HTML

"$browser" \
  --headless \
  --disable-gpu \
  --allow-file-access-from-files \
  --user-data-dir="$tmp/profile" \
  --virtual-time-budget=1000 \
  --dump-dom "file://$tmp/regression.html" >"$tmp/rendered.html" 2>"$tmp/chromium.log"

if ! grep -q 'data-test-result="pass"' "$tmp/rendered.html"; then
  failures="$(grep -o 'data-test-failures="[^"]*"' "$tmp/rendered.html" || true)"
  echo "FAIL: residual stored-XSS rendering is unsafe: ${failures:-no browser verdict}" >&2
  exit 1
fi

echo 'OK: mailbox purge and log data render as literal text outside HTML tooltips'
