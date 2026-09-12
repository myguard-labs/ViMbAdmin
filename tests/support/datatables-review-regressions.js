/* global DataTable, ossAjax, ossAjaxErrorHandler, vmDataTableServerData, vm_prefs, vmPrefsCookie, listLengthCallbacks, listLengthRestores, hasDataTablesHandlers */
'use strict';

function runDataTablesReviewRegressions(check) {
    function table() {
        var node = document.createElement('table');
        document.body.appendChild(node);
        return node;
    }
    function require(value, message) {
        if (!value) throw new Error(message);
    }
    ['assignDeep', 'assignDeepObjects'].forEach(function(method) {
        ['__proto__', 'prototype', 'constructor'].forEach(function(key) {
            check('R2 ' + method + ' rejects ' + key + ' at every object depth', function() {
                var payload = JSON.parse('{"' + key + '":{"vimPolluted":true},"nested":{"' + key + '":{"vimNestedPolluted":true},"safe":"translated"}}');
                try {
                    var target = DataTable.util.object[method]({}, payload);
                    require(({}).vimPolluted === undefined && ({}).vimNestedPolluted === undefined,
                        'merge polluted Object.prototype');
                    require(!Object.prototype.hasOwnProperty.call(target, key)
                        && !Object.prototype.hasOwnProperty.call(target.nested, key), 'unsafe merge key survived');
                    require(target.nested.safe === 'translated', 'ordinary nested translations lost');
                    var clean = DataTable.util.object[method]({}, { list: [1, 2], missing: null });
                    require(clean.list.join() === '1,2' && clean.missing === null, 'ordinary arrays/null lost');
                    return true;
                }
                finally { delete Object.prototype.vimPolluted; delete Object.prototype.vimNestedPolluted; }
            });
        });
    });

    check('R3 native JSON transport accepts 204, HEAD and 304 with single completion', function() {
        var NativeXHR = window.XMLHttpRequest;
        window.XMLHttpRequest = function() {
            this.open = this.setRequestHeader = this.send = function() {};
            this.getResponseHeader = function() { return 'application/json'; };
        };
        try {
            [[204, 'GET', '', 'nocontent'], [200, 'HEAD', '', 'nocontent'],
             [304, 'GET', '', 'notmodified'], [200, 'GET', '', 'parsererror'],
             [200, 'GET', '{broken', 'parsererror'], [503, 'GET', '', 'error'],
             [200, 'GET', 'null', 'success']].forEach(function(row) {
                var success = [], errors = [], completed = [];
                var xhr = ossAjax({ url: '/empty-json', type: row[1], dataType: 'json',
                    success: function(value, status) { success.push([value, status]); },
                    error: function(request, status) { errors.push(status); },
                    complete: function(request, status) { completed.push(status); }
                });
                xhr.status = row[0]; xhr.responseText = row[2]; xhr.onload(); xhr.onerror();
                require(completed.join() === row[3], row[0] + '/' + row[1] + ': completion ' + completed);
                if (row[3] === 'parsererror' || row[3] === 'error') {
                    require(!success.length && errors.join() === row[3], 'invalid response accepted');
                } else {
                    require(!errors.length && success.length === 1 && success[0][1] === row[3], 'success callback missing');
                    require(success[0][0] === (row[3] === 'success' ? null : undefined), 'unexpected response data');
                }
            });
            // Follow the production adapter into DataTables explicit 204 branch.
            var node = table(), api;
            try {
                api = new DataTable(node, { columns: [{ title: 'Name' }],
                    ajax: vmDataTableServerData('/empty-json', 0) });
                var request = api.settings()[0].jqXHR;
                request.status = 204; request.responseText = ''; request.onload();
                require(api.rows().count() === 0 && api.ajax.json().data.length === 0,
                    'DataTables did not consume 204 as an empty result');
            } finally { if (api) api.destroy(); node.remove(); }
            return true;
        } finally { window.XMLHttpRequest = NativeXHR; }
    });

    check('R4 destroy restores hidden header and body cells in original order', function() {
        var node = table();
        node.innerHTML = '<thead><tr><th>A</th><th>B</th><th>C</th></tr></thead>' +
            '<tbody><tr><td>a</td><td>b</td><td>c</td></tr></tbody>' +
            '<tfoot><tr><th>FA</th><th>FB</th><th>FC</th></tr></tfoot>';
        var originalCells = Array.from(node.querySelectorAll('th,td'));
        var api;
        try {
            api = new DataTable(node, { order: [] });
            api.column(1).visible(false);
            require(node.tBodies[0].rows[0].cells.length === 2, 'hide did not detach body cell');
            api.destroy(); api = null;
            require(Array.from(node.querySelectorAll('th,td')).every(function(cell, i) {
                return cell === originalCells[i];
            }) && node.querySelectorAll('th,td').length === originalCells.length, 'hidden cells not restored');
            api = new DataTable(node, { order: [] });
            require(api.columns().count() === 3 && api.cell(0, 1).data() === 'b', 'reinitialization lost hidden data');
            return true;
        } finally { if (api) api.destroy(); node.remove(); }
    });

    listLengthCallbacks.forEach(function(callback, index) {
        check('R5 list length callback ' + index + ' persists rendered selection and restores it', function() {
            var node = table(), api;
            var saved = Object.assign({}, vm_prefs);
            try {
                vm_prefs = { iLength: 10 };
                api = new DataTable(node, { data: [['a'], ['b']], columns: [{ title: 'Name' }],
                    drawCallback: callback, pageLength: 10 });
                var select = api.table().container().querySelector('select[aria-controls="' + node.id + '"]');
                require(select && select.name !== 'list_table_length', 'unexpected rendered length control');
                [25, 50, -1, 10].forEach(function(length) {
                    if (length === -1) api.page.len(length).draw();
                    else { select.value = String(length); select.dispatchEvent(new Event('change', { bubbles: true })); }
                    require(vm_prefs.iLength === length && vmPrefsCookie('vm_prefs').iLength === length,
                        'selected ' + length + ' persisted as ' + vm_prefs.iLength);
                });
                select.value = '25'; select.dispatchEvent(new Event('change', { bubbles: true }));
                vm_prefs = vmPrefsCookie('vm_prefs');
                api.destroy();
                api = new DataTable(node, { pageLength: listLengthRestores[index](), drawCallback: callback });
                require(api.page.len() === 25, 'saved page length did not restore');
                return true;
            } finally { if (api) api.destroy(); node.remove(); vm_prefs = saved; vmPrefsCookie('vm_prefs', saved, { path: '/' }); }
        });
    });

    listLengthRestores.forEach(function(restore, index) {
        check('R5 stored length validation ' + index + ' rejects corrupt cookies and restores valid lengths', function() {
            var saved = vm_prefs, node, api;
            try {
                [null, false, true, '', 'bad', '25junk', [], [25], {}, 0, -2, 1.5, '1.5', Infinity, NaN, 1e30].forEach(function(value) {
                    vm_prefs = { iLength: value };
                    require(restore() === 10, 'invalid stored length was accepted: ' + String(value));
                });
                [10, 25, -1, '50', '-1'].forEach(function(value) {
                    vm_prefs = { iLength: value };
                    require(restore() === Number(value), 'valid stored length was lost');
                });
                vm_prefs = {};
                require(restore() === 10, 'missing stored length did not use default');
                // Reproduce a cookie left by the broken empty selection.
                vmPrefsCookie('vm_prefs', { iLength: null }, { path: '/' });
                vm_prefs = vmPrefsCookie('vm_prefs');
                node = table();
                api = new DataTable(node, { data: [['visible']], columns: [{ title: 'Name' }], pageLength: restore() });
                require(api.page.len() === 10 && node.tBodies[0].textContent === 'visible', 'null preference made the table empty');
                return true;
            } finally {
                if (api) api.destroy(); if (node) node.remove();
                vm_prefs = saved; vmPrefsCookie('vm_prefs', saved, { path: '/' });
            }
        });
    });

    check('handled AJAX failure appears in visible modal after an earlier hidden modal', function() {
        var host = document.createElement('div');
        host.innerHTML = '<div class="modal-body" style="display:none" id="earlier-hidden-modal"></div>' +
            '<div id="modal_dialog_shell"><div class="modal-body" id="active-email-modal">Email settings</div>' +
            '<button id="modal_dialog_save" disabled>Save</button><button id="modal_dialog_cancel" disabled>Cancel</button></div>';
        document.body.prepend(host);
        try {
            ossAjaxErrorHandler({}, 'error', 'fixture');
            require(host.querySelector('#active-email-modal .alert-danger'), 'visible modal did not receive the AJAX error');
            require(!host.querySelector('#earlier-hidden-modal .alert'), 'hidden modal received the error');
            require(!host.querySelector('#modal_dialog_save').disabled, 'failed request left the save button disabled');
            return true;
        } finally { host.remove(); document.querySelectorAll('.modal-body .alert-danger').forEach(function(node) { node.remove(); }); }
    });

    check('R6 pagination redraw owns handlers through a weak element registry', function() {
        var node = table(), api;
        try {
            api = new DataTable(node, { data: Array.from({ length: 40 }, function(_, i) { return [i]; }),
                columns: [{ title: 'Number' }], pageLength: 2 });
            var first;
            for (var round = 0; round < 100; round++) {
                var button = api.table().container().querySelector('[data-dt-idx="next"]');
                require(button && hasDataTablesHandlers(button), 'pagination handlers are not weakly owned');
                if (!first) first = button;
                button.click();
                require(api.page() === (round % 19) + 1, 'pagination click lost or duplicated after redraw');
                require(!button.isConnected, 'old pagination button stayed attached');
                if (api.page() === 19) api.page(0).draw('page');
            }
            require(!first.isConnected, 'first button remained attached');
            // Namespaced removal and one-shot listeners must still use the store.
            var calls = 0, eventNode = document.createElement('button');
            DataTable.Dom.select(eventNode).one('click.probe', function() { calls++; });
            eventNode.click(); eventNode.click();
            require(calls === 1 && !hasDataTablesHandlers(eventNode), 'one-shot listener was not removed');
            DataTable.Dom.select(eventNode).on('click.probe', function() { calls++; }).off('.probe');
            eventNode.click();
            require(calls === 1 && !hasDataTablesHandlers(eventNode), 'namespace removal failed');
            return true;
        } finally { if (api) api.destroy(); node.remove(); }
    });

    check('R7 Dom.off removes zero, one and mixed handlers repeatedly', function() {
        var node = document.createElement('button');
        var dom = DataTable.Dom.select(node);
        var calls = [];
        var named = function() { calls.push('named'); };

        dom.off().off('click.missing');
        dom.on('click.one', named).off('click.one', named).off('click.one', named);
        node.click();
        require(calls.length === 0, 'single or repeated removal retained a handler');

        dom.on('click.keep', function() { calls.push('keep-click'); })
            .on('click.drop', function() { calls.push('drop-click-1'); })
            .on('keyup.drop', function() { calls.push('drop-keyup'); })
            .on('click.drop.extra', function() { calls.push('drop-click-2'); });
        dom.off('.drop').off('.drop');
        node.click();
        node.dispatchEvent(new Event('keyup'));
        require(calls.join() === 'keep-click', 'mixed namespace removal skipped a handler: ' + calls);

        dom.on('click', function() { calls.push('all-1'); })
            .on('keyup', function() { calls.push('all-2'); })
            .on('click.more', function() { calls.push('all-3'); });
        dom.off().off();
        node.click();
        node.dispatchEvent(new Event('keyup'));
        require(calls.join() === 'keep-click', 'unnamed removal skipped a handler: ' + calls);
        return true;
    });

    check('R8 destroy restores pixel, percentage and absent inline widths', function() {
        ['321px', '65%', ''].forEach(function(width) {
            var node = table(), api;
            node.style.width = width;
            try {
                api = new DataTable(node, { data: [['value']], columns: [{ title: 'Name' }] });
                api.destroy(); api = null;
                require(node.style.width === width,
                    'destroy changed ' + (width || 'absent') + ' inline width to ' + node.style.width);
                api = new DataTable(node, { data: [['value']], columns: [{ title: 'Name' }] });
                api.destroy(); api = null;
                require(node.style.width === width,
                    'reinitialization changed ' + (width || 'absent') + ' inline width to ' + node.style.width);
            } finally { if (api) api.destroy(); node.remove(); }
        });
        return true;
    });

    return new Promise(function(resolve, reject) {
        var node = table();
        DataTable.Dom.select(node).one('init.dt', function(event, settings) {
            check('R2 remote translation JSON preserves labels without prototype pollution', function() {
                try {
                    require(settings.language.emptyTable === 'Translated empty table', 'translation did not load');
                    require(node.tBodies[0].textContent === 'Translated empty table', 'translated label not rendered');
                    require(({}).vimPolluted === undefined && ({}).vimNestedPolluted === undefined, 'translation polluted Object.prototype');
                    return true;
                } finally { delete Object.prototype.vimPolluted; delete Object.prototype.vimNestedPolluted; }
            });
            settings.api.destroy(); node.remove(); resolve();
        });
        new DataTable(node, { columns: [{ title: 'Name' }], language: { ajax: '/datatables-language-pollution.json' } });
        setTimeout(function() { if (node.isConnected) reject(new Error('R2 translation initialization timed out')); }, 1500);
    });
}
