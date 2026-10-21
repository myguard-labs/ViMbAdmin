#!/usr/bin/env node
'use strict';
/* global require, console, __dirname */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const workflow = fs.readFileSync(path.join(__dirname, '../.github/workflows/regression.yml'), 'utf8');
const command = 'node ci/test-entry-autoload-cleanup.cjs';
function runs(body, invocation) {
  return body.split('\n').some(line => [invocation, `run: ${invocation}`, `- run: ${invocation}`].includes(line.trim()));
}
function check(source) {
  const jobs = [...source.matchAll(/^  ([A-Za-z_][A-Za-z0-9_-]*):[ \t]*(?:#[^\n]*)?\n([\s\S]*?)(?=^  [A-Za-z_][A-Za-z0-9_-]*:[ \t]*(?:#[^\n]*)?\n|(?![\s\S]))/gm)];
  const owners = jobs.filter(([, , body]) => runs(body, command));
  assert.equal(owners.length, 1, 'cleanup test must run in exactly one job');
  assert.equal(owners[0][1], 'static', 'cleanup test requires the Node-capable static job');
  const unit = jobs.find(([, name]) => name === 'unit');
  assert.ok(runs(unit?.[2] ?? '', 'bash ci/test-entry-autoload-control.sh'),
    'real PHP autoload control stays in the supported PHP matrix');
}

check(workflow);
assert.doesNotThrow(() => check(workflow.replace('  static:\n', '  static: # Node-capable lane\n')),
  'trailing job comments preserve the static runtime boundary');
const missing = workflow.replace(command, 'true');
assert.throws(() => check(missing), /cleanup test must run in exactly one job/);
for (const prefix of ['# ', 'echo ']) {
  assert.throws(() => check(workflow.replace(command, prefix + command)),
    /cleanup test must run in exactly one job/);
}
const unitControl = 'run: bash ci/test-entry-autoload-control.sh';
const extraStep = `${unitControl}\n\n      - name: Misplaced cleanup control\n        run: ${command}`;
const misplaced = missing.replace(unitControl, extraStep);
assert.throws(() => check(misplaced), /cleanup test requires the Node-capable static job/);
const duplicated = workflow.replace(unitControl, extraStep);
assert.throws(() => check(duplicated), /cleanup test must run in exactly one job/);
const extraJob = `  extra_job2: # separate PHP runtime\n    runs-on: ubuntu-24.04\n` +
  `    container: php:8.3\n    steps:\n      - run: ${command}\n\n`;
assert.throws(() => check(workflow.replace('  unit:\n', `${extraJob}  unit:\n`)),
  /cleanup test must run in exactly one job/, 'a separate runtime with digits/underscores must be detected');
assert.throws(() => check(workflow.replace(unitControl, `# ${unitControl}`)),
  /real PHP autoload control stays in the supported PHP matrix/);
assert.throws(() => check(workflow.replace('  unit:\n', '  missing_unit:\n')),
  /real PHP autoload control stays in the supported PHP matrix/);
assert.throws(() => check(''), /cleanup test must run in exactly one job/);
check(workflow);
console.log('OK: entry cleanup runtime placement; missing, commented, echoed, misplaced, duplicate and PHP controls red; restored green');
