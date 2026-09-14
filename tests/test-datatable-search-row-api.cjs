#!/usr/bin/env node
'use strict';
/* global require, console, __dirname */

// Exercise the active server-side DataTables transport's search boundary and
// pin each list view to its native transport factory and list-data endpoint.
// No browser, network or application state is required.
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

const legacyToken = /(?:^|[^A-Za-z0-9_$])getEntries(?:[^A-Za-z0-9_$]|$)|(?:^|[^A-Za-z0-9_-])list-search(?:[^A-Za-z0-9_-]|$)/;
for (const mutant of [
  'const getEntries = function () {};',
  'const getEntries = () => {};',
  'const config = { endpoint: "/domain/list-search" };'
]) {
  assert.match(mutant, legacyToken, `negative control must detect ${mutant}`);
}
for (const nearMiss of [
  'const getEntriesNew = () => {};',
  'const config = { endpoint: "/domain/list-search-v2" };'
]) {
  assert.doesNotMatch(nearMiss, legacyToken, `token boundary must allow ${nearMiss}`);
}

for (const [view, factoryName] of [
  ['domain', 'vmDomainServerData'],
  ['alias', 'vmAliasServerData'],
  ['mailbox', 'vmMailboxServerData']
]) {
  const source = fs.readFileSync(path.join(__dirname, `../application/views/${view}/js/list.js`), 'utf8');
  const active = new RegExp(`'ajax':\\s*${factoryName}\\([^\\n]*controller='${view}' action='list-data'`);
  assert.match(source, active, `${view}: active transport must use ${factoryName} and list-data`);
  assert.doesNotMatch(source, legacyToken, `${view}: legacy manual search must stay absent`);
  console.log(`OK: ${view} uses ${factoryName} with native list-data transport`);
}
