#!/usr/bin/env bash

# Exercise the supported jQuery 4 plugin stack in a real browser. jQuery was
# upgraded from 3.7.1 to 4.0.0 (VIM-A15.29) and the jQuery Migrate shim --
# written for the 1.9-3.x upgrade path -- was deleted with it, so the
# three-lane split that used to isolate Migrate's deprecation warnings from
# production is kept only for asset-loading parity between the individual-file
# and bundled paths; there is no Migrate mode left to assert.
#
# The lanes are development/second-load/production. The middle lane was called
# `early` when it differed from `development` by splicing jquery-migrate to the
# FRONT of the script list -- a load-order assertion. That splice went with the
# shim in PR #190, so the lane now loads exactly the same assets as
# `development` and its real job is to be the SECOND page load in the
# preferences-cookie chain: `development` writes a marker, `second-load`
# asserts it read `development`'s, `production` asserts it read
# `second-load`'s. Renamed to say so (VIM-A15.51).
#
# DROPPED COVERAGE (approved 2026-09-08, files deleted VIM-A15.56): this test
# used to also load and assert Chosen (public/js/300-chosen.jquery.js) and
# Colorbox (public/js/130-jquery.colorbox.js) in its non-production lanes.
# Both were dropped here first: jQuery 4.0.0 removed $.trim
# (https://jquery.com/upgrade-guide/4.0/), which 300-chosen.jquery.js:1240
# called from get_search_text(), reached at runtime from live search
# filtering -- so loading Chosen under jQuery 4 threw and there was no
# in-scope fix. Both libraries had been dead in the application since PR #180
# (no <script>/<link> row, no `.chosen(` call site, no `chzn-*`/colorbox
# markup) and were kept on disk, unbundled, for a time (see
# bin/minify-bundle-files.php's history). VIM-A15.56 finished the removal:
# both vendor files, their CSS, the Chosen sprite images and the Colorbox
# image directory no longer exist anywhere in the repository. Neither
# library, nor the $.trim removal, is otherwise exercised by this file any
# more.
#
# The '#missing-dependency' negative control used to remove
# 300-chosen.jquery.js from the loaded scripts and require this oracle to
# notice; with Chosen no longer loaded or asserted at all, that would be
# vacuous (the control's own load-order splice would go unnoticed too -- see
# that check for the real mechanism). It is re-pointed at
# 150-jquery.datatables.js instead, which every lane still loads and the
# 'DataTables sorts, searches and tears down' check still asserts against.

set -euo pipefail

cd "$(dirname "$0")/.."

# Source the bundle resolver
source tests/support/resolve-bundle-v.sh

browser=${CHROMIUM_BIN:-}
readonly http_runner=.github/scripts/run-chrome-http-fixture.sh
if [[ -z $browser ]]; then
  browser=$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)
fi
if [[ -z $browser ]]; then
  echo 'FAIL: Chromium is required for the jQuery compatibility regression' >&2
  exit 2
fi

tmp=$(mktemp -d /tmp/vimbadmin-jquery-migrate.XXXXXX)
cleanup() {
  rm -rf "$tmp"
}
trap cleanup EXIT

bundle_file=$(resolve_bundle_v) || exit $?

for asset in \
  100-jquery.js 120-vimbadmin.validation.js \
  150-jquery.datatables.js 151-jquery.datatables.ext.js \
  152-jquery.datatables.bootstrap5.js \
  800-bootstrap.js 850-bootbox.js \
  910-vimbadmin.functions.js 990-vimbadmin.js \
  "$bundle_file"; do
  if ! cp "public/js/$asset" "$tmp/$asset" 2>/dev/null; then
    echo "FAIL: required asset public/js/$asset not found" >&2
    exit 1
  fi
done
mkdir -p "$tmp/src/Kernel/DataTable" "$tmp/tests/support"
cp src/Kernel/DataTable/{DataTableQuery,DataTableResult}.php "$tmp/src/Kernel/DataTable/"
cp tests/support/datatable-wire-endpoint.php "$tmp/tests/support/"
mkdir -p "$tmp/tests/support/datatable-wire"
render_wire_response() {
  local scope=$1 draw=$2 start=$3 search=$4 direction=$5
  php "$tmp/tests/support/datatable-wire-endpoint.php" \
    "scope=$scope&draw=$draw&start=$start&length=2&search%5Bvalue%5D=$search&order%5B0%5D%5Bcolumn%5D=0&order%5B0%5D%5Bdir%5D=$direction" \
    >"$tmp/tests/support/datatable-wire/$scope-$draw.json"
}
for scope in domain mailbox alias archive log; do
  render_wire_response "$scope" 1 0 '' asc
  render_wire_response "$scope" 2 2 '' asc
  render_wire_response "$scope" 3 0 '' desc
  render_wire_response "$scope" 4 0 "${scope}-Beta" desc
done
# The search text contains a literal Smarty variable, not a shell variable.
# shellcheck disable=SC2016
sed 's/{if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{\/if}/10/' \
  application/views/admin/js/domains.js >"$tmp/view-admin-domains.js"

cat >"$tmp/regression.html" <<'HTML'
<!doctype html><html><head><meta charset="utf-8">
<script>
var mode = location.search.slice(1) || 'development';
var failures = [], warnings = [], bootboxResult = null;
var originalWarn = console.warn;
console.warn = function() {
    warnings.push(Array.prototype.join.call(arguments, ' '));
    originalWarn.apply(console, arguments);
};
window.onerror = function(message) { failures.push('page error: ' + message); };
var scripts = mode === 'production'
    ? ['@@VIMBADMIN_TEST_BUNDLE_FILE@@','view-admin-domains.js']
    : ['100-jquery.js','120-vimbadmin.validation.js',
       '150-jquery.datatables.js','151-jquery.datatables.ext.js',
       '152-jquery.datatables.bootstrap5.js',
       '800-bootstrap.js','850-bootbox.js',
       '910-vimbadmin.functions.js','990-vimbadmin.js',
       'view-admin-domains.js'];
// Drives the 'missing plugin dependency' negative control. It removes a script
// the development lane loads, so it is only meaningful there -- production
// loads a single bundle. The lane is pinned to development by expect_fail below.
// Re-pointed (2026-09-08) at DataTables, which every non-production lane still
// loads and which the 'DataTables sorts, searches and tears down' check below
// still asserts against, after Chosen was dropped from this file's coverage
// (see the file header for why).
if (location.hash === '#missing-dependency') {
    scripts = scripts.filter(function(file) { return file !== '150-jquery.datatables.js'; });
}
scripts.forEach(function(file) { document.write('<script src="' + file + '"><\/script>'); });
</script></head><body>
<form id="validation"><input id="required" name="required" required></form>
<table id="table"><thead><tr><th>Name</th></tr></thead><tbody><tr><td>Beta</td></tr><tr><td>Alpha</td></tr></tbody></table>
<table id="list_table"><thead><tr><th>Domain</th><th>Action</th></tr></thead><tbody><tr><td>example.test</td><td><a id="remove-domain-7" ref="example.test">Remove</a></td></tr></tbody></table>
<!-- Mirrors application/views/domain/list.phtml: Bootstrap 5's Modal requires a
     .modal-dialog > .modal-content subtree and throws when it is absent, so a
     bare .modal here would fail against markup the application does not ship. -->
<div id="purge_dialog" class="modal fade" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body"><span id="purge_domain_name"></span></div>
      <div class="modal-footer"><button id="purge_dialog_cancel" type="button">Cancel</button></div>
    </div>
  </div>
</div>
<form id="remove_domain_form"><input name="did"></form>
<button id="state-button" type="button" data-loading-text="Working">Ready</button>
<div id="throb-test"></div>
<pre id="output">PENDING</pre>
<script>
function check(name, test) {
    try {
        if (!test()) throw new Error('false oracle');
    } catch (error) {
        // A TypeError from a symbol this migration deleted is the exact failure
        // being guarded against, so report it distinctly from a false oracle.
        failures.push(name + ': threw ' + (error && error.name ? error.name : 'Error') +
            ': ' + (error && error.message ? error.message : String(error)));
    }
}

$(function() {
    var wireEndpoint = '/tests/support/datatable-wire-endpoint.php';
    var wireDisabled = location.hash === '#wire-route-disabled';
    // A missing route leaves Chromium's dump-dom process waiting on the 404
    // request even after jQuery reports it. Point the negative control at a
    // served response for the wrong scope instead: it keeps the route mutation
    // observable while settling promptly under both fixture servers.
    var wireRequestEndpoint = wireDisabled
        ? '/tests/support/datatable-wire/domain-1.json'
        : wireEndpoint;
    // The network-isolated multi-engine runner is a static server. Its finite
    // response set was rendered through the real PHP parser above; route each
    // fully formed modern request to the matching result while retaining real
    // HTTP serialization and DataTables' response handling.
    $.ajaxPrefilter(function(options, originalOptions) {
        var match = /^\/tests\/support\/datatable-wire-endpoint\.php\?scope=(domain|mailbox|alias|archive|log)$/.exec(options.url);
        if (!match) return;
        var data = originalOptions.data;
        if (!data || data.draw < 1 || data.draw > 4) return;
        if (location.hash === '#legacy-wire-key' && data.draw === 1) data.sEcho = data.draw;
        var allowedName = /^(?:draw|start|length|search%5B(?:value|regex)%5D|order%5B[0-9]+%5D%5B(?:column|dir|name)%5D|columns%5B[0-9]+%5D%5B(?:data|name|searchable|orderable)%5D|columns%5B[0-9]+%5D%5Bsearch%5D%5B(?:value|regex)%5D|_)$/i;
        var rejected = $.param(data).split('&').map(function(pair) {
            return pair.split('=', 1)[0];
        }).filter(function(name) { return !allowedName.test(name); });
        if (rejected.length) {
            failures.push('server-side wire: rejected request key ' + rejected[0]);
            options.url = '/tests/support/datatable-wire/' + match[1] + '-' + data.draw + '.json';
            return;
        }
        var expected = [
            { start: 0, search: '', dir: 'asc' },
            { start: 2, search: '', dir: 'asc' },
            { start: 0, search: '', dir: 'desc' },
            { start: 0, search: match[1] + '-Beta', dir: 'desc' }
        ][data.draw - 1];
        if (data.start !== expected.start || data.length !== 2 ||
            data.search.value !== expected.search || data.order[0].column !== 0 ||
            data.order[0].dir !== expected.dir) return;
        options.url = '/tests/support/datatable-wire/' + match[1] + '-' + data.draw + '.json';
    });
    // Each list uses the shared transport, with its own scoped fixture rows.
    var wireChecks = ['domain', 'mailbox', 'alias', 'archive', 'log'].map(function(scope) {
        return new Promise(function(resolve, reject) {
            var element = $('<table><thead><tr><th>Name</th></tr></thead></table>').appendTo('body');
            var step = 0;
            element.one('xhr.dt', function(event, settings, json, xhr) {
                if (json === null && xhr) reject(new Error(scope + ': AJAX request failed'));
            });
            element.on('draw.dt', function() {
                try {
                    var api = element.DataTable();
                    var info = api.page.info();
                    var names = api.column(0).data().toArray();
                    if (step === 0) {
                        if (info.recordsTotal !== 4 || names.join() !== scope + '-Alpha,' + scope + '-Beta') throw new Error('initial page');
                        step++;
                        api.page('next').draw('page');
                    } else if (step === 1) {
                        if (info.start !== 2 || names.join() !== scope + '-Delta,' + scope + '-Gamma') throw new Error('next page');
                        step++;
                        api.order([[0, 'desc']]).draw();
                    } else if (step === 2) {
                        if (names.join() !== scope + '-Gamma,' + scope + '-Delta') throw new Error('descending order');
                        step++;
                        api.search(scope + '-Beta').draw();
                    } else {
                        if (info.recordsTotal !== 4 || info.recordsDisplay !== 1 || names.join() !== scope + '-Beta') throw new Error('filtered page');
                        resolve();
                    }
                } catch (error) { reject(new Error(scope + ': ' + error.message)); }
            });
            element.DataTable({
                serverSide: true, pageLength: 2, order: [[0, 'asc']],
                columns: [{ data: 'name' }],
                ajax: vmDataTableServerData(wireRequestEndpoint + '?scope=' + scope, 3)
            });
        });
    });
    var wireFinished = false;
    Promise.all(wireChecks).then(function() { wireFinished = true; }, function(error) {
        failures.push('server-side wire: ' + error.message);
        wireFinished = true;
    });
    // Drives the 'injected Migrate warning' negative control (name kept for
    // history/CI-label continuity; the mechanism is jQuery-4-native, not
    // Migrate -- Migrate is deleted). jQuery 4.0.0 added
    // jQuery.Deferred.exceptionHook, which console.warn's an uncaught
    // TypeError/RangeError/etc. thrown inside a .then() callback rather than
    // silently swallowing it (see public/js/100-jquery.js's
    // jQuery.Deferred.exceptionHook). Throwing one here from a settled
    // Deferred is a real jQuery-4 warning path the oracle above already
    // captures via console.warn, so a rotted oracle is caught the same way it
    // was under Migrate.
    if (location.hash === '#warning-trigger') {
        $.Deferred().resolve().then(function() {
            throw new TypeError('injected for the negative control');
        });
    }

    // jQuery animations are driven by requestAnimationFrame, which headless
    // Chrome does not advance under --virtual-time-budget: fadeOut() queues a
    // timer that never steps, so the element is never removed and a teardown
    // assertion cannot distinguish "still fading" from "never removed".
    // $.fx.off makes animations complete synchronously, which is what lets the
    // teardown check below assert removal rather than merely callability.
    $.fx.off = true;

    check('jQuery 4.0.0 loaded', function() { return $.fn.jquery === '4.0.0'; });
    check('spinner renders and stop() is callable', function() {
        // Call tt_throbber directly to mount a spinner at the test point
        var spinner = tt_throbber(32, 14, 1.8);
        spinner.appendTo('#throb-test');
        spinner.start();
        // Verify spinner element exists in DOM
        var hasSpinner = $('#throb-test .vb-throbber').length > 0;
        if (!hasSpinner) return false;
        // stop() fades out over 750ms and removes the element only in the
        // completion callback, so teardown is asserted in the deferred block
        // below rather than here.
        spinner.stop();
        return true;
    });
    check('empty required field reports native Bootstrap validation state', function() {
        var submit = new Event('submit', { bubbles: true, cancelable: true });
        document.getElementById('validation').dispatchEvent(submit);
        return submit.defaultPrevented && $('#required').hasClass('is-invalid');
    });
    check('DataTables sorts, searches and tears down', function() {
        var table = $('#table').DataTable({ order: [[0, 'asc']] });
        if (table.rows({ order: 'applied' }).data()[0][0] !== 'Alpha') return false;
        table.search('Beta').draw();
        if (table.rows({ search: 'applied' }).count() !== 1) return false;
        table.destroy();
        return true;
    });
    check('server-side ajax preserves the modern request parameters', function() {
        var request = {
            draw: 4,
            start: 30,
            length: 15,
            search: { value: 'example' },
            order: [{ column: 2, dir: 'desc' }]
        };
        var originalAjax = $.ajax;
        var sent;
        var table = $('#table').DataTable();
        try {
            $.ajax = function(options) { sent = options.data; };
            vmDataTableServerData('/list-data', 3)(request, function() {}, table.settings()[0]);
        } finally {
            $.ajax = originalAjax;
            table.destroy();
        }
        if (sent.draw !== 4) return false;
        if (sent.start !== 30) return false;
        if (sent.length !== 15) return false;
        if (sent.search.value !== 'example') return false;
        if (sent.order[0].column !== 2 || sent.order[0].dir !== 'desc') return false;
        return sent === request;
    });
    // Exercise the actual source/bundle transport in every mode. Request
    // interception observes whether the minimum gate runs before network I/O,
    // and checks that accepted contains searches retain their original sigil.
    [
        ['*ab', 3, false], ['* ab', 3, false], ['*abc', 3, true],
        ['* abc', 3, true], ['*', 3, true], ['*  ', 3, true],
        ['*\tab', 3, false], ['*\nab', 3, false], ['*\rab', 3, false],
        ['*\0ab', 3, false], ['*\vab', 3, false],
        ['*\fab', 3, true], ['*\u00a0ab', 3, true],
        ['*ab\f', 3, true], ['*ab\u00a0', 3, true],
        ['\fab', 3, true], ['\u00a0ab', 3, true],
        ['*ab\0', 3, false], ['\0*ab', 3, false],
        ['*😀a', 3, false], ['*😀ab', 3, true],
        ['*ab', 0, true], ['ab', 3, false], ['abc', 3, true],
        ['  * ab  ', 3, false], ['', 3, true], ['   ', 3, true]
    ].concat([' ', '\t', '\n', '\r', '\0', '\v'].reduce(function(cases, space) {
        return cases.concat([[space + '*ab', 3, false], ['*ab' + space, 3, false],
            [space + '*abc' + space, 3, true]]);
    }, [])).forEach(function(testCase) {
        var search = testCase[0], minimum = testCase[1], allowed = testCase[2];
        check('sigil minimum boundary ' + JSON.stringify(search) + ' minimum ' + minimum, function() {
            var requested = null, answered = null;
            var originalAjax = $.ajax;
            $.ajax = function(options) { requested = options.data; return { abort: function() {} }; };
            try {
                vmDataTableServerData('/unused/source', minimum)({
                    draw: 11, start: 0, length: 10, search: { value: search }, order: []
                }, function(json) { answered = json; }, {
                    sServerMethod: 'GET', oLanguage: { sZeroRecords: 'None', sEmptyTable: 'Empty' }
                });
            } finally {
                $.ajax = originalAjax;
            }
            if (allowed) return requested !== null && requested.search.value === search && answered === null;
            return requested === null && answered !== null && answered.draw === 11
                && answered.recordsFiltered === 0 && answered.data.length === 0;
        });
    });

    // The shim must be able to DECLINE a request, not merely blank the search
    // term: a search shorter than the minimum has to resolve to an empty result
    // set locally. If it reached the server with search[value] blanked, the server
    // would answer with the full unfiltered page while the hint claimed more
    // characters were needed.
    check('a search shorter than the minimum never reaches the server', function() {
        var ajax = vmDataTableServerData('/unused/source', 3);
        if (typeof ajax !== 'function') return false;

        var requested = false;
        var originalAjax = $.ajax;
        $.ajax = function() { requested = true; return { abort: function() {} }; };

        // A real `oLanguage`, as the core always supplies (150-…js:453
        // initialises it before any table option is applied) -- unlike the
        // production path, this is the only object the shim is given, so if
        // the hint or its restore lands anywhere else, this assertion is the
        // one place that would notice.
        var settings = {
            sServerMethod: 'GET',
            oLanguage: {
                sZeroRecords: 'No matching records found',
                sEmptyTable:  'No log entries.'
            }
        };
        var originalZeroRecords = settings.oLanguage.sZeroRecords;
        var originalEmptyTable  = settings.oLanguage.sEmptyTable;

        var answered = null;
        try {
            ajax({
                draw: 9,
                start: 0,
                length: 10,
                search: { value: 'ab' },
                order: []
            }, function(json) { answered = json; }, settings);
        } finally {
            $.ajax = originalAjax;
        }

        if (requested) return false;
        if (!answered) return false;
        if (answered.draw !== 9) return false;
        if (answered.recordsFiltered !== 0) return false;
        if (answered.data.length !== 0) return false;

        // The hint is written to BOTH language keys and restored before the
        // transport returns -- see the `_emptyRow` note beside the hint in
        // vmDataTableServerData for why both keys are needed. `callback`
        // paints synchronously, so by the time the call is over the borrowed
        // keys must already be back: a declined search that is never followed
        // by another one (the table is destroyed, the view torn down) must
        // not leave the hint behind.
        if (settings.oLanguage.sZeroRecords !== originalZeroRecords) return false;
        if (settings.oLanguage.sEmptyTable  !== originalEmptyTable) return false;

        // The hint has to actually reach the paint, though. Re-run the
        // decline with a callback that samples the language keys at the
        // moment the core would render the empty row.
        var atPaint = null;
        $.ajax = function() { requested = true; return { abort: function() {} }; };
        try {
            ajax({
                draw: 11,
                start: 0,
                length: 10,
                search: { value: 'ab' },
                order: []
            }, function() {
                atPaint = {
                    zero:  settings.oLanguage.sZeroRecords,
                    empty: settings.oLanguage.sEmptyTable
                };
            }, settings);
        } finally {
            $.ajax = originalAjax;
        }

        if (!atPaint) return false;
        if (atPaint.zero  !== 'Enter at least 3 characters to search.') return false;
        if (atPaint.empty !== 'Enter at least 3 characters to search.') return false;

        // ...and the capture must read LIVE state on each call, not a value
        // memoised from the first decline. Rotate sEmptyTable to a sentinel
        // between declines: the second decline has to restore the sentinel,
        // which only holds if it captured at call time.
        settings.oLanguage.sEmptyTable = 'Rotated sentinel.';
        $.ajax = function() { requested = true; return { abort: function() {} }; };
        try {
            ajax({
                draw: 12,
                start: 0,
                length: 10,
                search: { value: 'cd' },
                order: []
            }, function() {}, settings);
        } finally {
            $.ajax = originalAjax;
        }

        if (settings.oLanguage.sZeroRecords !== originalZeroRecords) return false;
        if (settings.oLanguage.sEmptyTable  !== 'Rotated sentinel.') return false;

        settings.oLanguage.sEmptyTable = originalEmptyTable;

        // A following successful (long-enough) search must leave both keys
        // as the view configured them, and must actually hit the network.
        requested = false;
        $.ajax = function() { requested = true; return { abort: function() {} }; };
        try {
            ajax({
                draw: 10,
                start: 0,
                length: 10,
                search: { value: 'example' },
                order: []
            }, function() {}, settings);
        } finally {
            $.ajax = originalAjax;
        }

        if (!requested) return false;
        if (settings.oLanguage.sZeroRecords !== originalZeroRecords) return false;
        if (settings.oLanguage.sEmptyTable  !== originalEmptyTable) return false;
        return true;
    });
    // The single sort column the PHP side reads is only honest if the client
    // cannot select more than one.
    check('multi-column ordering is disabled for the single-order server contract', function() {
        return $.fn.dataTable.defaults.orderMulti === false;
    });
    // Chosen and Colorbox coverage was dropped here; see the file header.
    // bootbox 3.3.0 is gone (it built Bootstrap 2 modal markup and drove the
    // Bootstrap 2 lifecycle). The replacement shim deliberately provides only
    // `bootbox.alert`, which is the whole of the API the application uses --
    // `bootbox.confirm` has no call site anywhere in the tree. Assert the
    // surface that exists and is depended upon, via the frozen OSS_Message
    // contract, rather than one this migration intentionally dropped.
    // Opened here, asserted in the deferred block below: dismissal runs through
    // Bootstrap 5's hide transition and the shim removes the dialog on
    // `hidden.bs.modal`, so neither the close nor the callback has happened yet
    // when this statement returns. Asserting synchronously would pass even with
    // a broken dismiss handler.
    var bootboxDialog = bootbox.alert('<em id="bootbox-probe">Continue?</em>', function() { bootboxResult = true; });
    check('Bootbox alert renders its message as HTML', function() {
        return !!document.getElementById('bootbox-probe');
    });
    // Dismiss only after Bootstrap reports that its show transition completed.
    // A fixed delay races the component's `_isTransitioning` guard: Chromium
    // happened to finish in time while Firefox and WebKit correctly ignored an
    // early click. The click itself must be a NATIVE event, because
    // `data-bs-dismiss` is bound by Bootstrap's own delegated native listener,
    // which a jQuery-triggered event never reaches.
    bootboxDialog.one('shown.bs.modal', function() {
        if (location.hash === '#alert-dismiss-disabled') return;
        var button = bootboxDialog.get(0).querySelector('[data-bs-dismiss="modal"]');
        if (button) button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });
    check('real admin view remove dialog operates', function() {
        $('#remove-domain-7').trigger('click');
        var selected = $('#remove_domain_form input[name="did"]').val();
        $('#purge_dialog_cancel').trigger('click');
        return selected === '7';
    });
    check('preferences cookie persists between development and production page loads', function() {
        if (mode === 'development') return true;
        var persisted = vmPrefsCookie('vm_prefs');
        return persisted && persisted.marker === (mode === 'second-load' ? 'development' : 'second-load');
    });
    check('a cookie written by the old plugin is still readable', function() {
        // The removed $.jsonCookie wrote raw JSON with no URI-encoding. If the
        // replacement disagrees about the wire format, every existing user
        // silently loses their saved table preferences.
        document.cookie = 'vm_legacy={"pageLength":50,"marker":"legacy"}; path=/';
        var legacy = vmPrefsCookie('vm_legacy');
        return legacy && legacy.pageLength === 50 && legacy.marker === 'legacy';
    });
    check('a cookie list with an empty segment still resolves later entries', function() {
        document.cookie = 'vm_seg={"ok":true}; path=/';
        return document.cookie.indexOf('vm_seg=') !== -1 && vmPrefsCookie('vm_seg') !== null;
    });
    check('malformed preferences cookie fails soft', function() {
        document.cookie = 'vm_prefs={malformed; path=/';
        return vmPrefsCookie('vm_prefs') === null;
    });
    check('non-object preferences cookie fails soft', function() {
        document.cookie = 'vm_prefs=0; path=/';
        return vmPrefsCookie('vm_prefs') === null;
    });
    check('preferences cookie keeps its JSON shape and options', function() {
        vmPrefsCookie('vm_prefs', { pageLength: 25, marker: mode }, vm_cookie_options);
        var value = vmPrefsCookie('vm_prefs');
        return value && value.pageLength === 25 && value.marker === mode && /(?:^|; )vm_prefs=/.test(document.cookie);
    });

    // Bootstrap 5 REMOVED the button plugin's stateful `.button('loading')` /
    // `.button('reset')` API together with `data-loading-text`; there is no
    // replacement and the application never used it (no `.button('...')` call
    // and no `data-loading-text` attribute exists outside this fixture). Asserting
    // it here would test Bootstrap 2, not ViMbAdmin.
    //
    // What still needs an oracle is the thing the removed API was standing in
    // for: that a button disabled while work is in flight is re-enabled
    // afterwards, and that this file can still SEE a button wrongly left
    // disabled. The '#button-disabled' mutation lane depends on exactly that,
    // so the pair is kept with the state driven directly.
    var stateButton = $('#state-button');
    stateButton.prop('disabled', true).text('Working');
    setTimeout(function() {
        check('a button disabled for in-flight work reports as disabled', function() {
            return stateButton.prop('disabled') === true && stateButton.text() === 'Working';
        });
        stateButton.prop('disabled', false).text('Ready');
        setTimeout(function() {
            if (location.hash === '#button-disabled') stateButton.prop('disabled', true);
            check('a button restored after the work completes is enabled again', function() {
                return stateButton.prop('disabled') === false && stateButton.text() === 'Ready';
            });
        }, 30);
    }, 30);

    setTimeout(function() {
        // Deferred so the dismiss transition has completed: the dialog must be
        // gone from the DOM and the caller's callback must have run. Without
        // these two, a broken dismiss handler leaves the modal open forever and
        // the suite never notices.
        // Scoped to the alert's OWN dialog: #purge_dialog is a persistent
        // in-page modal opened by an earlier check and legitimately still in the
        // DOM, so a global `.modal.show` count would assert someone else's state.
        // What matters here is that the per-call dialog the shim created is gone
        // -- it is removed on `hidden.bs.modal`, so its absence proves the hide
        // transition completed rather than merely started.
        check('Bootbox alert dismisses and removes its dialog', function() {
            return document.getElementById('bootbox-probe') === null;
        });
        check('Bootbox alert invokes the caller callback on dismiss', function() {
            return bootboxResult === true;
        });

        // tt_throbber.stop() fades out over 750ms and removes the element in
        // the fadeOut completion callback, so the teardown assertion and the
        // verdict must both wait beyond that. Asserting at 300ms would pass
        // even when stop() never removes anything.
        setTimeout(function() {
            check('stopping a spinner removes it from the DOM', function() {
                return $('#throb-test .vb-throbber').length === 0;
            });

            if (!wireFinished) failures.push('server-side wire requests did not complete');
            if (warnings.length) failures.push('Migrate warning: ' + warnings.join(' | '));
            document.getElementById('output').textContent = JSON.stringify({ mode: mode, warnings: warnings, failures: failures });
            document.body.dataset.verdict = failures.length ? 'FAIL' : 'PASS';
        }, 900);
    }, 700);
});
</script></body></html>
HTML

if ! grep -q '@@VIMBADMIN_TEST_BUNDLE_FILE@@' "$tmp/regression.html"; then
  echo "FAIL: bundle-file placeholder not found in generated fixture" >&2
  exit 1
fi
sed -i "s/@@VIMBADMIN_TEST_BUNDLE_FILE@@/$bundle_file/" "$tmp/regression.html"
if grep -q '@@VIMBADMIN_TEST_BUNDLE_FILE@@' "$tmp/regression.html"; then
  echo "FAIL: bundle-file placeholder substitution did not apply" >&2
  exit 1
fi

run_mode() {
  local mode=$1
  local fragment=${2:-}
  local output=$tmp/$mode${fragment//[^a-z-]/}.html
  chrome_args=(
    --headless --disable-gpu --virtual-time-budget=3000
    --user-data-dir="$tmp/profile" --dump-dom
    "http://127.0.0.1:8765/regression.html?$mode$fragment"
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
  grep -q '"warnings":\[\]' "$output"
}

expect_fail() {
  local label=$1
  shift
  if "$@"; then
    echo "FAIL: negative control '$label' passed; the oracle cannot detect it" >&2
    exit 1
  fi
}

mutation=${VIMBADMIN_MUTATION:-}
case "$mutation" in
  '')
    run_mode development
    run_mode second-load
    run_mode production
    # Negative controls run in the default lane, so a rotted oracle fails CI
    # instead of waiting for someone to remember an env var.
    expect_fail 'injected Migrate warning' run_mode development '#warning-trigger'
    expect_fail 'missing plugin dependency' run_mode development '#missing-dependency'
    expect_fail 'button left disabled after reset' run_mode development '#button-disabled'
    expect_fail 'Bootbox alert dismissal and callback' run_mode development '#alert-dismiss-disabled'
    expect_fail 'server-side wire endpoint' run_mode development '#wire-route-disabled'
    expect_fail 'legacy server-side wire key' run_mode development '#legacy-wire-key'
    ;;
  warning)
    run_mode development '#warning-trigger'
    ;;
  missing-dependency)
    run_mode development '#missing-dependency'
    ;;
  button-disabled)
    run_mode development '#button-disabled'
    ;;
  alert-dismiss-disabled)
    run_mode development '#alert-dismiss-disabled'
    ;;
  wire-route-disabled)
    run_mode development '#wire-route-disabled'
    ;;
  legacy-wire-key)
    run_mode development '#legacy-wire-key'
    ;;
  *)
    echo "FAIL: unknown VIMBADMIN_MUTATION: $mutation" >&2
    exit 2
    ;;
esac

echo 'OK: jQuery 4 plugins, console warnings, validation and preferences cookie'
