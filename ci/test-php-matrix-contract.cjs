#!/usr/bin/env node
'use strict';
/* global require, console, __dirname */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

function check(source, expectedJobs) {
  const jobs = [...source.matchAll(/^  ([\w-]+):[^\n]*\n([\s\S]*?)(?=^  [\w-]+:|(?![\s\S]))/gm)];
  for (const name of expectedJobs) {
    const body = jobs.find(([, job]) => job === name)?.[2];
    assert.ok(body, `missing runtime job ${name}`);
    assert.match(body, /^      max-parallel: 4$/m, `${name}: four runtime lanes`);
    const versions = [...body.matchAll(/^          - php: '([^']+)'$/gm)].map(match => match[1]);
    assert.deepEqual(versions, ['8.3', '8.4', '8.5', '8.6'], `${name}: complete maintained PHP matrix`);
  }
}

for (const [file, jobs] of [['ci.yml', ['lint']], ['regression.yml', ['unit', 'cache-wiring', 'schema-drift']]]) {
  const source = fs.readFileSync(path.join(__dirname, '../.github/workflows', file), 'utf8');
  check(source, jobs);
  assert.throws(() => check(source.replace("          - php: '8.6'", "          - php: '8.2'"), jobs),
    /complete maintained PHP matrix/);
  assert.throws(() => check(source.replace("          - php: '8.6'", "          - php: '8.5'"), jobs),
    /complete maintained PHP matrix/);
  assert.throws(() => check(source.replaceAll('      max-parallel: 4', '      max-parallel: 5'), jobs),
    /four runtime lanes/);
  assert.throws(() => check('', jobs), /missing runtime job/);
  check(source, jobs);
}
console.log('OK: PHP 8.3–8.6 matrices, four runtime lanes, unsupported/duplicate/unbounded/empty controls red; restored green');
