#!/usr/bin/env node
'use strict';
/* global require, console */

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
  for (const event of ['request', 'response', 'load']) {
    assert.equal(page.listenerCount(event), 0, `${event}: temporary listener detached`);
  }
}

test('success retains wait-before-click, original response and silence', async () => {
  const page = new Page();
  const response = {};
  const result = await capture(async () => {
    const pending = submitCsrfDenial(page, 'missing CSRF denial');
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
    page.navigation.reject(failure);
    await assert.rejects(pending, error => error === failure);
    page.submission.resolve();
  } finally { spy.mock.restore(); }
  clean(page);
});
