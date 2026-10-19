'use strict';

/* global EventTarget, URL, __dirname, __filename, console, process, require */

const fs = require('fs');
const vm = require('vm');
const {spawnSync} = require('node:child_process');

const root = process.env.VIMBADMIN_SOURCE_ROOT || `${__dirname}/..`;
const assets = [
  'public/js/990-vimbadmin.js',
  'public/js/min.bundle-v30.js',
];

let failures = 0;
let checks = 0;

function check(label, ok, detail = '') {
  checks += 1;
  console.log(`${ok ? '  ok  ' : '  FAIL'} ${label}${detail ? `: ${detail}` : ''}`);
  if (!ok) failures += 1;
}

function extractFunction(source, name) {
  const start = source.indexOf(`function ${name}(`);
  if (start < 0) throw new Error(`${name} was not found`);
  const body = source.indexOf('{', start);
  let depth = 0;
  let quote = '';
  let escaped = false;
  for (let index = body; index < source.length; index += 1) {
    const char = source[index];
    if (quote) {
      if (escaped) escaped = false;
      else if (char === '\\') escaped = true;
      else if (char === quote) quote = '';
      continue;
    }
    if (char === '"' || char === "'" || char === '`') {
      quote = char;
      continue;
    }
    if (char === '{') depth += 1;
    else if (char === '}' && --depth === 0) return source.slice(start, index + 1);
  }
  throw new Error(`${name} has no complete body`);
}

function mutateFunction(source, name) {
  let body = extractFunction(source, name);
  if (name === 'ossAjax' && process.env.VIMBADMIN_UI_EVENTS_DISABLE_FINISH_GUARD === '1') {
    const mutated = body.replace(/if\s*\(\s*finished\s*\)\s*return;/, 'if (false) return;');
    if (mutated === body) throw new Error('ossAjax finish guard mutation did not apply');
    body = mutated;
  }
  if (name === 'tt_openModalDialog' && process.env.VIMBADMIN_UI_EVENTS_DISABLE_STALE_GUARD === '1') {
    const mutated = body
      .replace(/if\s*\(\s*modalGeneration\s*!==\s*tt_openModalDialog\.generation\s*\)\s*return;/g, '')
      .replace(/if\s*\(\s*modalGeneration\s*===\s*tt_openModalDialog\.generation\s*\)/g, 'if (true)');
    if (mutated === body) throw new Error('modal stale-response mutation did not apply');
    body = mutated;
  }
  if (name === 'tt_openModalDialog' && process.env.VIMBADMIN_UI_EVENTS_INVERT_ERROR_GUARD === '1') {
    const mutated = body.replace(
      /if\s*\(\s*modalGeneration\s*===\s*tt_openModalDialog\.generation\s*\)(\s*ossAjaxErrorHandler\s*\()/,
      'if (modalGeneration !== tt_openModalDialog.generation)$1'
    );
    if (mutated === body) throw new Error('modal error-only generation mutation did not apply');
    body = mutated;
  }
  return body;
}

class FakeXHR {
  static instances = [];

  constructor() {
    this.headers = new Map();
    this.responseHeaders = new Map();
    this.readyState = 0;
    this.responseText = '';
    this.status = 0;
    this.statusText = '';
    FakeXHR.instances.push(this);
  }

  open(method, url, async) {
    this.method = method;
    this.url = url;
    this.async = async;
  }

  setRequestHeader(name, value) {
    this.headers.set(name.toLowerCase(), value);
  }

  getResponseHeader(name) {
    return this.responseHeaders.get(name.toLowerCase()) || null;
  }

  send(body) {
    this.body = body;
  }

  respond(status, responseText, contentType = 'text/plain', statusText = '') {
    this.status = status;
    this.statusText = statusText;
    this.responseText = responseText;
    this.readyState = 4;
    this.responseHeaders.set('content-type', contentType);
    this.onload();
  }
}

function transportContext(source) {
  const serialized = [];
  const context = {
    URL,
    Date,
    document: {baseURI: 'https://example.test/vimbadmin/'},
    location: {origin: 'https://example.test'},
    XMLHttpRequest: FakeXHR,
    DataTable: {ajax: {serialize(value) {
      serialized.push(value);
      if (value && value.query === 'hostile') return 'q=%3Cimg%20src%3Dx%20onerror%3Dalert%281%29%3E&space=a%20b';
      return '';
    }}},
  };
  vm.createContext(context);
  vm.runInContext(mutateFunction(source, 'ossAjax'), context);
  return {context, serialized};
}

function runTransport(asset, source) {
  FakeXHR.instances.length = 0;
  const {context, serialized} = transportContext(source);
  const label = `${asset}:`;
  const success = [];
  const errors = [];
  const completed = [];
  const csrfBody = 'csrf=token%2F%2B&label=%3Cimg%20src%3Dx%20onerror%3Dalert%281%29%3E';
  const request = context.ossAjax({
    url: 'mailbox/update',
    data: csrfBody,
    type: 'post',
    timeout: 10000,
    success: (value, status) => success.push([value, status]),
    error: (xhr, status) => errors.push(status),
    complete: (xhr, status) => completed.push(status),
  });

  check(`${label} helper returns its live XHR`, request === FakeXHR.instances[0]);
  check(`${label} relative application URL and POST method are preserved`,
    request.url === 'https://example.test/vimbadmin/mailbox/update' && request.method === 'POST');
  check(`${label} CSRF and hostile form bytes remain encoded in the request body`, request.body === csrfBody);
  check(`${label} same-origin form headers and timeout are retained`,
    request.headers.get('content-type') === 'application/x-www-form-urlencoded; charset=UTF-8'
      && request.headers.get('x-requested-with') === 'XMLHttpRequest'
      && request.headers.get('accept') === '*/*'
      && request.timeout === 10000);
  request.respond(200, 'ok');
  request.onerror();
  check(`${label} successful request invokes success and completion exactly once`,
    success.length === 1 && success[0][0] === 'ok' && success[0][1] === 'success'
      && errors.length === 0 && completed.join() === 'success',
    `success=${success.length} error=${errors.length} complete=${completed.join()}`);

  const objectRequest = context.ossAjax({url: 'list-data?scope=all', data: {query: 'hostile'}, cache: false});
  const objectUrl = new URL(objectRequest.url);
  check(`${label} GET serializes hostile input once and keeps existing query plus cache boundary`,
    serialized.length === 1 && serialized[0].query === 'hostile'
      && objectUrl.pathname === '/vimbadmin/list-data'
      && objectUrl.searchParams.get('scope') === 'all'
      && objectUrl.searchParams.get('q') === '<img src=x onerror=alert(1)>'
      && objectUrl.searchParams.get('space') === 'a b'
      && objectUrl.searchParams.has('_')
      && objectRequest.body === null);

  const emptyRequest = context.ossAjax({url: '/vimbadmin/empty', data: {}});
  check(`${label} empty GET data sends no body`, emptyRequest.body === null);

  const crossOrigin = context.ossAjax({url: 'https://api.example.invalid/data', data: 'csrf=secret', type: 'POST'});
  check(`${label} cross-origin request does not receive same-origin marker`,
    !crossOrigin.headers.has('x-requested-with'));

  const malformedErrors = [];
  const malformedComplete = [];
  const malformed = context.ossAjax({
    url: '/vimbadmin/json',
    dataType: 'json',
    success: () => malformedErrors.push('unexpected-success'),
    error: (xhr, status) => malformedErrors.push(status),
    complete: (xhr, status) => malformedComplete.push(status),
  });
  malformed.respond(200, '{broken', 'application/json');
  check(`${label} malformed JSON is a visible parser failure with completion`,
    malformedErrors.join() === 'parsererror' && malformedComplete.join() === 'parsererror');

  const failedErrors = [];
  const failedComplete = [];
  const failed = context.ossAjax({
    url: '/vimbadmin/failure',
    error: (xhr, status) => failedErrors.push(status),
    complete: (xhr, status) => failedComplete.push(status),
  });
  failed.respond(503, '<script>hostile()</script>', 'text/html', 'Unavailable');
  check(`${label} failed request reports error once without treating hostile body as success`,
    failedErrors.join() === 'error' && failedComplete.join() === 'error');

  const noContentSuccess = [];
  const noContent = context.ossAjax({
    url: '/vimbadmin/no-content',
    dataType: 'json',
    success: (value, status) => noContentSuccess.push([value, status]),
  });
  noContent.respond(204, '', 'application/json');
  check(`${label} empty 204 JSON response keeps the no-content success contract`,
    noContentSuccess.length === 1 && noContentSuccess[0][0] === undefined
      && noContentSuccess[0][1] === 'nocontent');
}

function response(status, contentType, responseText) {
  return {
    status,
    responseText,
    getResponseHeader(name) {
      return name.toLowerCase() === 'content-type' ? contentType : null;
    },
  };
}

function runAuthAndUrl(asset, source) {
  const context = {URL, document: {baseURI: 'https://example.test/vimbadmin/'}};
  vm.createContext(context);
  vm.runInContext(extractFunction(source, 'vmDataTableAuthenticationExpired'), context);
  vm.runInContext(extractFunction(source, 'vmDataTableLoginUrl'), context);
  const label = `${asset}:`;
  check(`${label} exact JSON 401 contract is recognized as authentication expiry`,
    context.vmDataTableAuthenticationExpired(response(
      401, 'application/json; charset=UTF-8', '{"error":"Authentication required"}'
    )) === true);
  check(`${label} malformed, hostile, non-JSON and wrong-status responses are not authentication expiry`,
    [
      response(401, 'application/json', '{broken'),
      response(401, 'application/json', '["Authentication required"]'),
      response(401, 'text/html', '<script>login</script>'),
      response(403, 'application/json', '{"error":"Authentication required"}'),
    ].every(xhr => context.vmDataTableAuthenticationExpired(xhr) === false));
  check(`${label} auth redirect preserves a nested application root and drops route parameters`,
    context.vmDataTableLoginUrl('/vimbadmin/alias/list-data/id/7?draw=1#fragment')
      === 'https://example.test/vimbadmin/auth/login');
  check(`${label} unmatched endpoint falls back to origin-root login`,
    context.vmDataTableLoginUrl('/unexpected/path') === 'https://example.test/auth/login');
}

function runVisibleFailure(asset, source) {
  const state = {messages: [], restored: 0, removed: 0};
  function selection(selector) {
    const api = {
      length: selector === '#modal_dialog_save' || selector === '#modal_dialog_cancel'
        || selector === 'canvas' || selector === '.vb-throbber' ? 1 : 0,
      isVisible: () => selector === '#modal_dialog_shell',
      prop() { state.restored += 1; return api; },
      removeClass() { return api; },
      remove() { state.removed += 1; return api; },
    };
    return api;
  }
  const context = {
    DataTable: {Dom: {select: selection}},
    dialog: null,
    ossAddMessage: (...args) => state.messages.push(args),
  };
  vm.createContext(context);
  vm.runInContext(extractFunction(source, 'ossAjaxErrorHandler'), context);
  context.ossAjaxErrorHandler({}, 'error', '<img src=x onerror=alert(1)>');
  check(`${asset}: failed request restores controls and removes only owned loading UI`,
    state.restored === 2 && state.removed === 2);
  check(`${asset}: hostile transport detail is replaced by the fixed visible failure message`,
    state.messages.length === 1
      && state.messages[0][0] === 'An unexpected error occurred.'
      && state.messages[0][1] === 'danger'
      && state.messages[0][2] === true
      && !state.messages[0].join(' ').includes('onerror'));
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

function runModalStaleness(asset, source) {
  const requests = [];
  const htmlWrites = [];
  const modalElement = new ModalElement();
  const clickHandlers = new Map();
  const visibleErrors = [];

  function selection(selector) {
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
      find: child => selection(child),
      first: () => api,
      text: () => '',
      filter: () => ({length: 0}),
      scrollTop: () => api,
      on(name, handler) { clickHandlers.set(name, handler); return api; },
    };
    return api;
  }

  const context = {
    DataTable: {Dom: {select: selection}},
    tt_throbber: () => ({appendTo() { return this; }, start() {}}),
    ossModal: () => ({hide() {}}),
    ossAjax: options => requests.push(options),
    ossAjaxErrorHandler: xhr => visibleErrors.push(xhr),
  };
  vm.createContext(context);
  vm.runInContext(mutateFunction(source, 'tt_openModalDialog'), context);
  function open(href) {
    context.tt_openModalDialog({preventDefault() {}, target: {id: 'modal-dialog-1', href, title: href}});
  }

  open('/first');
  open('/second');
  check(`${asset}: repeated modal initialization retains one native hide listener`,
    modalElement.listenerCount('hide.bs.modal') === 1);
  requests[0].success('<div>stale hostile</div>');
  requests[1].success('<div>current safe</div>');
  check(`${asset}: stale response cannot replace the current request result`,
    !htmlWrites.includes('<div>stale hostile</div>') && htmlWrites.includes('<div>current safe</div>'));
  open('/failed-old');
  open('/failed-current');
  const olderFailure = {request: 'older-failure'};
  const currentFailure = {request: 'current-failure'};
  requests[2].error(olderFailure, 'error', 'old');
  requests[3].error(currentFailure, 'error', 'current');
  check(`${asset}: only the current failed request becomes visible`,
    visibleErrors.length === 1 && visibleErrors[0] === currentFailure,
    `visible-errors=${visibleErrors.map(xhr => xhr.request).join()}`);
}

function runMissingElement(asset, source) {
  const state = {requests: 0};
  const context = {
    WeakSet,
    ossPendingToggles: new WeakSet(),
    ossAjax: () => { state.requests += 1; },
  };
  vm.createContext(context);
  vm.runInContext(extractFunction(source, 'ossToggle'), context);
  const missing = {get: () => undefined, hasClass: () => false};
  check(`${asset}: missing toggle element returns undefined without a request`,
    context.ossToggle(missing, '/toggle', {}) === undefined && state.requests === 0);
}

function mutationFailedAsExpected(result, expected, totalChecks) {
  const observed = (result.stdout || '').split('\n').filter(line => line.startsWith('  FAIL '));
  return !result.error && result.signal === null && result.status === 1 && result.stderr === ''
    && result.stdout.endsWith(`${expected.length} FAILED (${totalChecks} checks)\n`)
    && observed.length === expected.length
    && expected.every(label => observed.filter(line => line === `  FAIL ${label}`
      || line.startsWith(`  FAIL ${label}: `)).length === 1);
}

function runMutationControls() {
  const controls = [
    {
      flag: 'VIMBADMIN_UI_EVENTS_DISABLE_FINISH_GUARD',
      name: 'finish guard',
      labels: ['successful request invokes success and completion exactly once'],
    },
    {
      flag: 'VIMBADMIN_UI_EVENTS_DISABLE_STALE_GUARD',
      name: 'stale-response guard',
      labels: ['stale response cannot replace the current request result',
        'only the current failed request becomes visible'],
    },
    {
      flag: 'VIMBADMIN_UI_EVENTS_INVERT_ERROR_GUARD',
      name: 'error-only generation',
      labels: ['only the current failed request becomes visible'],
    },
  ];
  // Manual mutants and marked children run the observable tests once. The marker
  // also bounds recursion if a child accidentally loses its mutation flag.
  if (process.env.VIMBADMIN_UI_EVENTS_MUTATION_CHILD === '1'
    || controls.some(({flag}) => process.env[flag] === '1')) return;
  const totalChecks = checks;
  for (const {flag, name, labels} of controls) {
    const expected = assets.flatMap(asset => labels.map(label => `${asset}: ${label}`));
    const result = spawnSync(process.execPath, [__filename], {
      env: {...process.env, [flag]: '1', VIMBADMIN_UI_EVENTS_MUTATION_CHILD: '1'},
      encoding: 'utf8', timeout: 30000, maxBuffer: 256 * 1024,
    });
    const observed = (result.stdout || '').split('\n').filter(line => line.startsWith('  FAIL ')).length;
    check(`${name} mutation fails at the expected identities for both assets`,
      mutationFailedAsExpected(result, expected, totalChecks),
      `expected=${expected.length}, observed=${observed}, status=${result.status}`);

    const valid = {
      status: 1, signal: null, stderr: '',
      stdout: expected.map(label => `  FAIL ${label}\n`).join('')
        + `${expected.length} FAILED (${totalChecks} checks)\n`,
    };
    check(`${name} result validator accepts exact failures with and without details`,
      mutationFailedAsExpected(valid, expected, totalChecks)
        && mutationFailedAsExpected({...valid,
          stdout: expected.map(label => `  FAIL ${label}: detail\n`).join('')
            + `${expected.length} FAILED (${totalChecks} checks)\n`,
        }, expected, totalChecks));
    const invalid = [
      {...valid, status: 0},
      {...valid, status: null, signal: 'SIGTERM'},
      {...valid, error: new Error('child failed')},
      {...valid, stderr: 'bootstrap failed'},
      {...valid, stdout: 'bootstrap failed\n'},
      {...valid, stdout: `${expected.length} FAILED (${totalChecks} checks)\n`},
      {...valid, stdout: valid.stdout.replace(expected[0], 'unrelated assertion')},
      {...valid, stdout: valid.stdout.replace(expected[0], expected[1])},
      {...valid, stdout: valid.stdout.replace(`${totalChecks} checks`, `${totalChecks + 1} checks`)},
      {...valid, stdout: valid.stdout + '  FAIL unexpected extra assertion\n'},
    ];
    check(`${name} result validator rejects success, failed children and false failure identities`,
      invalid.every(value => !mutationFailedAsExpected(value, expected, totalChecks)));
  }
}

for (const asset of assets) {
  const source = fs.readFileSync(`${root}/${asset}`, 'utf8');
  runTransport(asset, source);
  runAuthAndUrl(asset, source);
  runVisibleFailure(asset, source);
  runModalStaleness(asset, source);
  runMissingElement(asset, source);
}

if (failures === 0) runMutationControls();
console.log(failures === 0 ? `ALL PASSED (${checks} checks)` : `${failures} FAILED (${checks} checks)`);
process.exit(failures === 0 ? 0 : 1);
