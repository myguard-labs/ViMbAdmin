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

// Selection-change local verification also runs tests/assert-regression-coverage.sh
// and tests/test-assert-regression-coverage.sh: Static analysis owns reachability.
// Pin the pre-optimization command inventory so selection changes cannot drop cases.
// This extends the existing Node process; it adds no CI job or interpreter startup.
const sharedCommands = [
  "bash tests/test-resolve-bundle-v.sh",
  "php ci/test-bundle-utf8.php",
  "php tests/test-minify-bundle-charset.php",
  "bash tests/lint-template-escaping.sh",
  "bash tests/lint-no-new-zend.sh",
  "php tests/test-documentation-contract.php",
  "bash tests/lint-2fa-session-hygiene.sh",
  "bash tests/lint-bs2-grid-classes.sh",
  "bash tests/lint-bs2-component-classes.sh",
  "bash tests/test-lint-gate-cwd-independence.sh",
  "bash tests/lint-bs5-data-attrs.sh",
  "bash tests/lint-modal-aria-labelledby.sh",
  "bash tests/lint-no-glyphicon-classes.sh",
  "bash tests/lint-datatables-bs5-dom.sh",
  "bash tests/lint-datatables-registered-plugins.sh",
  "bash tests/lint-datatables-serverdata-source.sh",
  "bash tests/lint-datatables-clear-redraws.sh",
  "node tests/test-datatable-search-row-api.cjs",
  "node ci/test-refactor-ui-events.cjs",
  "node ci/test-refactor-ui-controls.cjs",
  "node ci/test-entry-autoload-workflow.cjs",
  "node ci/test-entry-autoload-cleanup.cjs",
  "VIMBADMIN_BROWSER=chrome bash tests/test-headless-chrome-container.sh",
  "bash tests/test-fixture-http-readiness-bound.sh",
  "bash ci/test-browser-tenant-mutation.sh",
  "bash ci/test-browser-operations-mutation.sh",
  "bash ci/test-browser-owned-cleanup.sh",
  "bash ci/test-browser-process-collector.sh"
];
const engineCommands = [
  "bash .github/scripts/assert-pr-runner-isolation.sh",
  "docker build -t vimbadmin-browser:1.63.0 .github/scripts/browser",
  "bash tests/test-browser-fixture-adapter.sh",
  "bash .github/scripts/run-static-php-container.sh install",
  "bash ci/test-browser-auth-errors.sh",
  "bash ci/test-browser-tenant.sh",
  "bash ci/test-browser-operations.sh",
  "bash ci/test-browser-visual.sh",
  "PHP_RENDERER=\"$GITHUB_WORKSPACE/.github/scripts/run-static-php-container.sh\" CHROMIUM_BIN=\"$GITHUB_WORKSPACE/.github/scripts/run-headless-chrome.sh\" bash tests/test-alias-destination-rendering.sh",
  "PHP_RENDERER=\"$GITHUB_WORKSPACE/.github/scripts/run-static-php-container.sh\" CHROMIUM_BIN=\"$GITHUB_WORKSPACE/.github/scripts/run-headless-chrome.sh\" bash tests/test-residual-stored-xss-rendering.sh",
  "CHROMIUM_BIN=\"$GITHUB_WORKSPACE/.github/scripts/run-headless-chrome.sh\" bash tests/test-confirm-guard-behaviour.sh",
  "CHROMIUM_BIN=\"$GITHUB_WORKSPACE/.github/scripts/run-headless-chrome.sh\" bash tests/test-source-defect-sweep-behaviour.sh",
  "CHROMIUM_BIN=\"$GITHUB_WORKSPACE/.github/scripts/run-headless-chrome.sh\" bash tests/test-datatables-dependency-free-compat.sh",
  "bash ci/test-asset-cache-version-boundary.sh",
  "bash tests/test-browser-engine-negative-control.sh",
  "CHROMIUM_BIN=\"$GITHUB_WORKSPACE/.github/scripts/run-headless-chrome.sh\" bash tests/test-native-validation-group-fixture.sh",
  "CHROMIUM_BIN=\"$GITHUB_WORKSPACE/.github/scripts/run-headless-chrome.sh\" bash tests/test-email-settings-validation-fixture.sh",
  "PHP_RENDERER=\"$GITHUB_WORKSPACE/.github/scripts/run-static-php-container.sh\" CHROMIUM_BIN=\"$GITHUB_WORKSPACE/.github/scripts/run-headless-chrome.sh\" bash tests/test-control-behaviour-rendering.sh"
];
const contractCommands = ['npm ci --prefix ci --ignore-scripts --no-audit --no-fund', command];
function runLines(step) {
  return typeof step.run === 'string' ? step.run.trim().split('\n').map(line => line.trim()).filter(Boolean) : [];
}
function checkCommandOwners(workflow, onceOnly, expected) {
  for (const [name, owner] of Object.entries(workflow.jobs)) {
    for (const step of owner.steps ?? []) {
      for (const line of runLines(step)) {
        if (name !== 'static') {
          assert.ok(!onceOnly.has(line), 'shared command must have only its static owner');
        } else {
          assert.ok(expected.has(line), 'unclassified browser command: ' + line);
          assert.ok(step.if === undefined || step.if === "matrix.browser == 'chromium'",
            'unsupported browser command condition');
        }
      }
    }
  }
}
function countBrowserCommands(job) {
  const counts = new Map();
  for (const browser of job.strategy.matrix.browser) {
    for (const step of job.steps) {
      if (step.if !== undefined && browser !== 'chromium') continue;
      for (const line of runLines(step)) counts.set(line, (counts.get(line) ?? 0) + 1);
    }
  }
  return counts;
}
function checkBrowserSelection(workflow) {
  const job = workflow.jobs.static;
  assert.equal(job.if, undefined, 'browser job must run for both triggers');
  assert.equal(job.env?.VIMBADMIN_BROWSER, '${{ matrix.browser }}', 'selected engine must reach browser fixtures');
  assert.deepEqual(job.strategy.matrix, { browser: ['chromium', 'firefox', 'webkit'] },
    'all three browser lanes required');
  const onceOnly = new Set([...sharedCommands, ...contractCommands]);
  const expected = new Set([...onceOnly, ...engineCommands]);
  checkCommandOwners(workflow, onceOnly, expected);
  for (const event of ['pull_request', 'workflow_dispatch']) {
    assert.ok(Object.hasOwn(workflow.on, event), 'both browser workflow triggers required');
    const counts = countBrowserCommands(job);
    for (const line of [...sharedCommands, ...contractCommands]) {
      assert.equal(counts.get(line), 1, event + ': shared command exactly once: ' + line);
    }
    for (const line of engineCommands) {
      assert.equal(counts.get(line), 3, event + ': engine command in every lane: ' + line);
    }
  }
}
function testBrowserSelection(source) {
  const baseline = yaml.parse(source, { uniqueKeys: true, maxAliasCount: 100 });
  const mutate = change => {
    const candidate = JSON.parse(JSON.stringify(baseline));
    change(candidate);
    return candidate;
  };
  const owner = (workflow, line) => workflow.jobs.static.steps.find(step => runLines(step).includes(line));
  checkBrowserSelection(baseline);
  checkBrowserSelection(mutate(workflow => {
    workflow.jobs.unrelatedReusable = { uses: './.github/workflows/unrelated.yml' };
  }));
  // Every named command has an exercised missing, duplicate and wrong-selection control.
  for (const line of [...sharedCommands, ...engineCommands, ...contractCommands]) {
    const shared = !engineCommands.includes(line);
    assert.throws(() => checkBrowserSelection(mutate(workflow => {
      const step = owner(workflow, line);
      step.run = runLines(step).filter(value => value !== line).join('\n');
    })), shared ? /shared command exactly once/ : /engine command in every lane/);
    assert.throws(() => checkBrowserSelection(mutate(workflow => {
      const step = owner(workflow, line);
      workflow.jobs.static.steps.push({ ...step, run: line });
    })), shared ? /shared command exactly once/ : /engine command in every lane/);
    assert.throws(() => checkBrowserSelection(mutate(workflow => {
      const step = owner(workflow, line);
      if (shared) delete step.if;
      else step.if = "matrix.browser == 'chromium'";
    })), shared ? /shared command exactly once/ : /engine command in every lane/);
  }
  for (const condition of ["matrix.browser == 'firefox'", "github.event_name == 'pull_request'", false]) {
    assert.throws(() => checkBrowserSelection(mutate(workflow => {
      owner(workflow, sharedCommands[0]).if = condition;
    })), /unsupported browser command condition/);
  }
  for (const event of ['pull_request', 'workflow_dispatch']) {
    assert.throws(() => checkBrowserSelection(mutate(workflow => { delete workflow.on[event]; })),
      /both browser workflow triggers required/);
  }
  assert.throws(() => checkBrowserSelection(mutate(workflow => {
    workflow.jobs.static.if = "github.event_name == 'pull_request'";
  })), /browser job must run for both triggers/);
  for (const env of [undefined, {}, { VIMBADMIN_BROWSER: 'chromium' }]) {
    assert.throws(() => checkBrowserSelection(mutate(workflow => { workflow.jobs.static.env = env; })),
      /selected engine must reach browser fixtures/);
  }
  for (const browsers of [['chromium', 'firefox'], ['chromium', 'firefox', 'firefox'],
    ['chromium', 'firefox', 'webkit', 'unknown']]) {
    assert.throws(() => checkBrowserSelection(mutate(workflow => {
      workflow.jobs.static.strategy.matrix.browser = browsers;
    })), /all three browser lanes required/);
  }
  assert.throws(() => checkBrowserSelection(mutate(workflow => {
    workflow.jobs.static.steps.push({ run: 'node ci/unclassified-control.cjs' });
  })), /unclassified browser command/);
  assert.throws(() => checkBrowserSelection(mutate(workflow => {
    workflow.jobs.unit.steps.push({ run: sharedCommands[0] });
  })), /shared command must have only its static owner/);
  checkBrowserSelection(baseline);
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
    testBrowserSelection(source);
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
console.log('OK: once-only shared and three-engine command selection controls; parsed PHP 8.3–8.6 matrices; quote styles, extra lanes, malformed YAML, trigger placement and missing parser controls; restored green');
