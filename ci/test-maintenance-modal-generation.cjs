'use strict';

const fs = require('fs');
const vm = require('vm');

let failures = 0;
function check(label, ok) {
  console.log(`${ok ? '  ok  ' : '  FAIL'} ${label}`);
  if (!ok) failures += 1;
}

let source = fs.readFileSync(`${__dirname}/../public/js/990-vimbadmin.js`, 'utf8');
if (process.env.VIMBADMIN_MODAL_DISABLE_GENERATION === '1') {
  source = source
    .replace(/if\( modalGeneration !== tt_openModalDialog\.generation \)\s*return;/g, '')
    .replace(/if\( modalGeneration === tt_openModalDialog\.generation \)/g, 'if( true )');
}
const start = source.indexOf('function tt_openModalDialog(event)');
const end = source.indexOf('\n/**\n * This function is handling ajax errors.', start);
if (start < 0 || end < 0) throw new Error('modal function was not found');

const requests = [];
const htmlWrites = [];
const handlers = new Map();
let errors = 0;
let hides = 0;

function wrapper(selector) {
  const api = {
    length: selector === '.modal-title' ? 0 : 1,
    is: () => false,
    parent: () => api,
    attr(name, value) {
      if (value !== undefined) return api;
      if (typeof selector === 'object') return selector[name] || selector.attrs?.[name];
      return undefined;
    },
    attrRemove: () => api,
    addClass: () => api,
    removeClass: () => api,
    html(value) { if (value !== undefined) htmlWrites.push(value); return api; },
    get: () => ({}),
    find: child => wrapper(child),
    first: () => api,
    text: () => '',
    filter: () => ({length: 0}),
    scrollTop: () => api,
    off(name) { handlers.delete(name); return api; },
    on(name, fn) { handlers.set(name, fn); return api; },
  };
  return api;
}

const context = {
  DataTable: {Dom: {select: wrapper}},
  tt_throbber: () => ({appendTo() { return this; }, start() {}}),
  ossModal: () => ({hide() { hides += 1; }}),
  ossAjax: options => requests.push(options),
  ossAjaxErrorHandler: () => { errors += 1; },
};
vm.createContext(context);
vm.runInContext(source.slice(start, end), context);

function open(href) {
  context.tt_openModalDialog({preventDefault() {}, target: {id: 'modal-dialog-1', href, title: href}});
}

open('/first');
open('/second');
requests[0].success('old');
requests[1].success('new');
check('out-of-order response cannot replace the latest modal', !htmlWrites.includes('old') && htmlWrites.includes('new'));

open('/closed');
handlers.get('hide.bs.modal.vimbadmin-generation')();
requests[2].success('closed-result');
check('closing a modal invalidates its pending response', !htmlWrites.includes('closed-result'));

open('/cancelled');
requests[3].success('cancel-content');
handlers.get('click')();
requests[3].success('cancelled-result');
check('cancel invalidates later callbacks and closes the dialog', !htmlWrites.includes('cancelled-result') && hides === 1);

open('/failed-old');
open('/failed-new');
requests[4].error({}, 'error', 'old');
requests[5].error({}, 'error', 'new');
check('only the current request can surface a failure', errors === 1);

console.log(failures === 0 ? 'ALL PASSED' : `${failures} FAILED`);
process.exit(failures === 0 ? 0 : 1);
