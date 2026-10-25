#!/usr/bin/env node
'use strict';
/* global require, module, process, console, URL, Buffer, document, HTMLInputElement */

const fs = require('node:fs/promises');
const http = require('node:http');
const net = require('node:net');
const { performance } = require('node:perf_hooks');

function check(condition, message) {
  if (!condition) throw new Error(message);
}

async function getJson(url, token) {
  return await new Promise((resolve, reject) => {
    const request = http.get(url, {
      agent: false,
      headers: { 'X-Vimbadmin-Fixture-Token': token }
    }, response => {
      const chunks = [];
      response.on('data', chunk => chunks.push(chunk));
      response.on('end', () => {
        try {
          check(response.statusCode === 200, 'state verifier returned a non-200 response');
          const value = JSON.parse(Buffer.concat(chunks).toString('utf8'));
          check(value?.ok === true, 'state verifier did not return an OK snapshot');
          resolve(value);
        } catch (error) { reject(error); }
      });
    });
    request.on('error', reject);
    request.setTimeout(10000, () => request.destroy(new Error('state verifier timed out')));
  });
}

async function rawMalformedStatus(origin) {
  const url = new URL(origin);
  const requestBytes = `GET /auth/%zz HTTP/1.1\r\nHost: 127.0.0.1:${url.port}\r\nConnection: close\r\n\r\n`;
  return await new Promise((resolve, reject) => {
    const socket = net.connect(Number(url.port), '127.0.0.1');
    const chunks = [];
    socket.setTimeout(10000, () => socket.destroy(new Error('raw malformed request timed out')));
    socket.on('connect', () => socket.end(requestBytes));
    socket.on('data', chunk => chunks.push(chunk));
    socket.on('end', () => {
      const statusLine = Buffer.concat(chunks).toString('latin1').split('\r\n', 1)[0];
      resolve(statusLine);
    });
    socket.on('error', reject);
  });
}

async function responseChain(response) {
  check(response, 'browser navigation returned no application response');
  let request = response.request();
  while (request.redirectedFrom()) request = request.redirectedFrom();
  const first = await request.response();
  check(first, 'browser redirect chain omitted its first response');
  return { initialStatus: first.status(), finalStatus: response.status() };
}

// Keep diagnostics separate from the security oracle. A missing POST is only
// unobserved submission; it does not establish why the browser did not navigate.
async function submitCsrfDenial(formPage, name) {
  const scenario = ['missing CSRF denial', 'invalid CSRF denial'].includes(name)
    ? name : 'unknown CSRF denial';
  const started = performance.now();
  const elapsed = () => Math.max(0, Math.round(performance.now() - started));
  const progress = { submit: 'pending', navigation: 'pending', request: null, response: null, loadMs: null };
  let submittedRequest;
  const listeners = {
    request(request) {
      try {
        if (!submittedRequest && request.isNavigationRequest() && request.method() === 'POST' &&
            new URL(request.url()).pathname === '/auth/totp') {
          submittedRequest = request;
          progress.request = { method: 'POST', pathname: '/auth/totp', elapsedMs: elapsed() };
        }
      } catch { /* Unavailable metadata must not change the submission. */ }
    },
    response(response) {
      try {
        const status = response.status();
        if (!progress.response && submittedRequest && response.request() === submittedRequest &&
            Number.isInteger(status) && status >= 100 && status <= 599) {
          progress.response = { status, elapsedMs: elapsed() };
        }
      } catch { /* Unavailable metadata remains unobserved. */ }
    },
    load() { if (progress.loadMs === null) progress.loadMs = elapsed(); }
  };
  for (const [event, listener] of Object.entries(listeners)) formPage.on(event, listener);
  try {
    const [response] = await Promise.all([
      formPage.waitForNavigation({ waitUntil: 'load', timeout: 15000 }).then(response => {
        progress.navigation = 'complete';
        return response;
      }, error => { progress.navigation = 'failed'; throw error; }),
      formPage.click('button[type="submit"]').then(() => {
        progress.submit = 'complete';
      }, error => { progress.submit = 'failed'; throw error; })
    ]);
    return response;
  } catch (error) {
    // Never include the original error or arbitrary browser data here: either
    // may contain a URL, submitted value or token. Preserve the original error.
    try {
      console.error(`CSRF submission diagnostic: ${JSON.stringify({ scenario, ...progress, elapsedMs: elapsed() })}`);
    } catch { /* A diagnostic sink failure must not replace the original error. */ }
    throw error;
  } finally {
    for (const [event, listener] of Object.entries(listeners)) formPage.off(event, listener);
  }
}

async function runApplicationSuite({ browserContext, targetOrigin, root, input, mutation }) {
  check(input && typeof input === 'object', 'application input must be an object');
  check(typeof input.verifierOrigin === 'string', 'application verifier origin is required');
  check(typeof input.verifierToken === 'string' && /^[a-f0-9]{64}$/.test(input.verifierToken),
    'application verifier token is invalid');
  check(typeof input.stateDirectory === 'string' && input.stateDirectory.startsWith(`${root}/`),
    'application state directory is outside the fixture root');

  const observedAuth = new Set();
  const observedErrors = new Set();
  const observedState = new Set();
  const assertions = [];
  const stateSnapshots = {};

  async function useState(alias) {
    const filename = `${input.stateDirectory}/${alias}.json`;
    const state = JSON.parse(await fs.readFile(filename, 'utf8'));
    check(Array.isArray(state.cookies) && state.cookies.length === 1,
      'private browser state must contain one fixture cookie');
    await browserContext.clearCookies();
    await browserContext.addCookies(state.cookies);
  }

  async function snapshot(alias) {
    return await getJson(
      `${input.verifierOrigin}/snapshot?session=${encodeURIComponent(alias)}`,
      input.verifierToken
    );
  }

  function compareDatabase(before, after, name) {
    check(before.database.sha256 === after.database.sha256 &&
      before.database.tables === after.database.tables && before.database.rows === after.database.rows,
    `${name}: failed request mutated database state`);
  }

  function compareSession(before, after, name) {
    check(JSON.stringify(before.session) === JSON.stringify(after.session),
      `${name}: failed request mutated session state`);
  }

  function recordState(name, before, after) {
    stateSnapshots[name] = {
      databaseBefore: before.database.sha256,
      databaseAfter: after.database.sha256,
      databaseTables: before.database.tables,
      databaseRows: before.database.rows,
      sessionBefore: before.session.sha256,
      sessionAfter: after.session.sha256,
      sessionExistedBefore: before.session.exists,
      sessionExistedAfter: after.session.exists
    };
  }

  async function navigate(name, path, options = {}) {
    const page = await browserContext.newPage();
    try {
      if (options.headers) await page.setExtraHTTPHeaders(options.headers);
      const captchaStatuses = [];
      page.on('response', response => {
        if (new URL(response.url()).pathname.startsWith('/auth/captcha-image/')) {
          captchaStatuses.push(response.status());
        }
      });
      const response = await page.goto(new URL(path, targetOrigin).href, {
        waitUntil: 'load', timeout: 15000
      });
      const chain = await responseChain(response);
      if (options.initialStatus !== undefined) {
        check(chain.initialStatus === options.initialStatus, `${name}: unexpected initial HTTP status`);
      }
      if (options.finalStatus !== undefined) {
        check(chain.finalStatus === options.finalStatus, `${name}: unexpected final HTTP status`);
      }
      if (options.finalPath !== undefined) {
        check(new URL(page.url()).pathname === options.finalPath, `${name}: unexpected final application path`);
      }
      if (options.selector) {
        check(await page.locator(options.selector).count() > 0, `${name}: rendered application marker is absent`);
      }
      if (options.bodyText) {
        const text = await page.locator('body').innerText();
        check(text.includes(options.bodyText), `${name}: rendered error text is absent`);
      }
      if (options.captcha) {
        check(captchaStatuses.includes(200), `${name}: rendered captcha application request did not return 200`);
      }
      assertions.push(name);
      return page;
    } catch (error) {
      await page.close();
      throw error;
    }
  }

  if (input.mode === 'guard-smoke') {
    await useState('anonymous');
    const page = await navigate('application-origin guard smoke', '/auth/login', {
      finalStatus: 200, finalPath: '/auth/login', selector: 'form[action="/auth/login"]'
    });
    await page.close();
    return { mode: 'guard-smoke', assertions: assertions.length };
  }
  check(input.mode === 'full', 'unknown application suite mode');

  await useState('anonymous');
  if (mutation !== 'skip-auth-assertion') {
    let page = await navigate('anonymous auth/login', '/auth/login', {
      finalStatus: 200, finalPath: '/auth/login', selector: 'form[action="/auth/login"]'
    });
    await page.close();
    observedAuth.add('auth/login');
  }
  let page = await navigate('anonymous auth/change-password', '/auth/change-password', {
    finalStatus: 200, finalPath: '/auth/change-password', selector: 'form[action="/auth/change-password"]'
  });
  await page.close();
  observedAuth.add('auth/change-password');

  page = await navigate('anonymous auth/lost-password and captcha-image', '/auth/lost-password', {
    finalStatus: 200, finalPath: '/auth/lost-password', selector: '#captchaimage', captcha: true
  });
  await page.close();
  observedAuth.add('auth/lost-password');
  observedAuth.add('auth/captcha-image');

  page = await navigate('anonymous auth/reset-password', '/auth/reset-password', {
    finalStatus: 200, finalPath: '/auth/reset-password', selector: 'form[action="/auth/reset-password"]'
  });
  await page.close();
  observedAuth.add('auth/reset-password');

  for (const [route, state] of [['auth/setup', 'anonymous'], ['auth/totp', 'anonymous'], ['auth/totp-setup', 'anonymous']]) {
    await useState(state);
    page = await navigate(`anonymous ${route}`, `/${route}`, {
      initialStatus: 302, finalStatus: 200, finalPath: '/auth/login', selector: 'form[action="/auth/login"]'
    });
    await page.close();
    observedAuth.add(route);
  }

  await useState('anonymous_logout');
  page = await navigate('anonymous auth/logout', '/auth/logout', {
    initialStatus: 302, finalStatus: 200, finalPath: '/auth/login', selector: 'form[action="/auth/login"]'
  });
  await page.close();
  observedAuth.add('auth/logout');

  await useState('pending_totp');
  page = await navigate('pending-2FA auth/totp', '/auth/totp', {
    finalStatus: 200, finalPath: '/auth/totp', selector: 'form[action="/auth/totp"]'
  });
  await page.close();
  observedAuth.add('pending:auth/totp');

  await useState('pending_setup');
  page = await navigate('pending-2FA auth/totp-setup', '/auth/totp-setup', {
    finalStatus: 200, finalPath: '/auth/totp-setup', selector: 'img[alt="TOTP QR code"]'
  });
  await page.close();
  observedAuth.add('pending:auth/totp-setup');

  await useState('error');
  let before = await snapshot('error');
  page = await navigate('unknown-route 404', '/browser-fixture-unknown/route', {
    finalStatus: mutation === 'skip-error-status' ? undefined : 404,
    finalPath: '/browser-fixture-unknown/route',
    bodyText: mutation === 'skip-error-status' ? undefined : 'Not found'
  });
  await page.close();
  let after = await snapshot('error');
  compareDatabase(before, after, 'unknown-route 404');
  compareSession(before, after, 'unknown-route 404');
  recordState('unknown-route-404', before, after);
  observedState.add('unknown-route-404');
  if (mutation !== 'skip-error-status') observedErrors.add('unknown-route-404');

  before = await snapshot('error');
  page = await navigate('malformed URI 400', '/auth/login', {
    headers: { 'X-Vimbadmin-Fixture-Malformed-Uri': '1' },
    finalStatus: 400, finalPath: '/auth/login', bodyText: 'Malformed request URI'
  });
  await page.close();
  after = await snapshot('error');
  compareDatabase(before, after, 'malformed URI 400');
  compareSession(before, after, 'malformed URI 400');
  recordState('malformed-uri-400', before, after);
  const rawStatus = await rawMalformedStatus(targetOrigin);
  check(rawStatus.startsWith('HTTP/1.1 400 '),
    'malformed URI 400: raw trigger did not exercise the entry-boundary 400');
  observedErrors.add('malformed-uri-400');
  observedState.add('malformed-uri-400');

  before = await snapshot('error');
  page = await navigate('injected bootstrap 500', '/auth/login', {
    headers: { 'X-Vimbadmin-Fixture-Bootstrap-Failure': '1' },
    finalStatus: 500, finalPath: '/auth/login', bodyText: 'Internal server error'
  });
  await page.close();
  after = await snapshot('error');
  compareDatabase(before, after, 'injected bootstrap 500');
  compareSession(before, after, 'injected bootstrap 500');
  recordState('bootstrap-500', before, after);
  observedErrors.add('bootstrap-500');
  observedState.add('bootstrap-500');

  // The development router must retain its front-controller hooks even when
  // the requested path names index.php directly. Returning false for this path
  // would let the built-in server bypass the malformed-URI injection.
  before = await snapshot('error');
  page = await navigate('direct front-controller malformed URI 400', '/index.php', {
    headers: { 'X-Vimbadmin-Fixture-Malformed-Uri': '1' },
    finalStatus: 400, finalPath: '/index.php', bodyText: 'Malformed request URI'
  });
  await page.close();
  after = await snapshot('error');
  compareDatabase(before, after, 'direct front-controller malformed URI 400');
  compareSession(before, after, 'direct front-controller malformed URI 400');
  recordState('direct-front-controller-malformed-uri-400', before, after);
  observedErrors.add('direct-front-controller-malformed-uri-400');
  observedState.add('direct-front-controller-malformed-uri-400');

  async function csrfDenial(alias, submittedValue, name) {
    await useState(alias);
    const formPage = await navigate(`${name} form`, '/auth/totp', {
      finalStatus: 200, finalPath: '/auth/totp', selector: 'form[action="/auth/totp"]'
    });
    const baseline = await snapshot(alias);
    await formPage.fill('input[name="code"]', '000000');
    if (submittedValue === null) {
      await formPage.locator('input[name="csrf"]').evaluate(element => element.remove());
    } else {
      await formPage.evaluate(value => {
        const input = document.querySelector('input[name="csrf"]');
        if (!(input instanceof HTMLInputElement)) throw new Error('CSRF input is absent');
        input.value = value;
      }, submittedValue);
    }
    const response = await submitCsrfDenial(formPage, name);
    check(response && response.status() === 200, `${name}: CSRF denial did not render HTTP 200`);
    check(new URL(formPage.url()).pathname === '/auth/totp', `${name}: CSRF denial left the TOTP route`);
    const message = await formPage.locator('.alert-danger').innerText();
    check(message.includes('Invalid or missing security token'), `${name}: CSRF denial message is absent`);
    await formPage.close();
    const finalSnapshot = await snapshot(alias);
    compareDatabase(baseline, finalSnapshot, name);
    recordState(alias.replace('_', '-'), baseline, finalSnapshot);
    if (!(mutation === 'skip-state-snapshot' && alias === 'csrf_invalid')) {
      compareSession(baseline, finalSnapshot, name);
      observedState.add(alias.replace('_', '-'));
    }
    observedErrors.add(alias.replace('_', '-'));
  }
  await csrfDenial('csrf_missing', null, 'missing CSRF denial');
  await csrfDenial('csrf_invalid', 'fixture-invalid-token', 'invalid CSRF denial');

  await useState('expired');
  before = await snapshot('expired');
  check(before.session.exists === true, 'session expiry: seeded authenticated session is absent');
  page = await navigate('session expiry', '/', {
    initialStatus: 302, finalStatus: 200, finalPath: '/auth/login', selector: 'form[action="/auth/login"]'
  });
  await page.close();
  after = await snapshot('expired');
  compareDatabase(before, after, 'session expiry');
  check(after.session.exists === false, 'session expiry: expired authenticated session was not destroyed');
  recordState('session-expiry', before, after);
  observedErrors.add('session-expiry');
  observedState.add('session-expiry');

  const requiredAuth = [
    'auth/captcha-image', 'auth/change-password', 'auth/login', 'auth/logout',
    'auth/lost-password', 'auth/reset-password', 'auth/setup', 'auth/totp',
    'auth/totp-setup', 'pending:auth/totp', 'pending:auth/totp-setup'
  ];
  const requiredErrors = [
    'unknown-route-404', 'malformed-uri-400', 'bootstrap-500',
    'direct-front-controller-malformed-uri-400', 'csrf-missing', 'csrf-invalid',
    'session-expiry'
  ];
  const requiredState = [
    'unknown-route-404', 'malformed-uri-400', 'bootstrap-500',
    'direct-front-controller-malformed-uri-400', 'csrf-missing', 'csrf-invalid',
    'session-expiry'
  ];
  for (const name of requiredAuth) {
    check(observedAuth.has(name), `application auth assertion coverage: missing ${name}`);
  }
  for (const name of requiredErrors) {
    check(observedErrors.has(name), `application error assertion coverage: missing ${name}`);
  }
  for (const name of requiredState) {
    check(observedState.has(name), `application state assertion coverage: missing ${name}`);
  }

  return {
    mode: 'full',
    authAssertions: observedAuth.size,
    errorAssertions: observedErrors.size,
    stateAssertions: observedState.size,
    browserAssertions: assertions.length,
    stateSnapshots,
    noSecretOutput: true
  };
}

module.exports = { runApplicationSuite, submitCsrfDenial };

if (require.main === module) {
  process.stderr.write('Run through tests/support/browser-application-fixture.sh so the pinned browser and application fixture are active.\n');
  process.exitCode = 64;
}
