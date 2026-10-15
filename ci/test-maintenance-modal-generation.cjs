'use strict';

const fs = require('fs');
const vm = require('vm');

let failures = 0;
function check(label, ok) {
  console.log(`${ok ? '  ok  ' : '  FAIL'} ${label}`);
  if (!ok) failures += 1;
}

class ModalElement extends EventTarget {
  constructor() {
    super();
    this.listeners = new Map();
  }
  addEventListener(type, listener, options) {
    const listeners = this.listeners.get(type) || new Set();
    listeners.add(listener);
    this.listeners.set(type, listeners);
    super.addEventListener(type, listener, options);
  }
  removeEventListener(type, listener, options) {
    this.listeners.get(type)?.delete(listener);
    super.removeEventListener(type, listener, options);
  }
  listenerCount(type) {
    return this.listeners.get(type)?.size || 0;
  }
}

function runAsset(asset) {
  let source = fs.readFileSync(`${__dirname}/../${asset}`, 'utf8');
  if (process.env.VIMBADMIN_MODAL_DISABLE_GENERATION === '1') {
    source = source
      .replace(/if\(\s*modalGeneration\s*!==\s*tt_openModalDialog\.generation\s*\)\s*return;/g, '')
      .replace(/if\(\s*modalGeneration\s*===\s*tt_openModalDialog\.generation\s*\)/g, 'if( true )');
  }
  const start = source.indexOf('function tt_openModalDialog(event)');
  const end = source.indexOf('function ossAjaxErrorHandler', start);
  if (start < 0 || end < 0) throw new Error(`modal function was not found in ${asset}`);

  const requests = [];
  const htmlWrites = [];
  const handlers = new Map();
  const modalElement = new ModalElement();
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
      get: () => selector === '#modal_dialog_shell' ? modalElement : {},
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
  const label = `${asset}:`;

  open('/first');
  open('/second');
  check(`${label} repeated opens retain one native hide listener`, modalElement.listenerCount('hide.bs.modal') === 1);
  requests[0].success('old');
  requests[1].success('new');
  check(`${label} out-of-order response cannot replace the latest modal`, !htmlWrites.includes('old') && htmlWrites.includes('new'));

  open('/closed');
  modalElement.dispatchEvent(new Event('hide.bs.modal'));
  requests[2].success('closed-result');
  check(`${label} native Bootstrap hide invalidates its pending response`, !htmlWrites.includes('closed-result'));

  open('/cancelled');
  requests[3].success('cancel-content');
  handlers.get('click')();
  requests[3].success('cancelled-result');
  check(`${label} cancel invalidates later callbacks and closes the dialog`, !htmlWrites.includes('cancelled-result') && hides === 1);

  open('/failed-old');
  open('/failed-new');
  requests[4].error({}, 'error', 'old');
  requests[5].error({}, 'error', 'new');
  check(`${label} only the current request can surface a failure`, errors === 1);
}

runAsset('public/js/990-vimbadmin.js');
runAsset('public/js/min.bundle-v29.js');

console.log(failures === 0 ? 'ALL PASSED' : `${failures} FAILED`);
process.exit(failures === 0 ? 0 : 1);
