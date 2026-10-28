#!/usr/bin/env node
'use strict';
/* global require, console, EventTarget, AbortController, Event */

// Run with node ci/test-browser-auth-diagnostics.cjs. No browser, service,
// credentials or signals: a page event double drives the real diagnostic seam.
const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const { test, mock } = require('node:test');
const { submitCsrfDenial } = require('./test-browser-auth-errors.cjs');

function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

class Page extends EventEmitter {
  constructor() {
    super();
    this.navigation = deferred();
    this.submission = deferred();
    this.calls = [];
    this.evaluations = 0;
    this.setupFailure = false;
    this.cleanupFailure = false;
    this.missingForm = false;
    this.failMessageDuringSetup = false;
    this.setupValue = { forms: 1, disabled: 0, invalidFields: 0 };
  }
  async evaluate() {
    this.evaluations++;
    if (this.evaluations === 1 && this.failMessageDuringSetup) this.emit('console', { text() { throw new Error('private-token'); } });
    if (this.evaluations === 1 && this.setupFailure) throw new Error('private-token setup failure');
    if (this.evaluations > 1 && this.cleanupFailure) throw new Error('private-token cleanup failure');
    return this.evaluations === 1 && !this.missingForm ? this.setupValue : false;
  }
  waitForNavigation(options) {
    this.calls.push(['navigation', options]);
    return this.navigation.promise;
  }
  click(selector) {
    this.calls.push(['click', selector]);
    return this.submission.promise;
  }
}

async function capture(action) {
  const lines = [];
  const spy = mock.method(console, 'error', line => lines.push(line));
  try { return { value: await action(), lines }; }
  finally { spy.mock.restore(); }
}

function diagnostic(lines) {
  assert.equal(lines.length, 1, 'failure emits exactly one diagnostic');
  assert.ok(lines[0].length <= 600, 'diagnostic is bounded');
  return JSON.parse(lines[0].replace(/^CSRF submission diagnostic: /, ''));
}

function clean(page) {
  for (const event of ['request', 'response', 'load', 'console']) {
    assert.equal(page.listenerCount(event), 0, `${event}: temporary listener detached`);
  }
}

test('success retains wait-before-click, original response and silence', async () => {
  const page = new Page();
  const response = {};
  const result = await capture(async () => {
    const pending = submitCsrfDenial(page, 'missing CSRF denial');
    await Promise.resolve();
    assert.deepEqual(page.calls, [
      ['navigation', { waitUntil: 'load', timeout: 15000 }],
      ['click', 'button[type="submit"]']
    ]);
    page.submission.resolve();
    page.navigation.resolve(response);
    return await pending;
  });
  assert.equal(result.value, response);
  assert.deepEqual(result.lines, []);
  clean(page);
});

test('navigation failure names missing scenario and safe observed progress', async () => {
  const page = new Page();
  const failure = new Error('private-token in an original error');
  let forbiddenReads = 0;
  const request = {
    isNavigationRequest: () => true,
    method: () => 'POST',
    url: () => 'http://user:private-token@127.0.0.1/auth/totp?csrf=private-token#private-token',
    headers: () => { forbiddenReads++; throw failure; },
    postData: () => { forbiddenReads++; throw failure; }
  };
  const result = await capture(async () => {
    const pending = submitCsrfDenial(page, 'missing CSRF denial');
    await Promise.resolve();
    page.emit('request', request);
    page.emit('response', { status: () => 200, request: () => request });
    page.emit('load');
    page.submission.resolve();
    await Promise.resolve();
    page.navigation.reject(failure);
    await assert.rejects(pending, error => error === failure);
  });
  const observed = diagnostic(result.lines);
  assert.equal(observed.scenario, 'missing CSRF denial');
  assert.equal(observed.submit, 'complete');
  assert.equal(observed.navigation, 'failed');
  assert.equal(observed.request.method, 'POST');
  assert.equal(observed.request.pathname, '/auth/totp');
  assert.equal(observed.response.status, 200);
  for (const time of [observed.elapsedMs, observed.request.elapsedMs, observed.response.elapsedMs, observed.loadMs]) {
    assert.ok(Number.isInteger(time) && time >= 0);
  }
  assert.equal(forbiddenReads, 0);
  assert.ok(!result.lines[0].includes('private-token'));
  assert.ok(!result.lines[0].includes('http:'));
  clean(page);
});

test('click failure names invalid scenario and keeps missing submission unobserved', async () => {
  const page = new Page();
  const failure = new Error('click failed');
  const result = await capture(async () => {
    const pending = submitCsrfDenial(page, 'invalid CSRF denial');
    await Promise.resolve();
    page.submission.reject(failure);
    await assert.rejects(pending, error => error === failure);
    clean(page);
    // The other Promise.all branch is still owned and handles a later failure.
    page.navigation.reject(new Error('context closed after click failure'));
    await Promise.resolve();
  });
  const observed = diagnostic(result.lines);
  assert.equal(observed.scenario, 'invalid CSRF denial');
  assert.equal(observed.submit, 'failed');
  assert.equal(observed.navigation, 'pending');
  assert.equal(observed.request, null);
  assert.equal(observed.response, null);
  assert.equal(observed.loadMs, null);
});

test('unrelated, malformed and throwing metadata cannot change control flow', async () => {
  const page = new Page();
  const failure = new Error('navigation failed');
  const result = await capture(async () => {
    const pending = submitCsrfDenial(page, 'invalid CSRF denial');
    await Promise.resolve();
    for (const request of [
      { isNavigationRequest: () => false },
      { isNavigationRequest: () => true, method: () => 'GET' },
      { isNavigationRequest: () => true, method: () => 'POST', url: () => '/malformed-private-token' },
      { isNavigationRequest: () => true, method: () => 'POST', url: () => 'http://127.0.0.1/other-private-token' },
      { isNavigationRequest() { throw new Error('private-token'); } }
    ]) assert.doesNotThrow(() => page.emit('request', request));
    assert.doesNotThrow(() => page.emit('response', { status() { throw failure; } }));
    page.navigation.reject(failure);
    await assert.rejects(pending, error => error === failure);
    page.submission.resolve();
  });
  const observed = diagnostic(result.lines);
  assert.equal(observed.request, null);
  assert.equal(observed.response, null);
  assert.ok(!result.lines[0].includes('private-token'));
  clean(page);
});

test('bounded first observations preserve pre-existing listeners and static labels', async () => {
  const page = new Page();
  const existing = () => {};
  page.on('load', existing);
  const request = { isNavigationRequest: () => true, method: () => 'POST', url: () => 'http://127.0.0.1/auth/totp' };
  const result = await capture(async () => {
    const pending = submitCsrfDenial(page, 'private-token');
    await Promise.resolve();
    for (let i = 0; i < 1000; i++) {
      page.emit('request', request);
      page.emit('response', { status: () => 999, request: () => request });
      page.emit('response', { status: () => 200, request: () => ({}) });
      page.emit('response', { status: () => 403, request: () => request });
      page.emit('load');
    }
    page.navigation.reject(new Error('forced failure'));
    await assert.rejects(pending);
    page.submission.resolve();
  });
  const observed = diagnostic(result.lines);
  assert.equal(observed.scenario, 'unknown CSRF denial');
  assert.equal(observed.response.status, 403);
  assert.ok(!result.lines[0].includes('private-token'));
  assert.deepEqual(page.listeners('load'), [existing]);
  page.off('load', existing);
  clean(page);
});

test('failed diagnostic output preserves the original rejection and cleanup', async () => {
  const page = new Page();
  const failure = new Error('original failure');
  const spy = mock.method(console, 'error', () => { throw new Error('sink failed'); });
  try {
    const pending = submitCsrfDenial(page, 'missing CSRF denial');
    await Promise.resolve();
    page.navigation.reject(failure);
    await assert.rejects(pending, error => error === failure);
    page.submission.resolve();
  } finally { spy.mock.restore(); }
  clean(page);
});

test('console observer accepts only bounded fixed numeric events and redacts unrelated messages', async () => {
  const page = new Page();
  const prior = () => {};
  page.on('console', prior);
  const failure = new Error('original failure');
  const result = await capture(async () => {
    const pending = submitCsrfDenial(page, 'invalid CSRF denial');
    await Promise.resolve();
    for (const text of [
      'private-token __VI_CSRF_OBSERVER__:click:1:0:0',
      '__VI_CSRF_OBSERVER__:click:1:0:0?private-token',
      '__VI_CSRF_OBSERVER__:submit:1000:0:0',
      '__VI_CSRF_OBSERVER__:click:1:2:0',
      '__VI_CSRF_OBSERVER__:invalid:1:0:1'
    ]) page.emit('console', { text: () => text });
    for (let i = 0; i < 30; i++) page.emit('console', { text: () => '__VI_CSRF_OBSERVER__:click:1:0:0' });
    page.navigation.reject(failure);
    await assert.rejects(pending, error => error === failure);
    page.submission.resolve();
  });
  const observed = diagnostic(result.lines);
  assert.deepEqual(observed.observer, { status: 'available', events: 16, click: 15, submit: 0, invalid: 1,
    forms: 1, disabled: 0, invalidFields: 0 });
  assert.ok(!result.lines[0].includes('private-token'));
  assert.deepEqual(page.listeners('console'), [prior], 'pre-existing console listener preserved');
  assert.equal(page.evaluations, 2, 'page-side observer cleanup attempted');
  page.off('console', prior);
  clean(page);
});

test('observer setup and cleanup failures preserve original error and report unavailable', async () => {
  const page = new Page();
  page.setupFailure = true;
  page.cleanupFailure = true;
  const failure = new Error('original rejection');
  const result = await capture(async () => {
    const pending = submitCsrfDenial(page, 'invalid CSRF denial');
    await Promise.resolve();
    page.navigation.reject(failure);
    await assert.rejects(pending, error => error === failure);
    page.submission.resolve();
  });
  const observed = diagnostic(result.lines);
  assert.equal(observed.observer.status, 'unavailable');
  assert.equal(observed.observer.events, 0);
  assert.ok(!result.lines[0].includes('private-token'));
  assert.equal(page.evaluations, 2);
  clean(page);
});

test('unreadable console message is unavailable and cannot replace original failure', async () => {
  const page = new Page();
  const failure = new Error('original rejection');
  const result = await capture(async () => {
    const pending = submitCsrfDenial(page, 'invalid CSRF denial');
    await Promise.resolve();
    page.emit('console', { text() { throw new Error('private-token'); } });
    page.navigation.reject(failure);
    await assert.rejects(pending, error => error === failure);
    page.submission.resolve();
  });
  assert.equal(diagnostic(result.lines).observer.status, 'unavailable');
  assert.ok(!result.lines[0].includes('private-token'));
  clean(page);
});

test('missing form and an unreadable early message stay unavailable', async () => {
  for (const mode of ['missingForm', 'failMessageDuringSetup']) {
    const page = new Page();
    page[mode] = true;
    const failure = new Error('original rejection');
    const result = await capture(async () => {
      const pending = submitCsrfDenial(page, 'invalid CSRF denial');
      await Promise.resolve();
      page.navigation.reject(failure);
      await assert.rejects(pending, error => error === failure);
      page.submission.resolve();
    });
    assert.equal(diagnostic(result.lines).observer.status, 'unavailable');
    assert.ok(!result.lines[0].includes('private-token'));
    clean(page);
  }
});

test('page-side listeners abort after success and failure without altering the original result', async () => {
  for (const outcome of ['success', 'failure']) {
    const page = new Page();
    const originalWindow = Object.getOwnPropertyDescriptor(globalThis, 'window');
    const originalDocument = Object.getOwnPropertyDescriptor(globalThis, 'document');
    const form = new EventTarget();
    form.elements = [];
    form.closest = () => form;
    form.querySelector = () => ({ disabled: false });
    Object.defineProperty(globalThis, 'window', { value: { AbortController }, configurable: true, writable: true });
    Object.defineProperty(globalThis, 'document', { value: { querySelector: () => form, querySelectorAll: () => [form] }, configurable: true, writable: true });
    page.evaluate = async fn => fn();
    let emitted = 0;
    const spy = mock.method(console, 'debug', value => {
      emitted++;
      page.emit('console', { text: () => value });
    });
    const failure = new Error('original rejection');
    try {
      const result = await capture(async () => {
        const pending = submitCsrfDenial(page, 'invalid CSRF denial');
        await Promise.resolve();
        form.dispatchEvent(new Event('click'));
        page.submission.resolve();
        if (outcome === 'success') page.navigation.resolve('response');
        else page.navigation.reject(failure);
        if (outcome === 'success') assert.equal(await pending, 'response');
        else await assert.rejects(pending, error => error === failure);
      });
      if (outcome === 'failure') assert.equal(diagnostic(result.lines).observer.click, 1);
      else assert.deepEqual(result.lines, []);
      assert.equal(emitted, 1, 'observer captured the live event');
      form.dispatchEvent(new Event('click'));
      assert.equal(emitted, 1, 'page-side event listeners were removed');
      clean(page);
    } finally {
      spy.mock.restore();
      if (originalWindow) Object.defineProperty(globalThis, 'window', originalWindow);
      else Reflect.deleteProperty(globalThis, 'window');
      if (originalDocument) Object.defineProperty(globalThis, 'document', originalDocument);
      else Reflect.deleteProperty(globalThis, 'document');
    }
  }
});

test('initial numeric form snapshot survives missing click and rejects malformed metadata', async () => {
  for (const valid of [true, false]) {
    const page = new Page();
    page.setupValue = valid ? { forms: 1, disabled: 1, invalidFields: 2 } :
      { forms: 'private-token', disabled: 1, invalidFields: 2 };
    const failure = new Error('original rejection');
    const result = await capture(async () => {
      const pending = submitCsrfDenial(page, 'invalid CSRF denial');
      await Promise.resolve();
      page.navigation.reject(failure);
      await assert.rejects(pending, error => error === failure);
      page.submission.resolve();
    });
    const observed = diagnostic(result.lines).observer;
    assert.equal(observed.status, valid ? 'available' : 'unavailable');
    assert.equal(observed.forms, valid ? 1 : null);
    assert.equal(observed.disabled, valid ? 1 : null);
    assert.equal(observed.invalidFields, valid ? 2 : null);
    assert.ok(!result.lines[0].includes('private-token'));
    clean(page);
  }
});

test('console listener attachment failure is unavailable and leaves original failure intact', async () => {
  const page = new Page();
  const on = page.on.bind(page);
  page.on = (event, listener) => {
    if (event === 'console') throw new Error('private-token');
    return on(event, listener);
  };
  const failure = new Error('original rejection');
  const result = await capture(async () => {
    const pending = submitCsrfDenial(page, 'invalid CSRF denial');
    await Promise.resolve();
    page.navigation.reject(failure);
    await assert.rejects(pending, error => error === failure);
    page.submission.resolve();
  });
  assert.equal(diagnostic(result.lines).observer.status, 'unavailable');
  assert.ok(!result.lines[0].includes('private-token'));
  assert.equal(page.evaluations, 0);
  clean(page);
});
