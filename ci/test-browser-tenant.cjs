#!/usr/bin/env node
'use strict';
/* global require, module, URL, URLSearchParams, Buffer, document, window, setTimeout, clearTimeout */

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const http = require('node:http');

function snapshot(input, role) {
  return new Promise((resolve, reject) => {
    const request = http.get(`${input.verifierOrigin}/snapshot?session=tenant_${role}`, {
      agent: false, headers: { 'X-Vimbadmin-Fixture-Token': input.verifierToken }
    }, response => {
      const chunks = [];
      response.on('data', chunk => chunks.push(chunk));
      response.on('end', () => {
        try {
          assert.equal(response.statusCode, 200, 'tenant verifier HTTP status');
          const value = JSON.parse(Buffer.concat(chunks).toString('utf8'));
          assert.equal(value.ok, true, 'tenant verifier snapshot');
          assert.ok(value.database.entities, 'tenant verifier entity projection');
          resolve(value.database);
        } catch (error) { reject(error); }
      });
    });
    request.on('error', reject);
    request.setTimeout(10000, () => request.destroy(new Error('tenant verifier timed out')));
  });
}

async function runApplicationSuite({ browserContext, targetOrigin, root, input }) {
  assert.equal(input.mode, 'full', 'tenant suite mode');
  assert.ok(typeof input.verifierToken === 'string' && /^[a-f0-9]{64}$/.test(input.verifierToken), 'tenant verifier token');
  assert.ok(input.stateDirectory.startsWith(`${root}/`), 'tenant state root');
  const ids = JSON.parse(await fs.readFile(`${input.stateDirectory}/tenant-ids.json`, 'utf8'));
  const cases = [];
  const changes = [];
  const csrfTokens = new WeakMap();
  const owned = 'owned.browser.example.test';
  const foreign = 'foreign.browser.example.test';

  async function rolePage(role, path) {
    const state = JSON.parse(await fs.readFile(`${input.stateDirectory}/tenant_${role}.json`, 'utf8'));
    await browserContext.clearCookies();
    await browserContext.addCookies(state.cookies);
    const page = await browserContext.newPage();
    csrfTokens.set(page, state.csrfToken);
    const response = await page.goto(new URL(path, targetOrigin).href, { waitUntil: 'load', timeout: 15000 });
    assert.equal(response.status(), 200, `${role} ${path}: rendered HTTP status`);
    return page;
  }

  async function table(page, names, absent, name) {
    await page.waitForFunction(() => typeof window.oDataTable === 'object' &&
      document.querySelector('#list_table tbody') &&
      !document.querySelector('#list_table tbody')?.innerText.includes('Loading'), null, { timeout: 10000 });
    for (const text of names) {
      await page.locator('#list_table tbody').getByText(text, { exact: true }).waitFor({ timeout: 10000 });
    }
    // The server-side table starts empty; wait for its real transport to finish
    // before treating absence as an authorization oracle.
    await page.waitForFunction(() => window.oDataTable?.ajax.json() !== undefined, null, { timeout: 10000 });
    const text = await page.locator('#list_table tbody').innerText();
    for (const value of absent) assert.ok(!text.includes(value), `${name}: foreign row leaked`);
    cases.push(name);
  }

  async function csrf(page) {
    const inputs = page.locator('input[name="csrf"]');
    // Empty authorized lists have no mutation forms. Use the private seeded
    // token for hostile POSTs so authorization, rather than CSRF, rejects them.
    const value = await inputs.count() ? await inputs.first().inputValue() : csrfTokens.get(page);
    assert.ok(typeof value === 'string' && /^[a-f0-9]{64}$/.test(value), 'rendered CSRF token');
    return value;
  }

  async function post(page, path, values) {
    // Native browser transport with the current role cookie and rendered token.
    // Tokens never enter diagnostics or Playwright traces.
    const firstResponse = page.waitForResponse(response => new URL(response.url()).pathname === path &&
      response.request().method() === 'POST', { timeout: 10000 });
    const result = await page.evaluate(async ({ path, values }) => {
      const response = await window.fetch(path, {
        method: 'POST', body: new URLSearchParams(values), redirect: 'follow'
      });
      const text = await response.text();
      return { status: response.status, text: text === 'ok' || text === 'ko' ? text : 'rendered-page',
        path: new URL(response.url).pathname };
    }, { path, values });
    const first = await firstResponse;
    return { ...result, initialStatus: first.status(), location: first.headers().location };
  }

  async function denial(page, role, family, key, id, name, token) {
    const before = await snapshot(input, role);
    const result = await post(page, `/${family}/ajax-toggle-active`, { [key]: String(id), csrf: token });
    assert.equal(result.status, 200, `${name}: denial HTTP status`);
    assert.equal(result.text, 'ko', `${name}: expected exact denial ko`);
    const after = await snapshot(input, role);
    assert.equal(after.sha256, before.sha256, `${name}: denied request mutated database`);
    cases.push(name);
  }

  async function editRoute(page, role, family, key, id, allowed) {
    const name = `${role} ${family} edit ${id}`;
    const before = await snapshot(input, role);
    const response = await page.goto(`${targetOrigin}/${family}/edit/${key}/${id}`, { waitUntil: 'load', timeout: 15000 });
    assert.equal(response.status(), 200, `${name}: rendered HTTP status`);
    if (allowed) {
      await page.locator(`form[action="/${family}/edit/${key}/${id}"]`).waitFor();
    } else {
      let request = response.request();
      while (request.redirectedFrom()) request = request.redirectedFrom();
      const first = await request.response();
      assert.equal(first.status(), 302, `${name}: denial redirect status`);
      assert.equal(first.headers().location, family === 'domain' ? '/auth/login' : '/mailbox/list',
        `${name}: exact denial redirect`);
      assert.equal(await page.locator(`form[action="/${family}/edit/${key}/${id}"]`).count(), 0,
        `${name}: denied form is absent`);
      if (family === 'mailbox') {
        assert.ok((await page.locator('body').innerText()).includes('Mailbox not found.'), `${name}: exact denial message`);
      }
    }
    assert.equal((await snapshot(input, role)).sha256, before.sha256, `${name}: GET/denial database unchanged`);
    cases.push(name);
  }

  async function allowedToggle(page, role, family, key, id, token, click = true) {
    const name = `${role} ${family} allowed toggle`;
    const before = await snapshot(input, role);
    if (click) {
      const responsePromise = page.waitForResponse(response =>
        new URL(response.url()).pathname === `/${family}/ajax-toggle-active` && response.request().method() === 'POST');
      await page.locator(`#toggle-active-${id}`).click();
      const response = await responsePromise;
      assert.equal(response.status(), 200, `${name}: HTTP status`);
      assert.equal(await response.text(), 'ok', `${name}: exact success response`);
      await page.waitForFunction(id => document.getElementById(`toggle-active-${id}`)?.textContent.trim() === 'No', id);
    } else {
      assert.equal((await post(page, `/${family}/ajax-toggle-active`, { [key]: String(id), csrf: token })).text,
        'ok', `${name}: exact success response`);
    }
    const after = await snapshot(input, role);
    const expected = JSON.parse(JSON.stringify(before.entities));
    const target = expected[family].find(row => Number(row.id) === id);
    assert.ok(target, `${name}: target fixture row`);
    assert.equal(target.active, '1', `${name}: baseline active`);
    target.active = '0';
    assert.deepEqual(after.entities, expected, `${name}: exact target change without cross-tenant mutation`);
    // Hashes cover every column, including fields deliberately omitted from
    // the safe projection. Only the target entity's digest may change.
    const beforeCanaries = JSON.parse(JSON.stringify(before.entityDigests));
    const afterCanaries = JSON.parse(JSON.stringify(after.entityDigests));
    assert.notEqual(afterCanaries[family][id], beforeCanaries[family][id], `${name}: complete target row changed`);
    delete beforeCanaries[family][id];
    delete afterCanaries[family][id];
    assert.deepEqual(afterCanaries, beforeCanaries, `${name}: all other entity columns preserved`);
    assert.notEqual(after.sha256, before.sha256, `${name}: database mutation observed`);
    changes.push({ name, id, before: before.sha256, after: after.sha256 });
    assert.equal((await post(page, `/${family}/ajax-toggle-active`, { [key]: String(id), csrf: token })).text,
      'ok', `${name}: restore fixture state`);
    assert.deepEqual((await snapshot(input, role)).entities, before.entities, `${name}: flags restored`);
    cases.push(name);
  }

  for (const role of ['assigned', 'super', 'unassigned']) {
    let page = await rolePage(role, '/domain/list');
    await table(page, role === 'super' ? [owned, foreign] : role === 'assigned' ? [owned] : [],
      role === 'super' ? [] : role === 'assigned' ? [foreign] : [owned, foreign], `${role} domain list scope`);
    let token = await csrf(page);
    if (role !== 'super') {
      // Run before any allowed mutation: the controlled authorization mutant
      // must fail this exact assertion with a genuine cross-tenant request.
      await denial(page, role, 'domain', 'did', ids.domain.foreign, `${role} cross-tenant domain toggle`, token);
    }
    if (role !== 'unassigned') {
      await allowedToggle(page, role, 'domain', 'did', ids.domain.owned, token);
      if (role === 'super') await allowedToggle(page, role, 'domain', 'did', ids.domain.foreign, token);
    } else {
      await denial(page, role, 'domain', 'did', ids.domain.owned, 'unassigned owned-domain toggle', token);
    }
    for (const [id, name] of [[0, 'zero'], ['not-an-id', 'malformed'], [999999, 'missing']]) {
      await denial(page, role, 'domain', 'did', id, `${role} ${name} domain id`, token);
    }
    await denial(page, role, 'domain', 'did', ids.domain.owned, `${role} invalid domain CSRF`, 'invalid');
    await editRoute(page, role, 'domain', 'did', ids.domain.owned, role === 'super');
    await editRoute(page, role, 'domain', 'did', ids.domain.foreign, role === 'super');
    await page.close();

    page = await rolePage(role, '/mailbox/list/unset/1');
    await table(page, role === 'super' ? [`user@${owned}`, `user@${foreign}`] : role === 'assigned' ? [`user@${owned}`] : [],
      role === 'super' ? [] : role === 'assigned' ? [foreign] : [owned, foreign], `${role} mailbox list scope`);
    token = await csrf(page);
    if (role !== 'super') {
      await denial(page, role, 'mailbox', 'mid', ids.mailbox.foreign, `${role} cross-tenant mailbox toggle`, token);
    }
    if (role !== 'unassigned') {
      await allowedToggle(page, role, 'mailbox', 'mid', ids.mailbox.owned, token);
      if (role === 'super') await allowedToggle(page, role, 'mailbox', 'mid', ids.mailbox.foreign, token);
    } else {
      await denial(page, role, 'mailbox', 'mid', ids.mailbox.owned, 'unassigned owned-mailbox toggle', token);
    }
    for (const [id, name] of [[0, 'zero'], ['not-an-id', 'malformed'], [999999, 'missing']]) {
      await denial(page, role, 'mailbox', 'mid', id, `${role} ${name} mailbox id`, token);
    }
    await denial(page, role, 'mailbox', 'mid', ids.mailbox.owned, `${role} invalid mailbox CSRF`, 'invalid');
    await editRoute(page, role, 'mailbox', 'mid', ids.mailbox.owned, role !== 'unassigned');
    await editRoute(page, role, 'mailbox', 'mid', ids.mailbox.foreign, role === 'super');
    await page.close();

    page = await rolePage(role, '/admin/list');
    if (role === 'super') {
      await page.locator(`#toggle-active-${ids.admin.target}`).waitFor();
      token = await csrf(page);
      await allowedToggle(page, role, 'admin', 'aid', ids.admin.target, token);
      await denial(page, role, 'admin', 'aid', ids.admin.super, 'super self-toggle denial', token);
    } else {
      // Existing semantics redirect through auth/login and the authenticated
      // landing page. Inspect the first response, not just the final HTTP 200.
      const before = await snapshot(input, role);
      const response = await page.goto(`${targetOrigin}/admin/list`);
      let request = response.request();
      while (request.redirectedFrom()) request = request.redirectedFrom();
      const first = await request.response();
      assert.equal(first.status(), 302, `${role} admin route denied`);
      assert.equal(first.headers().location, '/auth/login', `${role} exact admin denial redirect`);
      assert.equal(await page.locator('a[href="/admin/list"]').count(), 0, `${role} admin menu hidden`);
      const result = await post(page, '/admin/ajax-toggle-active', { aid: String(ids.admin.target), csrf: token });
      assert.equal(result.initialStatus, 302, `${role} admin mutation denial status`);
      assert.equal(result.location, '/auth/login', `${role} exact admin mutation denial redirect`);
      assert.equal(result.text, 'rendered-page', `${role} admin mutation denied`);
      assert.equal((await snapshot(input, role)).sha256, before.sha256, `${role} admin denial database unchanged`);
      cases.push(`${role} admin list and mutation denied`);
    }
    await page.close();
  }

  // Hold an actual server response while a newer real search finishes. The
  // old response contains both tenant rows; it must not replace the new row.
  const page = await rolePage('super', '/domain/list');
  await table(page, [owned, foreign], [], 'stale-response baseline');
  let release;
  let held;
  const ready = new Promise(resolve => { held = resolve; });
  const gate = new Promise(resolve => { release = resolve; });
  await page.route('**/domain/list-data?**', async route => {
    const url = new URL(route.request().url());
    if (url.searchParams.get('search[value]') !== '') return route.continue();
    const response = await route.fetch();
    held();
    await gate;
    await route.fulfill({ response });
  });
  await page.evaluate(() => window.oDataTable.search('').draw());
  let readyTimer;
  try {
    await Promise.race([ready, new Promise((resolve, reject) => {
      readyTimer = setTimeout(() => reject(new Error('stale response control did not capture a real response')), 10000);
    })]);
  } finally {
    clearTimeout(readyTimer);
  }
  await page.evaluate(() => window.oDataTable.search('owned').draw());
  await page.waitForFunction(() => {
    const rows = document.querySelector('#list_table tbody').innerText;
    return rows.includes('owned.browser.example.test') && !rows.includes('foreign.browser.example.test');
  });
  const oldResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/domain/list-data' &&
    new URL(response.url()).searchParams.get('search[value]') === '');
  release();
  await (await oldResponse).finished();
  // Queue two animation frames after the response callback to inspect settled DOM.
  await page.evaluate(() => new Promise(resolve => window.requestAnimationFrame(() => window.requestAnimationFrame(resolve))));
  const text = await page.locator('#list_table tbody').innerText();
  assert.ok(text.includes(owned) && !text.includes(foreign), 'stale domain response replaced newer tenant search');
  cases.push('stale domain response rejected after newer search');
  await page.close();
  return { scope: 'admin/domain/mailbox lists and active toggles; domain/mailbox edit form access only', cases, changes,
    staleResponseControl: true, noSecretOutput: true };
}

module.exports = { runApplicationSuite };
