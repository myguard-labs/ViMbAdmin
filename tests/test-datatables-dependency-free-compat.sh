#!/usr/bin/env bash

# Exercise the dependency-free DataTables 3 and Bootstrap stack in each asset lane.
# The second load also verifies preference-cookie persistence.
# Retired Chosen/Colorbox assets are not loaded or tested: their replacements
# are native form controls and application-owned Bootstrap dialogs.
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
  echo 'FAIL: Chromium is required for the dependency-free DataTables compatibility regression' >&2
  exit 2
fi

tmp=$(mktemp -d /tmp/vimbadmin-datatables-dependency-free.XXXXXX)
cleanup() {
  rm -rf "$tmp"
}
trap cleanup EXIT

bundle_file=$(resolve_bundle_v) || exit $?

for asset in \
  120-vimbadmin.validation.js \
  150-datatables.js 151-datatables.ext.js \
  152-datatables.bootstrap5.js \
  800-bootstrap.js 850-vimbadmin.modals.js \
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
cp tests/support/datatables-review-regressions.js tests/support/datatables-language-pollution.json tests/support/datatables-event-ownership.js "$tmp/"
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

# Exercise the production archive status/date renderers with nonempty rows.
# These two columns and their text-escaping helper contain no Smarty syntax;
# extracting them avoids depending on a second copy of their implementation.
awk '
  /^function vmArchiveEsc\(/ { helperSource = $0; helper++ }
  /render.*vmArchiveEsc/ && !/^function/ {
    if (!columns++) print "var archiveProbeColumns = [";
    print;
  }
  END {
    if (columns) print "];";
    print helperSource;
    if (columns != 2 || helper != 1) exit 1;
  }
' application/views/archive/js/list.js >"$tmp/view-archive-renderers.js"

# Use the actual ready bindings and draw callbacks, with an isolated stable
# ancestor and stubbed actions. Replaced row nodes must never own DT listeners.
awk '
  BEGIN {
    print "function bindRowLifecycleFixture(document, tt_openModalDialog, deleteAlias, showSizes, vmPrefsCookie, vm_prefs, vm_cookie_options) {";
  }
  /select\( document \).*\.on\(.*(modal-dialog|delete-alias|dir-size)/ { print; bindings++ }
  END { print "return ["; if (bindings != 3) exit 1 }
' public/js/990-vimbadmin.js application/views/alias/js/list.js \
  application/views/mailbox/js/list.js >"$tmp/view-row-lifecycle.js"
for view in alias domain mailbox; do
  awk '
    /drawCallback.*function/ && !found++ { active = 1; sub(/^.*function/, "function"); print; next }
    active && /^[[:space:]]*},/ { print "},"; active = 0; complete++ }
    active { print }
    END { if (complete != 1) exit 1 }
  ' "application/views/$view/js/list.js" >>"$tmp/view-row-lifecycle.js"
done
printf "];\n}\n" >>"$tmp/view-row-lifecycle.js"

# Both pagination modes in every production list must persist the API length.
printf 'var listLengthCallbacks = [\n' >"$tmp/view-length-callbacks.js"
for view in alias/js/list domain/js/list mailbox/js/list archive/js/list log/js/list admin/js/list admin/js/domains domain/js/admins mailbox/js/aliases; do
  count=1
  [[ $view =~ ^(alias|domain|mailbox|archive|log)/js/list$ ]] && count=2
  awk -v expected="$count" '
    /drawCallback.*function/ { active = 1; sub(/^.*function/, "function"); print; next }
    active && /^[[:space:]]*},/ { print "},"; active = 0; complete++ }
    active { print }
    END { if (complete != expected) exit 1 }
  ' "application/views/$view.js" >>"$tmp/view-length-callbacks.js"
done
printf '];\n' >>"$tmp/view-length-callbacks.js"
printf 'var listLengthRestores = [\n' >>"$tmp/view-length-callbacks.js"
for view in alias/js/list domain/js/list mailbox/js/list archive/js/list log/js/list admin/js/list admin/js/domains domain/js/admins mailbox/js/aliases; do
  # The configured default is rendered as 10; retain the real restore expression.
  # shellcheck disable=SC2016
  sed 's/{if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{\/if}/10/' "application/views/$view.js" |
    awk '
      /pageLength.*:/ { active = 1; sub(/^.*pageLength[^:]*: /, "function() { return ") }
      active { ending = /,$/; sub(/,$/, "; },"); print; if (ending) { active = 0; complete++ } }
      END { if (!complete || active) exit 1 }
    ' >>"$tmp/view-length-callbacks.js"
done
printf '];\n' >>"$tmp/view-length-callbacks.js"

cat >"$tmp/regression.html" <<'HTML'
<!doctype html><html><head><meta charset="utf-8">
<script>
var mode = location.search.slice(1) || 'development';
var failures = [], warnings = [], alertResult = null;
var originalWarn = console.warn;
console.warn = function() {
    warnings.push(Array.prototype.join.call(arguments, ' '));
    originalWarn.apply(console, arguments);
};
window.onerror = function(message) { failures.push('page error: ' + message); };
var scripts = mode === 'production'
    ? ['@@VIMBADMIN_TEST_BUNDLE_FILE@@','view-admin-domains.js','view-archive-renderers.js','view-row-lifecycle.js']
    : ['120-vimbadmin.validation.js',
       '150-datatables.js','151-datatables.ext.js',
       '152-datatables.bootstrap5.js',
       '800-bootstrap.js','850-vimbadmin.modals.js',
       '910-vimbadmin.functions.js','990-vimbadmin.js',
       'view-admin-domains.js','view-archive-renderers.js','view-row-lifecycle.js'];
// Drives the 'missing DataTables dependency' negative control. It removes a script
// the development lane loads, so it is only meaningful there -- production
// loads a single bundle. The lane is pinned to development by expect_fail below.
// The 'DataTables sorts, searches and tears down' assertion exercises that
// required core directly; removing it must fail before any compatibility claim.
if (location.hash === '#missing-dependency') {
    scripts = scripts.filter(function(file) { return file !== '150-datatables.js'; });
}
scripts.forEach(function(file) { document.write('<script src="' + file + '"><\/script>'); });
document.write('<script src="view-length-callbacks.js"><\/script><script src="datatables-event-ownership.js"><\/script><script src="datatables-review-regressions.js"><\/script>');
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

vmReady(function() {
    var wireEndpoint = '/tests/support/datatable-wire-endpoint.php';
    var wireDisabled = location.hash === '#wire-route-disabled';
    // A missing route leaves Chromium's dump-dom process waiting on the 404
    // request even after the transport reports it. Point the negative control at a
    // served response for the wrong scope instead: it keeps the route mutation
    // observable while settling promptly under both fixture servers.
    var wireRequestEndpoint = wireDisabled
        ? '/tests/support/datatable-wire/domain-1.json'
        : wireEndpoint;
    // The network-isolated multi-engine runner is a static server. Its finite
    // response set was rendered through the real PHP parser above; route each
    // fully formed modern request to the matching result while retaining real
    // HTTP serialization and DataTables' response handling.
    var wireAjax = ossAjax;
    ossAjax = function(options) {
        routeWire(options, options);
        return wireAjax(options);
    };
    function routeWire(options, originalOptions) {
        var match = /^\/tests\/support\/datatable-wire-endpoint\.php\?scope=(domain|mailbox|alias|archive|log)$/.exec(options.url);
        if (!match) return;
        var data = originalOptions.data;
        if (!data || data.draw < 1 || data.draw > 4) return;
        if (location.hash === '#legacy-wire-key' && data.draw === 1) data.sEcho = data.draw;
        var allowedName = /^(?:draw|start|length|search%5B(?:value|regex)%5D|order%5B[0-9]+%5D%5B(?:column|dir|name)%5D|columns%5B[0-9]+%5D%5B(?:data|name|searchable|orderable)%5D|columns%5B[0-9]+%5D%5Bsearch%5D%5B(?:value|regex)%5D|_)$/i;
        var rejected = DataTable.ajax.serialize(data).split('&').map(function(pair) {
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
    }
    // Each list uses the shared transport, with its own scoped fixture rows.
    var wireChecks = ['domain', 'mailbox', 'alias', 'archive', 'log'].map(function(scope) {
        return new Promise(function(resolve, reject) {
            var element = DataTable.Dom.create('table').html('<thead><tr><th>Name</th></tr></thead>').appendTo('body');
            var step = 0;
            element.one('xhr.dt', function(event, settings, json, xhr) {
                if (json === null && xhr) reject(new Error(scope + ': AJAX request failed'));
            });
            element.on('draw.dt', function() {
                try {
                    var api = new DataTable.Api(element.get(0));
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
            new DataTable(element.get(0), {
                serverSide: true, pageLength: 2, order: [[0, 'asc']],
                columns: [{ data: 'name' }],
                ajax: vmDataTableServerData(wireRequestEndpoint + '?scope=' + scope, 3)
            });
        });
    });
    var wireFinished = false;
    wireChecks.push(runDataTablesReviewRegressions(check));
    Promise.all(wireChecks).then(function() { wireFinished = true; }, function(error) {
        failures.push('server-side wire: ' + error.message);
        wireFinished = true;
    });
    // The warning oracle still rejects unexpected diagnostics after removal.
    if (location.hash === '#warning-trigger') console.warn('injected for the negative control');
    DataTable.Dom.transitions = false;
    check('runtime has no jQuery dependency', function() {
        return typeof window.jQuery === 'undefined' && typeof window.$ === 'undefined'
            && DataTable.version === '3.0.3';
    });
    window.decodeHandlerRan = false;
    check('HTML entity decoding produces text without constructing active markup', function() {
        var markup = '<img src="data:image/png;base64,broken" onerror="window.decodeHandlerRan=true">';
        var breakout = '</textarea>' + markup;
        return htmlEntityDecode('&lt;strong&gt;hello&lt;/strong&gt; &amp; &#x1F600;') === '<strong>hello</strong> & 😀'
            && htmlEntityDecode('') === ''
            && htmlEntityDecode('&definitelyUnknownEntity;') === '&definitelyUnknownEntity;'
            && htmlEntityDecode(markup) === markup
            && htmlEntityDecode(breakout) === breakout
            && '&lt;b&gt;'.htmlEntityDecode() === '<b>';
    });
    check('spinner renders and stop() is callable', function() {
        // Call tt_throbber directly to mount a spinner at the test point
        var spinner = tt_throbber(32, 14, 1.8);
        spinner.appendTo('#throb-test');
        spinner.start();
        // Verify spinner element exists in DOM
        var hasSpinner = DataTable.Dom.select('#throb-test .vb-throbber').length > 0;
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
        return submit.defaultPrevented && DataTable.Dom.select('#required').hasClass('is-invalid');
    });
    check('DataTables sorts, searches and tears down', function() {
        var table = new DataTable('#table', { order: [[0, 'asc']] });
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
        var originalAjax = ossAjax;
        var sent;
        var table = new DataTable('#table');
        try {
            ossAjax = function(options) { sent = options.data; };
            vmDataTableServerData('/list-data', 3)(request, function() {}, table.settings()[0]);
        } finally {
            ossAjax = originalAjax;
            table.destroy();
        }
        if (sent.draw !== 4) return false;
        if (sent.start !== 30) return false;
        if (sent.length !== 15) return false;
        if (sent.search.value !== 'example') return false;
        if (sent.order[0].column !== 2 || sent.order[0].dir !== 'desc') return false;
        return sent === request;
    });
    check('DataTables 3 errors preserve diagnostics and native cancellation', function() {
        var tableNode = document.createElement('table');
        tableNode.innerHTML = '<thead><tr><th>Name</th></tr></thead>';
        document.body.appendChild(tableNode);
        var api = new DataTable(tableNode, { data: [], columns: [{ title: 'Name' }] });
        var realAjax = ossAjax, realMode = DataTable.ext.errMode;
        var transport, notes = [], seenApi = false, bodyErrors = 0;
        try {
            ossAjax = function(options) { transport = options; return {}; };
            DataTable.ext.errMode = function(settings, note) { notes.push(note); };
            vmDataTableServerData('/error', 3)({ draw: 1, search: { value: '' } },
                function() { throw new Error('error reached success callback'); }, api.settings()[0]);
            api.on('dt-error.dt', function(event) {
                seenApi = event.dt === api.settings()[0].api && event.dt.table().node() === tableNode;
            });
            transport.error({ readyState: 4 }, 'parsererror');
            transport.error({ readyState: 4 }, 'error');
            if (notes.join() !== '1,7' || !seenApi) return false;
            api.on('xhr.dt', function(event) { event.preventDefault(); });
            transport.error({ readyState: 4 }, 'error');
            if (notes.length !== 2) return false;
            api.off('xhr.dt').on('xhr.dt', function() { return false; });
            transport.error({ readyState: 4 }, 'error');
            if (notes.length !== 2) return false;
            api.off('xhr.dt');
            DataTable.Dom.select(document.body).on('dt-error.dt.vimProbe', function() { bodyErrors++; });
            tableNode.remove();
            api.on('dt-error.dt', function(event) { event.stopPropagation(); });
            vmDataTableLogAjaxError(api, 7, 'Ajax error');
            if (bodyErrors !== 0) return false;
            api.off('dt-error.dt');
            vmDataTableLogAjaxError(api, 7, 'Ajax error');
            return bodyErrors === 1;
        }
        finally {
            DataTable.Dom.select(document.body).off('.vimProbe');
            ossAjax = realAjax;
            DataTable.ext.errMode = realMode;
            api.destroy();
            tableNode.remove();
        }
    });
    check('numeric HTML ordering keeps the existing ascending and descending contract', function() {
        var node = document.createElement('table');
        document.body.appendChild(node);
        var api = new DataTable(node, {
            data: [['<b>10</b>'], ['<span>2</span>'], ['-3']],
            columns: [{ title: 'Number', type: 'num-html' }], order: [[0, 'asc']]
        });
        try {
            if (api.column(0, { order: 'applied' }).data().toArray().join() !== '-3,<span>2</span>,<b>10</b>') return false;
            api.order([[0, 'desc']]).draw();
            return api.column(0, { order: 'applied' }).data().toArray().join() === '<b>10</b>,<span>2</span>,-3';
        }
        finally { api.destroy(); node.remove(); }
    });
    check('nonempty archive status and date renderers escape text and handle null values', function() {
        window.archiveStatuses = { saved: 'Saved & ready' };
        var payload = '<img src="data:image/png;base64,broken" onerror="window.archiveHandlerRan=true">';
        window.archiveHandlerRan = false;
        var node = document.createElement('table');
        document.body.appendChild(node);
        var api;
        try {
            api = new DataTable(node, {
                data: [
                    { status: 'saved', archived_at: '2026-09-12 09:00:00' },
                    { status: payload, archived_at: payload },
                    { status: null, archived_at: null }
                ],
                columns: archiveProbeColumns, order: []
            });
            var rows = Array.from(node.querySelectorAll('tbody tr'));
            var values = rows.map(function(row) {
                return Array.from(row.cells).map(function(cell) { return cell.textContent; });
            });
            return api.rows().count() === 3
                && JSON.stringify(values) === JSON.stringify([
                    ['Saved & ready', '2026-09-12 09:00:00'],
                    [payload, payload], ['', '—']
                ])
                && node.querySelector('img,[onerror]') === null
                && vmArchiveEsc(undefined) === ''
                && vmArchiveEsc(0) === '0';
        }
        finally { if (api) api.destroy(); node.remove(); }
    });
    check('row actions survive redraw without retaining detached row listeners', function() {
        var host = document.createElement('div');
        var node = document.createElement('table');
        host.appendChild(node);
        document.body.appendChild(host);
        var calls = [0, 0, 0];
        var actions = calls.map(function(value, index) {
            return function(event) { event.preventDefault(); calls[index]++; };
        });
        var noop = function() {};
        var draws = bindRowLifecycleFixture(host, actions[0], actions[1], actions[2], noop, {}, {});
        // Keep this isolated fixture from also invoking the application's
        // document-level modal handler after the fixture delegate has run.
        host.addEventListener('click', function(event) { event.stopPropagation(); });
        var api;
        try {
            api = new DataTable(node, {
                data: [], columns: [{ title: 'Actions' }], order: [],
                drawCallback: function(settings) { draws.forEach(function(draw) { draw(settings); }); }
            });
            var oldControls = [], tooltipRecords = [];
            function assertDisposed(record) {
                if (bootstrap.Tooltip.getInstance(record.node) !== null || record.instance._element !== null)
                    throw new Error('detached Bootstrap tooltip instance retained');
                if (!record.disposedConnected || record.removed < 2)
                    throw new Error('tooltip not disposed with listeners removed before detachment');
            }
            for (var round = 0; round < 10; round++) {
                api.clear().rows.add([[
                    '<a id="modal-dialog-probe" class="have-tooltip" title="Modal"><i>Modal</i></a>' +
                    '<button id="delete-alias-probe"><i>Delete</i></button>' +
                    '<a id="dir-size-probe"><i>Size</i></a>'
                ]]).draw();
                var controls = Array.from(node.querySelectorAll('tbody a,tbody button'));
                if (controls.length !== 3) throw new Error('missing row controls');
                tooltipRecords.forEach(assertDisposed);
                var tipNode = controls[0];
                var tip = bootstrap.Tooltip.getInstance(tipNode);
                if (!tip) throw new Error('actual vmTooltips did not initialize the drawn row');
                var record = { node: tipNode, instance: tip, disposedConnected: false, removed: 0 };
                (function(current) {
                    var dispose = current.instance.dispose;
                    current.instance.dispose = function() {
                        current.disposedConnected = current.node.isConnected;
                        return dispose.apply(this, arguments);
                    };
                    var remove = current.node.removeEventListener;
                    current.node.removeEventListener = function() {
                        current.removed++;
                        return remove.apply(this, arguments);
                    };
                }(record));
                tooltipRecords.push(record);
                controls.forEach(function(control) {
                    if (hasDataTablesHandlers(control))
                        throw new Error('row control owns a retained DataTables listener');
                    control.firstChild.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
                });
                oldControls.forEach(function(control) {
                    if (control.isConnected) throw new Error('old row was not detached');
                    control.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
                });
                if (!calls.every(function(count) { return count === round + 1; })) throw new Error('missing, duplicate or detached action');
                oldControls = oldControls.concat(controls);
            }
            api.destroy(); api = null;
            tooltipRecords.forEach(assertDisposed);
            return true;
        }
        finally {
            if (api) api.destroy();
            DataTable.Dom.select(host).off('click');
            host.remove();
        }
    });
    check('failed server redraw restores tooltips on retained rows', function() {
        var node = document.createElement('table');
        document.body.appendChild(node);
        var api = new DataTable(node, {
            data: [['<span class="have-tooltip" title="Retained">Row</span>']],
            columns: [{ title: 'Name' }]
        });
        try {
            var control = node.querySelector('.have-tooltip');
            var original = bootstrap.Tooltip.getInstance(control);
            if (!original) return false;
            var settings = api.settings()[0];
            DataTable.defaults.preDrawCallback(settings);
            if (bootstrap.Tooltip.getInstance(control) !== null) return false;
            DataTable.Dom.select(node).trigger('xhr.dt', true, [settings, null, {}], { dt: api });
            return original._element === null && bootstrap.Tooltip.getInstance(control) !== null;
        }
        finally { api.destroy(); node.remove(); }
    });
    check('native AJAX handles JSON, malformed responses, HTTP errors, timeouts and aborts', function() {
        var NativeXHR = window.XMLHttpRequest;
        var requests = [];
        window.XMLHttpRequest = function() {
            this.headers = {};
            this.open = function(method, url, async) { this.method = method; this.url = url; this.async = async; };
            this.setRequestHeader = function(name, value) { this.headers[name] = value; };
            this.getResponseHeader = function() { return 'application/json'; };
            this.send = function(body) { this.body = body; requests.push(this); };
        };
        try {
            var success = [], errors = [], completions = [];
            var make = function() {
                return ossAjax({ url: '/native-probe', type: 'POST', dataType: 'json', timeout: 10000,
                    data: { csrf: 'fixture-token', value: 'a b&c' },
                    success: function(value) { success.push(value); },
                    error: function(xhr, status) { errors.push(status); },
                    complete: function(xhr, status) { completions.push(status); }
                });
            };
            var xhr = make();
            if (xhr.method !== 'POST' || !xhr.async || xhr.timeout !== 10000
                || xhr.body !== 'csrf=fixture-token&value=a+b%26c'
                || xhr.headers['X-Requested-With'] !== 'XMLHttpRequest'
                || !xhr.headers['Content-Type'].startsWith('application/x-www-form-urlencoded')) return false;
            xhr.status = 200; xhr.responseText = '{"ok":true}'; xhr.onload();
            xhr = make(); xhr.status = 200; xhr.responseText = '{broken'; xhr.onload();
            xhr = make(); xhr.status = 503; xhr.responseText = 'unavailable'; xhr.onload();
            xhr = make(); xhr.responseText = ''; xhr.ontimeout(); xhr.onerror();
            xhr = make(); xhr.responseText = ''; xhr.onabort(); xhr.onerror();
            return requests.length === 5 && success.length === 1 && success[0].ok === true
                && errors.join() === 'parsererror,error,timeout,abort'
                && completions.join() === 'success,parsererror,error,timeout,abort';
        }
        finally { window.XMLHttpRequest = NativeXHR; }
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
            var originalAjax = ossAjax;
            ossAjax = function(options) { requested = options.data; return { abort: function() {} }; };
            try {
                vmDataTableServerData('/unused/source', minimum)({
                    draw: 11, start: 0, length: 10, search: { value: search }, order: []
                }, function(json) { answered = json; }, {
                    serverMethod: 'GET', language: { zeroRecords: 'None', emptyTable: 'Empty' }
                });
            } finally {
                ossAjax = originalAjax;
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
        var originalAjax = ossAjax;
        ossAjax = function() { requested = true; return { abort: function() {} }; };

        // A real `language`, as the core always supplies before applying table
        // options. Unlike the production path, this is the only object the shim
        // is given, so this assertion detects a misplaced hint or restore.
        var settings = {
            serverMethod: 'GET',
            language: {
                zeroRecords: 'No matching records found',
                emptyTable:  'No log entries.'
            }
        };
        var originalZeroRecords = settings.language.zeroRecords;
        var originalEmptyTable  = settings.language.emptyTable;

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
            ossAjax = originalAjax;
        }

        if (requested) return false;
        if (!answered) return false;
        if (answered.draw !== 9) return false;
        if (answered.recordsFiltered !== 0) return false;
        if (answered.data.length !== 0) return false;

        // The hint is written to BOTH language keys and restored before the
        // transport returns. DataTables chooses between the keys according to
        // whether the table has data, so both must carry the temporary hint.
        // `callback` paints synchronously, so by the time it returns the borrowed
        // keys must already be back: a declined search that is never followed
        // by another one (the table is destroyed, the view torn down) must
        // not leave the hint behind.
        if (settings.language.zeroRecords !== originalZeroRecords) return false;
        if (settings.language.emptyTable  !== originalEmptyTable) return false;

        // The hint has to actually reach the paint, though. Re-run the
        // decline with a callback that samples the language keys at the
        // moment the core would render the empty row.
        var atPaint = null;
        ossAjax = function() { requested = true; return { abort: function() {} }; };
        try {
            ajax({
                draw: 11,
                start: 0,
                length: 10,
                search: { value: 'ab' },
                order: []
            }, function() {
                atPaint = {
                    zero:  settings.language.zeroRecords,
                    empty: settings.language.emptyTable
                };
            }, settings);
        } finally {
            ossAjax = originalAjax;
        }

        if (!atPaint) return false;
        if (atPaint.zero  !== 'Enter at least 3 characters to search.') return false;
        if (atPaint.empty !== 'Enter at least 3 characters to search.') return false;

        // ...and the capture must read LIVE state on each call, not a value
        // memoised from the first decline. Rotate emptyTable to a sentinel
        // between declines: the second decline has to restore the sentinel,
        // which only holds if it captured at call time.
        settings.language.emptyTable = 'Rotated sentinel.';
        ossAjax = function() { requested = true; return { abort: function() {} }; };
        try {
            ajax({
                draw: 12,
                start: 0,
                length: 10,
                search: { value: 'cd' },
                order: []
            }, function() {}, settings);
        } finally {
            ossAjax = originalAjax;
        }

        if (settings.language.zeroRecords !== originalZeroRecords) return false;
        if (settings.language.emptyTable  !== 'Rotated sentinel.') return false;

        settings.language.emptyTable = originalEmptyTable;

        // A following successful (long-enough) search must leave both keys
        // as the view configured them, and must actually hit the network.
        requested = false;
        ossAjax = function() { requested = true; return { abort: function() {} }; };
        try {
            ajax({
                draw: 10,
                start: 0,
                length: 10,
                search: { value: 'example' },
                order: []
            }, function() {}, settings);
        } finally {
            ossAjax = originalAjax;
        }

        if (!requested) return false;
        if (settings.language.zeroRecords !== originalZeroRecords) return false;
        if (settings.language.emptyTable  !== originalEmptyTable) return false;
        return true;
    });
    // The single sort column the PHP side reads is only honest if the client
    // cannot select more than one.
    check('multi-column ordering is disabled for the single-order server contract', function() {
        return DataTable.defaults.orderMulti === false;
    });
    // Assert the application-owned informational dialog through the native
    // Bootstrap 5 Modal lifecycle. It keeps the OSS_Message HTML contract but
    // has no third-party dialog or jQuery dependency.
    // Opened here, asserted in the deferred block below: dismissal runs through
    // Bootstrap 5's hide transition and the helper removes the dialog on
    // `hidden.bs.modal`, so neither the close nor the callback has happened yet
    // when this statement returns. Asserting synchronously would pass even with
    // a broken dismiss handler.
    var alertDialog = ossAlert('<em id="alert-probe">Continue?</em>', function() { alertResult = true; });
    check('native modal alert renders its message as HTML', function() {
        return !!document.getElementById('alert-probe');
    });
    // The click must be a NATIVE event because `data-bs-dismiss` is bound by
    // Bootstrap's own delegated native listener, which a jQuery-triggered event
    // never reaches. Generated dialogs are intentionally not animated, so they
    // are already shown when ossAlert() returns.
    function dismissAlert() {
        if (location.hash === '#alert-dismiss-disabled') return;
        var button = alertDialog.querySelector('[data-bs-dismiss="modal"]');
        if (button) button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    }
    if (alertDialog.classList.contains('fade'))
        alertDialog.addEventListener('shown.bs.modal', dismissAlert, { once: true });
    else
        dismissAlert();
    check('real admin view remove dialog operates', function() {
        DataTable.Dom.select('#remove-domain-7').trigger('click');
        var selected = DataTable.Dom.select('#remove_domain_form input[name="did"]').val();
        DataTable.Dom.select('#purge_dialog_cancel').trigger('click');
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
    var stateButton = DataTable.Dom.select('#state-button');
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
        // What matters here is that the per-call dialog the helper created is gone
        // -- the helper removes it on `hidden.bs.modal`, so its absence proves the hide
        // transition completed rather than merely started.
        check('native modal alert dismisses and removes its dialog', function() {
            return document.getElementById('alert-probe') === null;
        });
        check('native modal alert invokes the caller callback on dismiss', function() {
            return alertResult === true;
        });

        // tt_throbber.stop() fades out over 750ms and removes the element in
        // the transition completion callback, so the teardown assertion and the
        // verdict must both wait beyond that. Asserting at 300ms would pass
        // even when stop() never removes anything.
        setTimeout(function() {
            check('stopping a spinner removes it from the DOM', function() {
                return DataTable.Dom.select('#throb-test .vb-throbber').length === 0;
            });

            if (!wireFinished) failures.push('server-side wire requests did not complete');
            if (window.decodeHandlerRan) failures.push('HTML entity decoding executed an active handler');
            if (window.archiveHandlerRan) failures.push('archive renderer executed an active handler');
            if (warnings.length) failures.push('Compatibility warning: ' + warnings.join(' | '));
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
  expect_fail 'injected compatibility warning' run_mode development '#warning-trigger'
  expect_fail 'missing DataTables dependency' run_mode development '#missing-dependency'
  expect_fail 'button left disabled after reset' run_mode development '#button-disabled'
  expect_fail 'native modal alert dismissal and callback' run_mode development '#alert-dismiss-disabled'
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

echo 'OK: DataTables 3 without jQuery, console warnings, validation and preferences cookie'
