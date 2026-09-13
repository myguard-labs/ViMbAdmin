#!/usr/bin/env node
'use strict';
/* global require, console, __dirname */

// Execute the actual legacy search response callbacks with a counting Api
// factory. DOM formatting is stubbed; row order, content and draw sequencing
// remain observable. No browser, network or application state is required.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Isolate the actual transport factory; keep the accepted wire value observable.
const transportSource = fs.readFileSync(path.join(__dirname, '../public/js/990-vimbadmin.js'), 'utf8');
const transportMatch = transportSource.match(/function vmDataTableServerData\([^]*?\n}/);
assert.ok(transportMatch, 'transport factory must be present');
for (const [search, allowed] of [
  ['*ab\u00a0', true], ['*ab\f', true], ['\u00a0ab', true], ['\fab', true],
  ['*\u00a0ab', true], ['*\fab', true], ['*ab\0', false], ['\0*ab', false],
  ['*ab', false], ['* ab', false], ['*😀a', false], ['*😀ab', true],
  ['*', true], [' \t\n\r\0\v*abc \t\n\r\0\v', true]
].concat([' ', '\t', '\n', '\r', '\0', '\v'].flatMap(space => [
  [space + '*ab', false], ['*ab' + space, false], [space + '*abc' + space, true]
]))) {
  let requested = null;
  let answered = null;
  const factory = vm.runInNewContext(`(${transportMatch[0]})`, {
    DataTable: { Api: function() {} }, ossAjax: options => { requested = options.data; }
  });
  factory('/unused', 3)({ draw: 11, search: { value: search } }, result => { answered = result; }, {
    language: { zeroRecords: 'None', emptyTable: 'Empty' }
  });
  assert.equal(requested !== null, allowed, `PHP trim boundary ${JSON.stringify(search)}: request admission`);
  if (allowed) {
    assert.equal(requested.search.value, search, 'accepted raw request is preserved');
    assert.equal(answered, null);
  } else {
    assert.equal(answered.recordsFiltered, 0);
  }
}
console.log('OK: transport PHP trim boundaries and raw search preservation');

for (const view of ['domain', 'alias', 'mailbox']) {
  const source = fs.readFileSync(path.join(__dirname, `../application/views/${view}/js/list.js`), 'utf8');
  const match = source.match(/success:\s*function\(data\)\{([\s\S]*?)\n\s*}\n\s*}\);/);
  assert.ok(match, `${view}: response callback must be present`);
  // Include the optional domain quota column and use its unit multiplier 1.
  const body = match[1].replace(/\{if [^}]*}|\{\/if}/g, '').replace(/\{\$multiplier}/g, '1');
  for (const count of [0, 1, 3]) {
    let wrappers = 0;
    const events = [];
    const table = {};
    const format = (...args) => args.join(':');
    const callback = vm.runInNewContext(`(function(data) {${body}\n})`, {
      oDataTable: table,
      vmDataTableApi: selected => {
        assert.equal(selected, table);
        const wrapper = ++wrappers;
        return {
          row: { add: values => events.push({ type: 'row', wrapper, values: Array.from(values) }) },
          draw: () => events.push({ type: 'draw', wrapper })
        };
      },
      formatMailboxes: format, formatAliases: format, formatQuotaLimit: format,
      formatActive: format, formatControlls: format, formatGoto: format,
      formatUsedQuota: format, formatLastLogin: format
    });
    const rows = Array.from({ length: count }, (_, index) => ({
      id: index + 1, name: `name-${index}`, username: `user-${index}`,
      address: `address-${index}`, domain: `domain-${index}`, active: true,
      goto: `destination-${index}`, quota_bytes: 1024, quota: 2,
      last_login: '2026-09-01', mailboxes: 3, maxmailboxes: 4, aliases: 5,
      maxaliases: 6, mailboxes_size: 7, maxquota: 8, transport: 'local',
      backupmx: false, created: { date: '2026-09-01 12:00:00' }
    }));
    callback(JSON.stringify(rows));
    assert.equal(wrappers, 1, `${view}/${count}: one Api wrapper per response`);
    assert.equal(events.length, count + 1, `${view}/${count}: add every row then draw once`);
    rows.forEach((row, index) => {
      const expected = view === 'alias'
        ? [row.address, row.domain, format(row.id, row.active), format(row.id, row.goto), format(row.id)]
        : view === 'mailbox'
          ? [row.username, row.name, format(row.id, row.quota_bytes, row.quota), row.last_login,
            row.domain, format(row.id, row.active), format(row.id)]
          : [row.name, format(row.id, 3, 4), format(row.id, 5, 6), '7.0 / 8', '2',
            format(row.id, row.active), 'local', 'No', '2026-09-01', format(row.id, row.name)];
      assert.deepEqual(events[index], { type: 'row', wrapper: 1, values: expected }, `${view}/${index}: row data/order`);
    });
    assert.deepEqual(events[count], { type: 'draw', wrapper: 1 }, `${view}: draw after all rows`);
    for (const rejected of ['ko', 'unexpected response']) {
      callback(rejected);
      assert.equal(wrappers, 1, `${view}: rejected response allocates no wrapper`);
      assert.equal(events.length, count + 1, `${view}: rejected response changes no rows`);
    }
  }
  console.log(`OK: ${view} response reuses one Api; empty/multiple rows and rejected responses`);
}
