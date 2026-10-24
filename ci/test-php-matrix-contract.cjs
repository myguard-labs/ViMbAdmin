#!/usr/bin/env node
'use strict';
/* global require, console, __dirname */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
let yaml;
try {
  yaml = require('yaml');
} catch {
  throw new Error('Matrix contract requires the locked YAML parser; run npm ci --prefix ci');
}

function check(source, expectedJobs) {
  const workflow = yaml.parse(source, { uniqueKeys: true, maxAliasCount: 100 });
  for (const name of expectedJobs) {
    const job = workflow?.jobs?.[name];
    assert.ok(job, `missing runtime job ${name}`);
    assert.equal(job.strategy?.['max-parallel'], 4, `${name}: four runtime lanes`);
    const matrix = job.strategy?.matrix;
    assert.deepEqual(Object.keys(matrix ?? {}), ['include'], `${name}: explicit include matrix required`);
    assert.ok(Array.isArray(matrix.include), `${name}: include must be an array`);
    const versions = matrix.include.map(entry => String(entry?.php));
    assert.deepEqual(versions, ['8.3', '8.4', '8.5', '8.6'], `${name}: complete maintained PHP matrix`);
  }
}

const command = 'node ci/test-php-matrix-contract.cjs';
function checkPlacement(source) {
  const workflow = yaml.parse(source, { uniqueKeys: true, maxAliasCount: 100 });
  assert.ok(Object.hasOwn(workflow.on, 'pull_request') && Object.hasOwn(workflow.on, 'workflow_dispatch'),
    'matrix contract requires both workflow triggers');
  const owners = [];
  for (const [name, job] of Object.entries(workflow.jobs)) {
    for (const step of job.steps ?? []) {
      if (step.run?.split('\n').some(line => line.trim() === command)) owners.push({ name, job, step });
    }
  }
  assert.equal(owners.length, 1, 'matrix contract must run in exactly one step');
  assert.equal(owners[0].name, 'static', 'matrix contract requires the always-running static job');
  assert.equal(owners[0].job.if, undefined, 'matrix contract job must run for both triggers');
  assert.equal(owners[0].step.if, "matrix.browser == 'chromium'", 'matrix contract runs once per workflow');
}

for (const [file, jobs] of [['ci.yml', ['lint']], ['regression.yml', ['unit', 'cache-wiring', 'schema-drift']]]) {
  const source = fs.readFileSync(path.join(__dirname, '../.github/workflows', file), 'utf8');
  check(source, jobs);
  check(source.replaceAll(/php: '(8\.[3-6])'/g, 'php: "$1"'), jobs);
  check(source.replaceAll(/php: '(8\.[3-6])'/g, 'php: $1'), jobs);
  assert.throws(() => check(source.replace("          - php: '8.6'", "          - php: '8.2'"), jobs),
    /complete maintained PHP matrix/);
  assert.throws(() => check(source.replace("          - php: '8.6'", "          - php: '8.5'"), jobs),
    /complete maintained PHP matrix/);
  assert.throws(() => check(source.replaceAll('      max-parallel: 4', '      max-parallel: 5'), jobs),
    /four runtime lanes/);
  assert.throws(() => check('', jobs), /missing runtime job/);
  for (const extra of ['"8.2"', "'8.2'", '8.2']) {
    assert.throws(() => check(source.replace("          - php: '8.6'", `          - php: ${extra}\n          - php: '8.6'`), jobs),
      /complete maintained PHP matrix/);
  }
  assert.throws(() => check(source.replace('        include:', "        php: ['8.2']\n        include:"), jobs),
    /explicit include matrix required/);
  assert.throws(() => check(source.replace('        include:', '        include: [unterminated'), jobs));
  check(source, jobs);
  if (file === 'regression.yml') {
    checkPlacement(source);
    assert.throws(() => checkPlacement(source.replace('  static:\n', "  static:\n    if: github.event_name == 'pull_request'\n")),
      /must run for both triggers/);
    assert.throws(() => checkPlacement(source.replace(command, `# ${command}`)), /exactly one step/);
    assert.throws(() => checkPlacement(source.replace("if: matrix.browser == 'chromium'", "if: github.event_name == 'pull_request'")),
      /runs once per workflow/);
  }
}
assert.throws(() => vm.runInNewContext(fs.readFileSync(path.join(__dirname, 'test-php-matrix-contract.cjs'), 'utf8'), {
  require(name) { if (name === 'yaml') throw new Error('missing parser'); return require(name); },
}), /requires the locked YAML parser/);
console.log('OK: parsed PHP 8.3–8.6 matrices; quote styles, extra lanes, malformed YAML, trigger placement and missing parser controls; restored green');
