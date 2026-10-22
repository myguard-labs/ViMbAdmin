#!/usr/bin/env node
'use strict';
/* global require, module, URL, URLSearchParams, Buffer, window, document, setTimeout, clearTimeout */

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const http = require('node:http');

async function runApplicationSuite({ browserContext, targetOrigin, root, input }) {
  assert.equal(input.mode, 'full');
  assert.ok(input.stateDirectory.startsWith(`${root}/`));
  const state = JSON.parse(await fs.readFile(`${input.stateDirectory}/operations-private.json`, 'utf8'));
  const ids = JSON.parse(await fs.readFile(`${input.stateDirectory}/tenant-ids.json`, 'utf8'));
  const cases = [];
  const owned = 'owned.browser.example.test';
  const created = 'operations.browser.example.test';
  const maintenance = ['repair-all', 'recalc-all', 'disable-inactive', 'flush-auth-cache', 'check-update',
    'prune-expired', 'prune-all', 'schema-update', 'scan-orphans', 'backup-orphans'];
  const queue = ['cancel', 'retry', 'delete', 'clear', 'run-now', 'run-task'];
  const methods = ['ping', 'domains.list', 'mailboxes.list', 'aliases.list', 'domain.create', 'domain.delete',
    'mailbox.create', 'mailbox.delete', 'alias.create', 'alias.delete', 'mailbox.archive', 'archive.restore', 'archive.delete'];

  async function verifier(path = '/snapshot?session=tenant_super', action, status = 200, suppliedToken = input.verifierToken) {
    return await new Promise((resolve, reject) => {
      const body = action ? new URLSearchParams({ action }).toString() : null;
      const request = http.request(`${input.verifierOrigin}${path}`, {
        method: body ? 'POST' : 'GET', agent: false,
        headers: { 'X-Vimbadmin-Fixture-Token': suppliedToken,
          ...(body ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) }
      }, response => {
        const chunks = [];
        response.on('data', chunk => chunks.push(chunk));
        response.on('end', () => {
          try {
            assert.equal(response.statusCode, status, 'private operations verifier status');
            const result = JSON.parse(Buffer.concat(chunks).toString('utf8'));
            assert.equal(result.ok, status === 200);
            resolve(result.database || result);
          } catch (error) { reject(error); }
        });
      });
      request.on('error', reject);
      request.setTimeout(10000, () => request.destroy(new Error('operations verifier timed out')));
      request.end(body);
    });
  }

  await verifier('/operations-control', 'fail-alias-update', 403, 'invalid');
  await verifier('/operations-control', undefined, 405);
  await verifier('/operations-control', 'unknown', 400);
  cases.push('private database fault control rejects missing authority, GET and unknown actions');

  async function useRole(role, path) {
    const alias = role === 'anonymous' ? 'anonymous' : `tenant_${role}`;
    const privateState = JSON.parse(await fs.readFile(`${input.stateDirectory}/${alias}.json`, 'utf8'));
    await browserContext.clearCookies();
    await browserContext.addCookies(privateState.cookies);
    const page = await browserContext.newPage();
    page.on('dialog', dialog => dialog.accept());
    await page.goto(`${targetOrigin}${path}`, { waitUntil: 'load', timeout: 15000 });
    return { page, token: privateState.csrfToken || '' };
  }

  async function browserRequest(page, path, values) {
    const method = values === undefined ? 'GET' : 'POST';
    const initial = page.waitForResponse(response => new URL(response.url()).pathname === path &&
      response.request().method() === method, { timeout: 10000 });
    const result = await page.evaluate(async ({ path, values, method }) => {
      const response = await window.fetch(path, { method,
        ...(method === 'POST' ? { body: new URLSearchParams(values) } : {}) });
      const html = await response.text();
      const parsed = new window.DOMParser().parseFromString(html, 'text/html');
      return { status: response.status, path: new URL(response.url).pathname, text: parsed.body.innerText || parsed.body.textContent };
    }, { path, values, method });
    const first = await initial;
    return { ...result, initialStatus: first.status(), location: first.headers().location };
  }

  function formsFor(page, path) {
    // Smarty genUrl produces absolute URLs; the native form renderer also
    // admits relative actions. Both must refer to the same application route.
    return page.locator(`form[action="${targetOrigin}${path}"], form[action="${path}"]`);
  }

  async function submit(page, path, expected, values = {}) {
    const token = await page.locator('input[name="csrf"]').first().inputValue();
    const result = await browserRequest(page, path, { csrf: token, ...values });
    assert.equal(result.status, 200, `${path}: rendered response status`);
    assert.ok(result.text.includes(expected), `${path}: expected rendered message ${expected}`);
    cases.push(`${path}: ${expected}`);
    return result;
  }

  async function clickForm(page, path, expected, id, field = 'id') {
    let form = formsFor(page, path);
    if (id !== undefined) form = form.filter({ has: page.locator(`input[name="${field}"][value="${id}"]`) });
    const confirm = await form.first().getAttribute('data-confirm');
    await Promise.all([page.waitForNavigation({ waitUntil: 'load', timeout: 15000 }), (async () => {
      await form.first().locator('button').click();
      if (confirm) await page.locator('.modal.show [data-oss-confirm]').click();
    })()]);
    assert.ok((await page.locator('body').innerText()).includes(expected), `${path}: rendered native form result`);
    cases.push(`${path}: native form ${expected}`);
  }

  // Every operator mutation is denied to anonymous, assigned and unassigned
  // actors even with a genuine session token. Complete DB hashes are the oracle.
  for (const role of ['anonymous', 'assigned', 'unassigned']) {
    const { page, token } = await useRole(role, '/domain/list');
    for (const family of ['maintenance', 'queue']) {
      const dashboard = await page.goto(`${targetOrigin}/${family}/index`);
      let first = dashboard.request();
      while (first.redirectedFrom()) first = first.redirectedFrom();
      assert.equal((await first.response()).status(), 302, `${role} ${family} dashboard denied status`);
      assert.equal((await first.response()).headers().location, '/auth/login', `${role} ${family} dashboard denial target`);
      cases.push(`${role} ${family} dashboard denial`);
      for (const action of family === 'maintenance' ? maintenance : queue) {
        const before = await verifier();
        const result = await browserRequest(page, `/${family}/${action}`, { csrf: token, confirm: '1', id: String(state.tasks.cancel) });
        assert.equal(result.initialStatus, 302, `${role} ${family}/${action}: authorization redirect`);
        assert.equal(result.location, '/auth/login', `${role} ${family}/${action}: authorization target`);
        assert.equal((await verifier()).sha256, before.sha256, `${role} ${family}/${action}: unauthorized database mutation`);
        cases.push(`${role} ${family}/${action} authorization denial`);
      }
    }
    await page.close();
  }

  let { page, token } = await useRole('super', '/maintenance/index');
  let before;
  await page.locator('h1').filter({ hasText: 'Maintenance' }).waitFor();
  token = await page.locator('input[name="csrf"]').first().inputValue();
  for (const family of ['maintenance', 'queue']) {
    for (const action of family === 'maintenance' ? maintenance : queue) {
      const before = await verifier();
      const result = await browserRequest(page, `/${family}/${action}`, { csrf: 'invalid', confirm: '1', id: String(state.tasks.cancel) });
      assert.ok(result.text.includes('Invalid or missing security token'), `${family}/${action}: explicit CSRF denial`);
      assert.equal((await verifier()).sha256, before.sha256, `${family}/${action}: invalid CSRF mutated database`);
      const missing = await browserRequest(page, `/${family}/${action}`, { confirm: '1', id: String(state.tasks.cancel) });
      assert.ok(missing.text.includes('Invalid or missing security token'), `${family}/${action}: missing CSRF denial`);
      assert.equal((await verifier()).sha256, before.sha256, `${family}/${action}: missing CSRF mutated database`);
      const response = await browserRequest(page, `/${family}/${action}`);
      assert.equal(response.initialStatus, 302, `${family}/${action}: GET rejection`);
      assert.equal(response.location, `/${family}/index`, `${family}/${action}: GET rejection target`);
      assert.equal(response.status, 200, `${family}/${action}: rendered GET rejection`);
      assert.equal((await verifier()).sha256, before.sha256, `${family}/${action}: GET mutated database`);
      cases.push(`${family}/${action} GET and CSRF denial`);
    }
  }

  for (const action of ['repair-all', 'recalc-all', 'prune-expired', 'prune-all', 'backup-orphans']) {
    const before = await verifier();
    const result = await browserRequest(page, `/maintenance/${action}`, { csrf: token, confirm: 'invalid' });
    assert.equal(result.status, 500, `${action}: malformed confirmation is rejected`);
    assert.ok(result.text.includes('Internal server error'), `${action}: bounded application error response`);
    assert.equal((await verifier()).sha256, before.sha256, `${action}: malformed confirmation leaves database unchanged`);
    cases.push(`${action} malformed confirmation rejection`);
  }

  await page.goto(`${targetOrigin}/maintenance/index`);
  const beforeRollback = await verifier();
  assert.equal(beforeRollback.entities.domain.find(row => row.domain === 'inactive.browser.example.test').active, '0',
    'rollback fixture domain is inactive');
  assert.equal(beforeRollback.entities.mailbox.find(row => row.username === 'user@inactive.browser.example.test').active, '1',
    'rollback fixture mailbox starts active');
  const formActions = await page.locator('form[action]').evaluateAll(forms => forms.map(form => form.getAttribute('action')));
  assert.equal(await formsFor(page, '/maintenance/disable-inactive').count(), 1,
    `maintenance disable form rendered at ${new URL(page.url()).pathname}; actions=${JSON.stringify(formActions)}`);
  await formsFor(page, '/maintenance/disable-inactive').locator('button').click();
  await page.locator('.modal.show').getByRole('button', { name: 'Cancel', exact: true }).click();
  await page.locator('.modal [data-oss-confirm]').waitFor({ state: 'detached' });
  assert.equal((await verifier()).sha256, beforeRollback.sha256, 'cancelled confirmation never submits a mutation');
  cases.push('cancelled native operator confirmation preserves database');
  await verifier('/operations-control', 'fail-alias-update');
  await clickForm(page, '/maintenance/disable-inactive', 'Failed to disable inactive-domain accounts:');
  assert.deepEqual((await verifier()).entityDigests, beforeRollback.entityDigests,
    'maintenance rollback preserves complete protected rows');
  cases.push('maintenance transaction rolls back mailbox update when alias update fails');
  await verifier('/operations-control', 'restore-alias-update');
  await clickForm(page, '/maintenance/disable-inactive', 'Disabled 1 mailbox(es) and 1 alias(es)');
  const disabled = await verifier();
  assert.equal(disabled.entities.mailbox.find(row => row.username === 'user@inactive.browser.example.test').active, '0');
  assert.equal(disabled.entities.alias.find(row => row.address === 'alias@inactive.browser.example.test').active, '0');
  assert.equal(disabled.entities.mailbox.find(row => row.username === `user@${owned}`).active, '1');

  for (const [action, type] of [['repair-all', 'REPAIR'], ['recalc-all', 'QUOTA_RECALC']]) {
    const before = await verifier();
    await clickForm(page, `/maintenance/${action}`, 'Yes, queue');
    assert.equal((await verifier()).sha256, before.sha256, `${action}: unconfirmed request mutated database`);
    await clickForm(page, `/maintenance/${action}`, 'for 2 mailbox(es)');
    const tasks = (await verifier()).entities.mailbox_task.filter(row => row.type === type &&
      [`user@${owned}`, 'user@foreign.browser.example.test'].includes(row.username));
    assert.equal(tasks.length, 2, `${action}: exactly both active mailboxes enqueued`);
    const beforeDuplicate = await verifier();
    const stale = await browserRequest(page, `/maintenance/${action}`, { csrf: token, confirm: '1' });
    assert.ok(stale.text.includes('for 0 mailbox(es)'), `${action}: stale confirmation is deduplicated`);
    assert.deepEqual((await verifier()).entityDigests, beforeDuplicate.entityDigests, `${action}: duplicate queue state unchanged`);
    await page.goto(`${targetOrigin}/maintenance/index`);
  }

  for (const action of ['prune-expired', 'prune-all']) {
    const before = await verifier();
    await clickForm(page, `/maintenance/${action}`, 'Yes,');
    assert.equal((await verifier()).sha256, before.sha256, `${action}: preview changed database`);
    await clickForm(page, `/maintenance/${action}`, action === 'prune-all' ? 'Deleted 0' : 'Pruned 0');
    await page.goto(`${targetOrigin}/maintenance/index`);
  }
  before = await verifier();
  assert.ok(!before.mailboxIndexes.some(name => name.toLowerCase() === 'ix_mailbox_active'), 'schema fixture starts without the mapped active index');
  await clickForm(page, '/maintenance/schema-update', 'Review before applying.');
  assert.ok((await page.locator('pre').innerText()).toLowerCase().includes('ix_mailbox_active'), 'schema preview names the missing mapped index');
  assert.equal((await verifier()).sha256, before.sha256, 'schema preview changes no database rows');
  await clickForm(page, '/maintenance/schema-update', 'Schema updated successfully');
  assert.ok((await verifier()).mailboxIndexes.some(name => name.toLowerCase() === 'ix_mailbox_active'), 'confirmed schema update restores the mapped active index');
  await submit(page, '/maintenance/flush-auth-cache', 'Could not flush the Dovecot auth cache:');
  for (const which of ['release', 'commit']) {
    await submit(page, '/maintenance/check-update', 'Could not reach GitHub', { which });
  }
  await submit(page, '/maintenance/backup-orphans', 'Re-scan and use');
  await submit(page, '/maintenance/backup-orphans', 'no longer an unmanaged orphan', { username: 'missing@example.test' });
  await submit(page, '/maintenance/backup-orphans', 'Queued import of 1 unmanaged maildir(s)', { confirm: '1' });
  await page.goto(`${targetOrigin}/maintenance/index`);
  await clickForm(page, '/maintenance/scan-orphans', 'Queued the unmanaged-maildir scan');
  await submit(page, '/maintenance/scan-orphans', 'already queued or running');

  await page.goto(`${targetOrigin}/queue/index`);
  const queuedOrphans = (await verifier()).entities.mailbox_task.filter(row =>
    row.status === 'PENDING' && ['SCAN_ORPHANS', 'BACKUP_ORPHAN'].includes(row.type));
  assert.equal(queuedOrphans.length, 2, 'orphan operations enqueue one scan and one import');
  // Verify their queue entry points without executing a mailbox-import engine
  // against an unavailable storage backend. Queue execution below uses repair.
  for (const task of queuedOrphans) await clickForm(page, '/queue/cancel', 'Task cancelled.', task.id);
  await clickForm(page, '/queue/cancel', 'Task cancelled.', state.tasks.cancel);
  before = await verifier();
  await submit(page, '/queue/cancel', 'not cancellable', { id: String(state.tasks.cancel) });
  assert.equal((await verifier()).sha256, before.sha256, 'stale cancel replay changes no state');
  assert.equal(before.entities.mailbox_task.find(row => Number(row.id) === state.tasks.cancel).status, 'CANCELLED');
  await clickForm(page, '/queue/retry', 'Task re-queued.', state.tasks.retry);
  assert.equal((await verifier()).entities.mailbox_task.find(row => Number(row.id) === state.tasks.retry).status, 'PENDING');
  await clickForm(page, '/queue/delete', 'Task deleted.', state.tasks.delete);
  assert.ok(!(await verifier()).entities.mailbox_task.some(row => Number(row.id) === state.tasks.delete));
  for (const id of ['0', 'bad-id', '999999']) {
    before = await verifier();
    for (const [action, message] of [['cancel', 'not cancellable'], ['retry', 'not safely retryable'],
      ['delete', 'not found'], ['run-task', 'not pending']]) {
      await submit(page, `/queue/${action}`, message, { id });
    }
    assert.equal((await verifier()).sha256, before.sha256, `invalid queue id ${id}: no mutation`);
  }
  await clickForm(page, '/queue/run-task', `Task #${state.tasks.run} failed:`, state.tasks.run);
  const failed = (await verifier()).entities.mailbox_task.find(row => Number(row.id) === state.tasks.run);
  assert.equal(failed.status, 'FAILED');
  assert.ok(failed.finished_at, 'runner publishes a terminal timestamp on dependency failure');
  await clickForm(page, '/queue/clear', 'Cleared 5 finished task(s).');
  await clickForm(page, '/queue/run-now', 'Queue run complete');
  await submit(page, '/queue/retry', 'Task re-queued.', { id: String(state.tasks.retry) });
  assert.equal((await verifier()).entities.mailbox_task.find(row => Number(row.id) === state.tasks.retry).status, 'PENDING',
    'trigger starts with a genuinely pending task');
  async function trigger(key) {
    return await page.evaluate(async key => {
      const response = await window.fetch('/queue/trigger', {
        method: 'POST', headers: key ? { Authorization: `Bearer ${key}` } : {}
      });
      return { status: response.status, value: await response.json() };
    }, key);
  }
  before = await verifier();
  assert.deepEqual(await trigger(''), { status: 401, value: { error: 'missing bearer' } });
  assert.deepEqual(await trigger('invalid'), { status: 403, value: { error: 'bad key' } });
  assert.equal((await verifier()).sha256, before.sha256, 'unauthorized queue trigger never drains tasks');
  assert.deepEqual(await trigger(state.queueKey), { status: 200, value: { triggered: true } });
  // Wait for the real afterSend callback by issuing another request to the
  // fixture's single application worker before examining terminal state.
  await page.goto(`${targetOrigin}/queue/index`);
  assert.equal((await verifier()).entities.mailbox_task.find(row => Number(row.id) === state.tasks.retry).status, 'FAILED',
    'authorized trigger actually processes its pending repair task');
  assert.ok((await verifier()).entities.mailbox_task.every(row => !['PENDING', 'RUNNING'].includes(row.status)),
    'authorized trigger drains remaining tasks and publishes dependency failures');
  cases.push('queue trigger missing/bad bearer denials and authorized deferred drain');

  async function rpc(method, params = {}, bearer = 'operator', raw) {
    return await page.evaluate(async ({ method, params, bearer, raw }) => {
      const response = await window.fetch('/mcp/index', { method: 'POST', headers: {
        'Content-Type': 'application/json', ...(bearer ? { Authorization: `Bearer ${bearer}` } : {})
      }, body: raw === undefined ? JSON.stringify({ jsonrpc: '2.0', id: 7, method, params }) : raw });
      return { status: response.status, value: await response.json() };
    }, { method, params, bearer: bearer ? state.tokens[bearer] : '', raw });
  }
  async function rpcSuccess(method, params = {}, bearer = 'operator') {
    const response = await rpc(method, params, bearer);
    assert.equal(response.status, 200, `${method}: RPC status`);
    assert.equal(response.value.id, 7, `${method}: request identity`);
    assert.ok(response.value.result, `${method}: successful real handler result`);
    assert.equal(response.value.error, undefined, `${method}: no RPC error`);
    cases.push(`mcp ${method} successful handler`);
    return response.value.result;
  }
  // Session authorization must never substitute for a bearer on any handler.
  for (const method of methods) {
    before = await verifier();
    const response = await rpc(method, {}, null);
    assert.equal(response.status, 401, `${method}: missing bearer denied before handler`);
    assert.equal(response.value.error.message, 'missing or malformed Authorization: Bearer header');
    assert.equal((await verifier()).sha256, before.sha256, `${method}: missing bearer mutated database`);
    cases.push(`mcp ${method} missing bearer denial`);
  }
  for (const method of methods.filter(name => !['ping', 'domains.list', 'mailboxes.list', 'aliases.list'].includes(name))) {
    before = await verifier();
    const response = await rpc(method, {}, 'reader');
    assert.equal(response.status, 403, `${method}: read-only bearer denied before handler`);
    assert.equal(response.value.error.message, "token lacks required scope 'write'");
    assert.equal((await verifier()).sha256, before.sha256, `${method}: read-only bearer mutated database`);
    cases.push(`mcp ${method} read-only bearer denial`);
  }
  for (const [bearer, message] of [['reader', "token lacks required scope 'write'"],
    ['denied_ip', 'source IP 127.0.0.1 not allowed for this token'],
    ['revoked', 'token revoked or expired'], ['expired', 'token revoked or expired']]) {
    before = await verifier();
    const response = await rpc('domain.create', { domain: created }, bearer);
    assert.equal(response.status, 403);
    assert.equal(response.value.error.message, message);
    assert.equal((await verifier()).sha256, before.sha256, `${bearer}: denied mutation changed database`);
    cases.push(`mcp ${bearer} denial`);
  }
  before = await verifier();
  assert.equal((await rpc('domain.create', { domain: created }, 'unknown')).status, 401);
  assert.equal((await verifier()).sha256, before.sha256, 'unknown bearer cannot mutate database');
  assert.equal((await rpc('ping', {}, 'operator', '{')).value.error.code, -32700);
  assert.equal((await rpc('unknown')).value.error.code, -32601);
  assert.equal((await rpc('domains.list', { limit: 0 })).value.error.code, -32602);
  before = await verifier();
  assert.equal((await rpc('mailboxes.list', { domain: 'foreign.browser.example.test' })).value.error.code, -32010);
  assert.deepEqual((await verifier()).entityDigests, before.entityDigests, 'MCP foreign domain rejected without protected mutation');
  assert.equal((await rpcSuccess('ping', {}, 'reader')).pong, true);
  const domains = await rpcSuccess('domains.list', { limit: 1 });
  assert.deepEqual(domains.domains.map(row => row.domain), [owned]);
  assert.deepEqual((await rpcSuccess('mailboxes.list', { domain: owned })).mailboxes.map(row => row.username),
    [`restore@${owned}`, `user@${owned}`], 'read ability lists only the allowed domain, including its inactive restore witness');
  await rpcSuccess('aliases.list', { domain: owned });
  await rpcSuccess('domain.create', { domain: created });
  assert.equal((await rpc('domain.create', { domain: created })).value.error.code, -32010);
  await rpcSuccess('mailbox.create', { domain: created, local_part: 'operator', password: state.password });
  assert.ok((await verifier()).entities.mailbox.some(row => row.username === `operator@${created}` && row.active === '1'));
  await rpcSuccess('alias.create', { domain: created, address: 'forward', goto: `operator@${created}` });
  assert.ok((await verifier()).entities.alias.some(row => row.address === `forward@${created}`));
  await rpcSuccess('alias.delete', { address: `forward@${created}` });
  assert.ok(!(await verifier()).entities.alias.some(row => row.address === `forward@${created}`), 'alias.delete removes the exact alias');
  const archived = await rpcSuccess('mailbox.archive', { username: `operator@${created}` });
  assert.equal(archived.queued, 'ARCHIVE');
  const beforeArchiveDuplicate = await verifier();
  assert.equal(beforeArchiveDuplicate.entities.mailbox_task.filter(row => row.type === 'ARCHIVE' &&
    row.username === `operator@${created}` && row.status === 'PENDING').length, 1, 'mailbox.archive creates one pending task');
  const duplicate = await rpcSuccess('mailbox.archive', { username: `operator@${created}` });
  assert.equal(duplicate.already_queued, true);
  assert.deepEqual((await verifier()).entityDigests, beforeArchiveDuplicate.entityDigests, 'duplicate archive request preserves protected rows');
  for (const method of ['archive.restore', 'archive.delete']) {
    before = await verifier();
    assert.equal((await rpc(method, { username: `missing@${created}` })).value.error.code, -32010);
    assert.deepEqual((await verifier()).entityDigests, before.entityDigests, `${method}: unknown archive no partial mutation`);
    cases.push(`mcp ${method} unknown archive error`);
  }
  before = await verifier();
  const fencedRestore = await rpc('archive.restore', { username: `user@${owned}` });
  assert.equal(fencedRestore.value.error.code, -32603, 'archive restore respects the failed backend resource fence');
  assert.deepEqual((await verifier()).entityDigests, before.entityDigests, 'fenced restore preserves all protected rows');
  cases.push('MCP restore rejects a resource retained after backend failure');
  await rpcSuccess('archive.restore', { username: `restore@${owned}` });
  const restoredArchive = await verifier();
  assert.ok(!restoredArchive.entities.archive.some(row => row.username === `restore@${owned}`), 'archive.restore consumes its exact snapshot');
  assert.equal(restoredArchive.entities.mailbox.find(row => row.username === `restore@${owned}`).active, '0',
    'restoring an existing mailbox preserves its inactive state');
  await rpcSuccess('archive.delete', { username: `delete@${owned}` });
  assert.ok(!(await verifier()).entities.archive.some(row => row.username === `delete@${owned}`), 'archive.delete removes its exact snapshot');
  before = await verifier();
  assert.equal((await rpc('archive.restore', { username: `broken@${owned}` })).value.error.code, -32010);
  assert.deepEqual((await verifier()).entityDigests, before.entityDigests, 'invalid archive snapshot preserves complete protected rows');
  // Drop the queued archive before the synchronous purge path can conflict.
  const archiveTask = (await verifier()).entities.mailbox_task.find(row => row.username === `operator@${created}`);
  await submit(page, '/queue/delete', 'Task deleted.', { id: archiveTask.id });
  await rpcSuccess('mailbox.delete', { username: `operator@${created}` });
  assert.ok(!(await verifier()).entities.mailbox.some(row => row.username === `operator@${created}`), 'mailbox.delete removes the exact mailbox');
  await rpcSuccess('domain.delete', { domain: created });
  assert.ok(!(await verifier()).entities.domain.some(row => row.domain === created));
  before = await verifier();
  assert.equal((await rpc('archive.delete', { username: `missing@${created}` })).status, 429);
  assert.deepEqual((await verifier()).entityDigests, before.entityDigests, 'destructive rate boundary prevents protected mutation');
  cases.push('MCP destructive rate limit allows tenth request and rejects eleventh');
  await page.close();

  // The three mailbox-maintenance forms are the assigned domain operator's
  // queue entry points. Hostile posts carry a real token, so roles reject them.
  for (const role of ['assigned', 'unassigned', 'super']) {
    ({ page, token } = await useRole(role, '/mailbox/list/unset/1'));
    await page.waitForFunction(() => window.oDataTable?.ajax.json() !== undefined);
    for (const [action, type] of [['queue-repair', 'REPAIR'], ['queue-archive', 'ARCHIVE'], ['queue-delete', 'DELETE']]) {
      before = await verifier();
      const denied = await browserRequest(page, `/mailbox/${action}`, { csrf: token, mid: String(ids.mailbox.foreign) });
      if (role !== 'super') {
        assert.equal(denied.path, '/mailbox/list');
        assert.equal((await verifier()).sha256, before.sha256, `${role} ${action}: foreign mailbox denied`);
      }
      if (role !== 'unassigned') {
        await page.goto(`${targetOrigin}/mailbox/list/unset/1`);
        await page.waitForFunction(() => window.oDataTable?.ajax.json() !== undefined);
        await clickForm(page, `/mailbox/${action}`, 'queued', ids.mailbox.owned, 'mid');
        const tasks = (await verifier()).entities.mailbox_task.filter(row => row.type === type && row.username === `user@${owned}` &&
          ['PENDING', 'RUNNING'].includes(row.status));
        assert.equal(tasks.length, 1, `${role} ${action}: one deduplicated owned task`);
      } else {
        before = await verifier();
        await browserRequest(page, `/mailbox/${action}`, { csrf: token, mid: String(ids.mailbox.owned) });
        assert.equal((await verifier()).sha256, before.sha256, `unassigned ${action}: owned mailbox denied`);
      }
      cases.push(`${role} mailbox ${action} admission and isolation`);
    }
    await page.close();
  }

  // Hold a real application's older modal response, render a newer one, then
  // deliver the older bytes. No route is fulfilled with synthetic application HTML.
  const stalePage = (await useRole('super', '/mailbox/list/unset/1')).page;
  await stalePage.waitForFunction(() => window.oDataTable?.ajax.json() !== undefined);
  let release;
  let captured;
  const gate = new Promise(resolve => { release = resolve; });
  const ready = new Promise(resolve => { captured = resolve; });
  const oldPath = `/mailbox/email-settings/mid/${ids.mailbox.owned}`;
  await stalePage.route(url => url.pathname === oldPath, async route => {
    const response = await route.fetch();
    captured();
    await gate;
    await route.fulfill({ response });
  });
  before = await verifier();
  await stalePage.locator(`#modal-dialog-mailbox_settings_${ids.mailbox.owned}`).click();
  let timer;
  try {
    await Promise.race([ready, new Promise((resolve, reject) => {
      timer = setTimeout(() => reject(new Error('old operator modal response was not captured')), 10000);
    })]);
  } finally { clearTimeout(timer); }
  await stalePage.evaluate(id => document.getElementById(`modal-dialog-mailbox_settings_${id}`).click(), ids.mailbox.foreign);
  await stalePage.locator('#modal_dialog').getByText('user@foreign.browser.example.test', { exact: true }).first().waitFor();
  const oldResponse = stalePage.waitForResponse(response => new URL(response.url()).pathname === oldPath);
  release();
  await (await oldResponse).finished();
  await stalePage.evaluate(() => new Promise(resolve => window.requestAnimationFrame(() => window.requestAnimationFrame(resolve))));
  const modal = await stalePage.locator('#modal_dialog').innerText();
  assert.ok(modal.includes('user@foreign.browser.example.test') && !modal.includes(`user@${owned}`),
    'older operator modal response cannot replace the current mailbox');
  assert.equal((await verifier()).sha256, before.sha256, 'stale modal reads never mutate database');
  cases.push('real stale operator modal response rejected after newer mailbox response');
  await stalePage.close();
  return { scope: 'maintenance/queue/MCP operator application routes', cases,
    rollbackControl: 'real MariaDB alias update failure after mailbox update', noExternalDoveadm: true };
}

module.exports = { runApplicationSuite };
