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
# nothing. It was harmless only by luck (`$(undefined)` is an empty jQuery
# set and `.hide()` on it is a no-op), so this asserts the callback the guard
# is supposed to gate is never invoked when delElement is absent.
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
# VIM-A15.49: ossToggle()'s $.ajax `complete:` handler ran
# `if( delElement ) { ... remove() }` unconditionally. jQuery's `complete`
# fires on success AND on error/timeout/non-"ok" response body -- on failure
# the handler correctly reverts the toggle (`if( !ok ) on = !on;`) but then
# deleted the associated row anyway, so the page claimed the row was gone
# while the server still had it. This asserts a failed request (success
# callback invoked with a non-"ok" body, mirroring the real failure path)
# leaves delElement present in the DOM.

set -euo pipefail

cd "$(dirname "$0")/.."

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

cp public/js/100-jquery.js "$tmp/jquery.js"
cp public/js/990-vimbadmin.js "$tmp/990-vimbadmin.js"
cp public/js/850-vimbadmin.modals.js "$tmp/850-vimbadmin.modals.js"

cat >"$tmp/regression.html" <<'HTML'
<!doctype html>
<html><head><meta charset="utf-8"></head><body>
<script src="jquery.js"></script>
<script src="990-vimbadmin.js"></script>
<script>
// ossAddMessage() (invoked on a failed toggle) ends by calling the Bootstrap
// jQuery plugin .alert() on the message it just inserted. The real Bootstrap
// JS bundle is out of scope for this fixture, so this stubs the plugin as a
// no-op -- ossAddMessage's own insertion logic and ossToggle's delElement
// handling are what is under test.
jQuery.fn.alert = function () { return this; };
</script>
<script src="850-vimbadmin.modals.js"></script>

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

$(function () {
    var failures = [];

    // -- VIM-A15.43: absent delElement must not enter the cleanup branch at all --
    //
    // The old guard, `typeof( delElement ) != undefined`, is a string-vs-value
    // comparison that is ALWAYS true, so it always called $( delElement ).hide(...)
    // regardless of whether delElement was passed. $(undefined) is an empty
    // jQuery set and .hide() on it silently no-ops, so the DOM end state is
    // identical whether the branch ran or not -- an end-state assertion cannot
    // discriminate the fixed guard from the broken one (this is the "harmless
    // today only by luck" the item names). What DOES discriminate is whether
    // the jQuery constructor `$` was ever invoked with `undefined` from inside
    // that cleanup at all: the fixed guard (`if( delElement )`) never calls
    // $(undefined) when delElement is omitted, the old guard always did.
    var realJQuery = window.$;
    var wrappedJQuery = function (selector) {
        if (selector === undefined) results.undefinedCallSeen = true;
        return realJQuery.apply(this, arguments);
    };
    for (var key in realJQuery) { if (Object.prototype.hasOwnProperty.call(realJQuery, key)) wrappedJQuery[key] = realJQuery[key]; }
    wrappedJQuery.fn = realJQuery.fn;
    wrappedJQuery.prototype = realJQuery.prototype;
    window.$ = wrappedJQuery;
    jQuery = wrappedJQuery;

    var xhr = { open: function () {}, send: function () {} };
    var realAjax = wrappedJQuery.ajax;
    wrappedJQuery.ajax = function (opts) {
        // Synchronously resolve as success, mirroring the shape ossToggle's
        // own success/complete handlers expect, with NO delElement passed to
        // ossToggle at all -- the exact absent-argument path VIM-A15.43 named.
        opts.success('ok');
        opts.complete();
        return xhr;
    };
    try {
        var target = wrappedJQuery('#toggle-target');
        ossToggle(target, '/x', {});
        results.toggleRan = true;
        results.toggleDelRemoved = target.prop('disabled') === false && target.hasClass('btn-danger');
    } catch (e) {
        failures.push('ossToggle with absent delElement threw: ' + e);
    } finally {
        wrappedJQuery.ajax = realAjax;
        window.$ = realJQuery;
        jQuery = realJQuery;
    }

    // -- VIM-A15.49: a failed toggle request must not remove delElement --
    //
    // The real cleanup calls $(delElement).hide('slow', function(){ remove() }),
    // an animated (~600ms) removal. Headless dump-dom under a bounded virtual
    // time budget can catch that animation mid-flight regardless of whether the
    // removal branch even ran, which would make this control vacuous in both
    // directions. Stub .hide() to invoke its completion callback synchronously
    // so the assertion below reflects whether ossToggle's `if( delElement [&&
    // ok] )` branch ran at all, not whether an unrelated animation finished.
    var realHide = jQuery.fn.hide;
    jQuery.fn.hide = function ( duration, callback ) {
        if ( typeof callback === 'function' ) callback.call( this );
        else if ( typeof duration === 'function' ) duration.call( this );
        return this;
    };
    var realAjax2 = wrappedJQuery.ajax;
    wrappedJQuery.ajax = function (opts) {
        // Mirror the real failure path: success is invoked with a non-"ok"
        // body (so ok stays false and complete() reverts the toggle), with
        // delElement passed in exactly as ossToggle's real callers do.
        opts.success('failed: in use');
        opts.complete();
        return xhr;
    };
    window.$ = wrappedJQuery;
    jQuery = wrappedJQuery;
    try {
        var failTarget = wrappedJQuery('#toggle-target-fail');
        ossToggle(failTarget, '/x', {}, '#del-target-fail');
        results.failedToggleDelSurvived = document.getElementById('del-target-fail') !== null;

        // The failure path rebinds a click handler for the user's retry. If
        // that rebound handler drops delElement, a later SUCCESSFUL retry
        // toggles the state but leaves the row on the page forever -- the
        // mirror-image defect of the one fixed above. Retry through the real
        // rebound handler (not another direct ossToggle call) with a
        // succeeding request, and require the row to be gone.
        wrappedJQuery.ajax = function (opts) {
            opts.success('ok');
            opts.complete();
            return xhr;
        };
        failTarget.trigger('click');
        results.retriedToggleDelRemoved = document.getElementById('del-target-fail') === null;
    } catch (e) {
        failures.push('ossToggle with failed request threw: ' + e);
    } finally {
        wrappedJQuery.ajax = realAjax2;
        jQuery.fn.hide = realHide;
        window.$ = realJQuery;
        jQuery = realJQuery;
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
    if (results.undefinedCallSeen) failures.push('ossToggle called $(undefined) even though delElement was omitted -- the guard is not gating anything');
    if (results.failedToggleDelSurvived !== true) failures.push('ossToggle removed delElement even though the request failed -- the row vanished from the page while the server still has it');
    if (results.retriedToggleDelRemoved !== true) failures.push('a successful retry after a failed ossToggle left delElement on the page -- the rebound click handler dropped delElement');
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

rm -rf "$tmp/profile"
"$browser" \
  --headless \
  --disable-gpu \
  --allow-file-access-from-files \
  --user-data-dir="$tmp/profile" \
  --virtual-time-budget=1000 \
  --dump-dom "file://$tmp/regression.html" >"$tmp/rendered.html" 2>"$tmp/chromium.log"

if ! grep -q 'data-test-result="pass"' "$tmp/rendered.html"; then
  failures="$(grep -o 'data-test-failures="[^"]*"' "$tmp/rendered.html" || true)"
  echo "FAIL: source-defect-sweep regression: ${failures:-no browser verdict}" >&2
  exit 1
fi

echo "ok   ossToggle with delElement omitted runs cleanly (VIM-A15.43)"
echo "ok   addPluginTab emits text-danger, not text-error (VIM-A15.44)"
echo "ok   ossAlert surfaces its message when Modal is unavailable (VIM-A15.47)"
echo "ok   ossToggle leaves delElement in place when the request fails (VIM-A15.49)"
echo "ok   a successful retry after a failed ossToggle removes delElement (VIM-A15.49)"
echo "ALL PASSED"
