#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/.."

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

cp public/js/min.bundle-v24.js "$tmp/min.bundle-v24.js"
bundle_uri="file://$tmp/min.bundle-v24.js"
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

    const settings = oDataTable.fnSettings();
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

    vmDataTableServerData(
        '/legitimate-empty',
        [{ name: 'sSearch', value: '' }, { name: 'sEcho', value: 11 }],
        function (result) {
            if (result.aaData.length === 0) emptyCallbacks++;
        },
        3,
        '#list_table',
        settings
    );
    const errorsBeforeFailure = dataTableErrors.length;
    vmDataTableServerData(
        '/transport-failure',
        [{ name: 'sSearch', value: 'valid search' }, { name: 'sEcho', value: 12 }],
        function () { failureCallbacks++; },
        3,
        '#list_table',
        settings
    );
    const surfacedTransportFailure = dataTableErrors.length === errorsBeforeFailure + 1
        && dataTableErrors[dataTableErrors.length - 1].technicalNote === 7;
    $.ajax = originalAjax;

    setTimeout(function () {
        const failures = [];
        const purgeDestination = document.querySelector(
            '#mailbox-purge-fixture [id|="alias-goto"]'
        );
        const logCell = Array.from(document.querySelectorAll('#log-fixture td'))
            .find((cell) => cell.textContent.trim() === payload);

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
        if (document.querySelector('#log-fixture .have-tooltip-long')) {
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
