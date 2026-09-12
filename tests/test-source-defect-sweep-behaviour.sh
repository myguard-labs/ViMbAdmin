#!/usr/bin/env bash

# VIM-A15.43 / VIM-A15.47: two pre-existing SOURCE defects CodeRabbit surfaced
# while reviewing PR #186's bundle regeneration but that PR was artifact-only
# and could not carry a source fix, so they were ledgered instead. Both are
# behavioural, not stylistic, and a string check on the source cannot prove
# either is fixed -- so this drives the real production functions in a real
# browser, the same pattern tests/test-confirm-guard-behaviour.sh uses for
# VIM-D07.
#
# VIM-A15.43: ossToggle()'s cleanup used `typeof( delElement ) != undefined`.
# `typeof` always yields a STRING, so `"undefined" != undefined` is ALWAYS
# TRUE regardless of whether delElement was passed -- the guard guarded
# nothing. An empty selection can silently do nothing, so this asserts the
# selector is never invoked when delElement is absent.
#
# VIM-A15.47: ossAlert(), when Bootstrap's Modal constructor is
# unavailable, removed the dialog and ran the callback WITHOUT ever showing
# the message -- so `ossAlert('Delete failed, contact support')` told the
# user nothing while the caller believed the alert had been acknowledged. This
# asserts the message text still reaches the user (via window.alert) on that
# fallback path, and that the callback still fires.
#
# VIM-A15.44: addPluginTab() emitted `class="text-error"` for a plugin tab
# whose panel contains an `.error` element. `text-error` is a Bootstrap 2
# class with no Bootstrap 5 styling, so the tab silently lost its error
# indicator; the Bootstrap 5 spelling is `text-danger`. This asserts the
# emitted tab markup carries `text-danger` and never `text-error`.
#
# VIM-A15.49: ossToggle()'s AJAX `complete:` handler ran
# `if( delElement ) { ... remove() }` unconditionally. The transport's `complete`
# fires on success AND on error/timeout/non-"ok" response body -- on failure
# the handler correctly reverts the toggle (`if( !ok ) on = !on;`) but then
# deleted the associated row anyway, so the page claimed the row was gone
# while the server still had it. This asserts a failed request (success
# callback invoked with a non-"ok" body, mirroring the real failure path)
# leaves delElement present in the DOM.

set -euo pipefail

cd "$(dirname "$0")/.."
source tests/support/resolve-bundle-v.sh

browser="${CHROMIUM_BIN:-}"
if [[ -z "$browser" ]]; then
  browser="$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)"
fi
if [[ -z "$browser" ]]; then
  echo "FAIL: Chromium is required for the source-defect-sweep regression" >&2
  exit 2
fi

tmp="$(mktemp -d /tmp/vimbadmin-source-defect-sweep.XXXXXX)"
trap 'rm -rf "$tmp"' EXIT

cp public/js/150-datatables.js public/js/800-bootstrap.js "$tmp/"
cp public/js/990-vimbadmin.js "$tmp/990-vimbadmin.js"
cp public/js/850-vimbadmin.modals.js "$tmp/850-vimbadmin.modals.js"
bundle_file=$(resolve_bundle_v) || exit $?
cp "public/js/$bundle_file" "$tmp/bundle.js"
# Extract the production toggle wrapper and document delegate, replacing only
# server-rendered URL/token literals with fixed fixture values.
# The token placeholder is Smarty syntax, not a shell variable.
# shellcheck disable=SC2016
awk '
  /^function toggleActive\(/ { active = 1; wrappers++ }
  /^DataTable.Dom.select\( document \).on.*data-toggle-active/ { active = 1; delegates++ }
  active { print }
  active && /^};|^} \);/ { active = 0 }
  END { if (wrappers != 1 || delegates != 1) exit 1 }
' application/views/alias/js/list.js |
  sed -e 's/{genUrl[^}]*}/\/x/g' -e 's/{$csrfToken}/fixture-csrf/g' >"$tmp/toggle-view.js"

cat >"$tmp/regression.html" <<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"></head><body>
<script>
var assets = new URL(location.href).searchParams.get('assets') === 'production'
    ? ['bundle.js']
    : ['150-datatables.js', '800-bootstrap.js', '990-vimbadmin.js', '850-vimbadmin.modals.js'];
assets.concat(['toggle-view.js']).forEach(function(file) {
    document.write('<script src="' + file + '"><\/script>');
});
</script>

<button id="toggle-target" class="btn btn-success" data-throb-key="t1"></button>
<div id="throb-toggle-target"></div>
<div id="del-target">to be hidden and removed</div>
<button id="toggle-target-fail" class="btn btn-success"></button>
<div id="throb-toggle-target-fail"></div>
<div id="del-target-fail">must survive a failed toggle request</div>
<ul id="plugin_tabs" style="display:none"></ul>
<div id="tab_errplug"><span class="error">bad plugin</span></div>

<script>
var results = { toggleRan: false, toggleDelRemoved: null, undefinedCallSeen: false, alertMessage: null, alertCallbackRan: false, pluginTabClass: null, failedToggleDelSurvived: null, retriedToggleDelRemoved: null };

vmReady(function () {
    var failures = [];

    // -- VIM-A15.43: absent delElement must not enter the cleanup branch at all --
    //
    // The old guard, `typeof( delElement ) != undefined`, is a string-vs-value
    // comparison that is ALWAYS true. An empty selection has no visible effect,
    // so an end-state assertion cannot distinguish a missing guard. Observe
    // DataTable.Dom.select directly: omitted delElement must never reach it.
    var realSelect = DataTable.Dom.select;
    var wrappedSelect = function (selector) {
        if (selector === undefined) results.undefinedCallSeen = true;
        return realSelect.apply(this, arguments);
    };
    DataTable.Dom.select = wrappedSelect;

    var xhr = { open: function () {}, send: function () {} };
    var realAjax = ossAjax;
    ossAjax = function (opts) {
        // Synchronously resolve as success, mirroring the shape ossToggle's
        // own success/complete handlers expect, with NO delElement passed to
        // ossToggle at all -- the exact absent-argument path VIM-A15.43 named.
        opts.success('ok');
        opts.complete();
        return xhr;
    };
    try {
        var target = wrappedSelect('#toggle-target');
        ossToggle(target, '/x', {});
        results.toggleRan = true;
        results.toggleDelRemoved = target.prop('disabled') === false && target.hasClass('btn-danger');
    } catch (e) {
        failures.push('ossToggle with absent delElement threw: ' + e);
    } finally {
        ossAjax = realAjax;
        DataTable.Dom.select = realSelect;
    }

    // -- VIM-A15.49: a failed toggle request must not remove delElement --
    //
    // The real cleanup calls DataTable.Dom.select(delElement).transition(...),
    // an animated (600ms) removal. Headless dump-dom under a bounded virtual
    // time budget can catch that animation mid-flight regardless of whether the
    // removal branch even ran, which would make this control vacuous in both
    // directions. Disable transitions to invoke completion synchronously
    // so the assertion below reflects whether ossToggle's `if( delElement [&&
    // ok] )` branch ran at all, not whether an unrelated animation finished.
    var realTransitions = DataTable.Dom.transitions;
    DataTable.Dom.transitions = false;
    var realAjax2 = ossAjax;
    ossAjax = function (opts) {
        // Mirror the real failure path: success is invoked with a non-"ok"
        // body (so ok stays false and complete() reverts the toggle), with
        // delElement passed in exactly as ossToggle's real callers do.
        opts.success('failed: in use');
        opts.complete();
        return xhr;
    };
    DataTable.Dom.select = wrappedSelect;
    try {
        var failTarget = wrappedSelect('#toggle-target-fail');
        ossToggle(failTarget, '/x', {}, '#del-target-fail');
        results.failedToggleDelSurvived = document.getElementById('del-target-fail') !== null;

        // A later successful call must still honor the optional removal
        // argument. Click/delegate retries are exercised separately below.
        ossAjax = function (opts) {
            opts.success('ok');
            opts.complete();
            return xhr;
        };
        ossToggle(failTarget, '/x', {}, '#del-target-fail');
        results.retriedToggleDelRemoved = document.getElementById('del-target-fail') === null;
    } catch (e) {
        failures.push('ossToggle with failed request threw: ' + e);
    } finally {
        ossAjax = realAjax2;
        DataTable.Dom.transitions = realTransitions;
        DataTable.Dom.select = realSelect;
    }

    // Production toggleActive wrapper + document delegate + ossToggle. Spans
    // deliberately match shipped row markup: disabled does not stop clicks.
    var table = document.createElement('table');
    document.body.appendChild(table);
    var toggleApi;
    var requests = [];
    ossAjax = function(opts) { requests.push(opts); return xhr; };
    try {
        toggleApi = new DataTable(table, { data: [], columns: [{ title: 'Active' }], order: [] });
        var markup = '<div id="throb-toggle-active-probe"></div>' +
            '<span id="toggle-active-probe" data-toggle-active="probe" class="btn btn-success"><i>Yes</i></span>';
        toggleApi.rows.add([[markup]]).draw();
        var control = table.querySelector('[data-toggle-active]');
        function clickToggle(node) {
            (node.firstElementChild || node).dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        }
        control.classList.add('disabled');
        clickToggle(control);
        if (requests.length !== 0) throw new Error('disabled toggle sent a request');
        control.classList.remove('disabled');
        var outcomes = ['ok', 'failed: in use', 'error', 'timeout', 'abort', '<malformed>'];
        outcomes.forEach(function(outcome, index) {
            var oldClass = control.className;
            clickToggle(control);
            if (requests.length !== index + 1) throw new Error('accepted click did not send exactly one request');
            var request = requests[index];
            if (request.url !== '/x' || request.type !== 'POST' || request.data.alid !== 'probe'
                || request.data.csrf !== 'fixture-csrf') throw new Error('toggle request contract changed');
            clickToggle(control);
            clickToggle(control);
            if (requests.length !== index + 1) throw new Error('pending toggle accepted another request');
            if (['error', 'timeout', 'abort'].indexOf(outcome) !== -1) request.error(xhr, outcome, 'fixture error');
            else request.success(outcome);
            request.complete();
            if (control.disabled !== false) throw new Error('completed toggle remained disabled');
            if (control._event_uid !== undefined) throw new Error('toggle owns a direct DataTables listener after completion');
            if (outcome === 'ok' ? !control.classList.contains('btn-danger') : control.className !== oldClass)
                throw new Error('toggle success/failure state incorrect');
        });
        // Replace a row after success/error/retry use, then click both detached
        // and current controls. Only the current control may reach transport.
        var detached = control;
        toggleApi.clear().rows.add([[markup]]).draw();
        clickToggle(detached);
        if (requests.length !== outcomes.length) throw new Error('detached toggle sent a request');
        control = table.querySelector('[data-toggle-active]');
        clickToggle(control);
        if (requests.length !== outcomes.length + 1) throw new Error('redrawn toggle lost delegation');
        requests[outcomes.length].success('ok');
        requests[outcomes.length].complete();
        if (control._event_uid !== undefined) throw new Error('redrawn toggle owns a direct listener');
    } catch (e) {
        failures.push('delegated toggle lifecycle: ' + e.message);
    } finally {
        ossAjax = realAjax;
        if (toggleApi) toggleApi.destroy();
        table.remove();
    }

    // -- VIM-A15.47: Modal unavailable must still surface the message --
    var realAlert = window.alert;
    var realModal = window.bootstrap;
    window.alert = function (msg) { results.alertMessage = msg; };
    // Simulate Bootstrap's JS not having loaded: ossAlert() looks
    // up the Modal constructor lazily, so removing window.bootstrap makes that
    // lookup fail exactly like an unloaded Bootstrap bundle would.
    window.bootstrap = undefined;
    try {
        ossAlert('Delete failed, contact support', function () { results.alertCallbackRan = true; });
    } catch (e) {
        failures.push('ossAlert with Modal unavailable threw: ' + e);
    } finally {
        window.alert = realAlert;
        window.bootstrap = realModal;
    }

    // -- VIM-A15.44: the plugin tab error class must be the BS5 spelling --
    addPluginTab('Bad Plugin', 'errplug');
    var tabAnchor = document.querySelector('#plugin_tabs a[href="#tab_errplug"]');
    results.pluginTabClass = tabAnchor ? tabAnchor.className : null;

    if (!results.toggleRan) failures.push('ossToggle did not run with delElement omitted');
    if (results.toggleDelRemoved !== true) failures.push('ossToggle left the toggle button in a bad state with delElement omitted');
    if (results.undefinedCallSeen) failures.push('ossToggle selected undefined even though delElement was omitted -- the guard is not gating anything');
    if (results.failedToggleDelSurvived !== true) failures.push('ossToggle removed delElement even though the request failed -- the row vanished from the page while the server still has it');
    if (results.retriedToggleDelRemoved !== true) failures.push('a successful retry after a failed ossToggle left delElement on the page');
    if (results.alertMessage !== 'Delete failed, contact support') {
        failures.push('ossAlert did not surface its message via window.alert when Modal was unavailable: got ' + JSON.stringify(results.alertMessage));
    }
    if (results.alertCallbackRan !== true) failures.push('ossAlert callback did not run when Modal was unavailable');
    if (results.pluginTabClass === null) {
        failures.push('addPluginTab did not emit a tab anchor for the errored plugin panel');
    } else {
        if (results.pluginTabClass.indexOf('text-error') !== -1) failures.push('addPluginTab still emits the Bootstrap 2 class text-error');
        if (results.pluginTabClass.indexOf('text-danger') === -1) failures.push('addPluginTab did not emit the Bootstrap 5 class text-danger: got ' + results.pluginTabClass);
    }

    document.body.dataset.testResult = failures.length === 0 ? 'pass' : 'fail';
    document.body.dataset.testFailures = failures.join('; ');
});
</script>
</body></html>
HTML

for mode in source production; do
rm -rf "$tmp/profile"
"$browser" \
  --headless \
  --disable-gpu \
  --allow-file-access-from-files \
  --user-data-dir="$tmp/profile" \
  --virtual-time-budget=1000 \
  --dump-dom "file://$tmp/regression.html?assets=$mode" >"$tmp/rendered.html" 2>"$tmp/chromium.log"

if ! grep -q 'data-test-result="pass"' "$tmp/rendered.html"; then
  failures="$(grep -o 'data-test-failures="[^"]*"' "$tmp/rendered.html" || true)"
  echo "FAIL: source-defect-sweep regression ($mode): ${failures:-no browser verdict}" >&2
  exit 1
fi
echo "ok   source-defect-sweep $mode asset lane"
done

echo "ok   ossToggle with delElement omitted runs cleanly (VIM-A15.43)"
echo "ok   addPluginTab emits text-danger, not text-error (VIM-A15.44)"
echo "ok   ossAlert surfaces its message when Modal is unavailable (VIM-A15.47)"
echo "ok   ossToggle leaves delElement in place when the request fails (VIM-A15.49)"
echo "ok   a successful retry after a failed ossToggle removes delElement (VIM-A15.49)"
echo "ok   production toggle delegate sends one request, suppresses pending clicks and survives redraw without direct listeners"
echo "ALL PASSED"
