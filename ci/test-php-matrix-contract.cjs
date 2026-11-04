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
const workflowEvents = ['pull_request', 'workflow_dispatch', 'push'];
function checkTriggers(workflow) {
  for (const event of workflowEvents) {
    assert.ok(Object.hasOwn(workflow.on ?? {}, event), 'all workflow triggers required');
  }
  assert.deepEqual(workflow.on.push, { branches: ['master'] },
    'merged-tree checks require an unfiltered master push');
}

// The repository does not enforce branch freshness: PR-head checks alone cannot
// cover two passing changes combined by a later merge. Keep post-merge checks.
for (const file of ['ci.yml', 'regression.yml', 'security.yml', 'static-analysis.yml']) {
  const workflow = yaml.parse(fs.readFileSync(path.join(__dirname, '../.github/workflows', file), 'utf8'),
    { uniqueKeys: true, maxAliasCount: 100 });
  checkTriggers(workflow);
  for (const event of workflowEvents) {
    const candidate = JSON.parse(JSON.stringify(workflow));
    delete candidate.on[event];
    assert.throws(() => checkTriggers(candidate), /all workflow triggers required/);
  }
  for (const push of [null, 'master', {}, { branches: ['main'] },
    { branches: ['master', 'other'] }, { branches: ['master'], paths: ['src/**'] },
    { branches: ['master'], 'paths-ignore': ['ci/**'] }, { tags: ['*'] }]) {
    assert.throws(() => checkTriggers({ ...workflow, on: { ...workflow.on, push } }),
      /merged-tree checks require an unfiltered master push/);
  }
  checkTriggers(workflow);
}

function checkPlacement(source) {
  const workflow = yaml.parse(source, { uniqueKeys: true, maxAliasCount: 100 });
  checkTriggers(workflow);
  const owners = [];
  for (const [name, job] of Object.entries(workflow.jobs)) {
    for (const step of job.steps ?? []) {
      if (step.run?.split('\n').some(line => line.trim() === command)) owners.push({ name, job, step });
    }
  }
  assert.equal(owners.length, 1, 'matrix contract must run in exactly one step');
  assert.equal(owners[0].name, 'static', 'matrix contract requires the always-running static job');
  assert.equal(owners[0].job.if, undefined, 'matrix contract job must run for all triggers');
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
const contractCommands = ['npm ci --prefix ci --ignore-scripts --no-audit --no-fund', command,
  'node ci/test-selfhosted-sarif.cjs'];
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
  assert.equal(job.if, undefined, 'browser job must run for all triggers');
  assert.equal(job.env?.VIMBADMIN_BROWSER, '${{ matrix.browser }}', 'selected engine must reach browser fixtures');
  assert.deepEqual(job.strategy.matrix, { browser: ['chromium', 'firefox', 'webkit'] },
    'all three browser lanes required');
  const onceOnly = new Set([...sharedCommands, ...contractCommands]);
  const expected = new Set([...onceOnly, ...engineCommands]);
  checkCommandOwners(workflow, onceOnly, expected);
  checkTriggers(workflow);
  for (const event of workflowEvents) {
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
  for (const event of workflowEvents) {
    assert.throws(() => checkBrowserSelection(mutate(workflow => { delete workflow.on[event]; })),
      /all workflow triggers required/);
  }
  assert.throws(() => checkBrowserSelection(mutate(workflow => {
    workflow.jobs.static.if = "github.event_name == 'pull_request'";
  })), /browser job must run for all triggers/);
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

for (const [file, jobs] of [['ci.yml', ['lint']], ['regression.yml', ['unit', 'cache-wiring', 'schema-drift', 'doveadm-native']]]) {
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
  assert.throws(() => check(source.replace('        include:', '        include: [unterminated'), jobs),
    { name: 'YAMLParseError' });
  check(source, jobs);
  if (file === 'regression.yml') {
    checkPlacement(source);
    testBrowserSelection(source);
    assert.throws(() => checkPlacement(source.replace('  static:\n', "  static:\n    if: github.event_name == 'pull_request'\n")),
      /must run for all triggers/);
    assert.throws(() => checkPlacement(source.replace(command, `# ${command}`)), /exactly one step/);
    assert.throws(() => checkPlacement(source.replace("if: matrix.browser == 'chromium'", "if: github.event_name == 'pull_request'")),
      /runs once per workflow/);
  }
}
// PHP06 fixtures are explicit unit-job owners on every maintained minor.
const php06Fixtures = [
  'ci/test-cli-precompile-contract.php',
  'ci/test-cli-schema-result-contract.php',
  'ci/test-smarty-date-formatter-contract.php',
  'ci/test-smarty-filesize-contract.php',
  'ci/test-smarty-shorten-contract.php',
  'ci/test-smarty-unixdate-contract.php'
];
function checkPhp06Owners(workflow) {
  const job = workflow.jobs.unit;
  assert.equal(job.if, undefined, 'PHP06 unit owner remains unconditional');
  assert.deepEqual(job.strategy.matrix.include.map(entry => String(entry.php)), ['8.3', '8.4', '8.5', '8.6']);
  for (const fixture of php06Fixtures) {
    const owners = Object.entries(workflow.jobs).flatMap(([name, owner]) =>
      owner.steps.filter(step => runLines(step).includes(`php ${fixture}`)).map(step => ({ name, step })));
    assert.equal(owners.length, 1, `${fixture}: exactly one workflow owner required`);
    assert.equal(owners[0].name, 'unit', `${fixture}: full PHP matrix unit owner required`);
    assert.equal(owners[0].step.if, undefined, `${fixture}: unconditional owner required`);
  }
}
const php06Workflow = yaml.parse(fs.readFileSync(path.join(__dirname, '../.github/workflows/regression.yml'), 'utf8'));
checkPhp06Owners(php06Workflow);
for (const fixture of php06Fixtures) {
  for (const mutate of [
    w => { w.jobs.unit.steps = w.jobs.unit.steps.filter(step => !runLines(step).includes(`php ${fixture}`)); },
    w => { w.jobs.unit.steps.find(step => runLines(step).includes(`php ${fixture}`)).if = 'false'; },
    w => {
      const index = w.jobs.unit.steps.findIndex(step => runLines(step).includes(`php ${fixture}`));
      w.jobs.static.steps.push(w.jobs.unit.steps.splice(index, 1)[0]);
    }
  ]) {
    const changed = JSON.parse(JSON.stringify(php06Workflow));
    mutate(changed);
    assert.throws(() => checkPhp06Owners(changed), assert.AssertionError);
  }
}
checkPhp06Owners(php06Workflow);
console.log('OK: six PHP06 fixtures each have one unconditional full-matrix unit owner; 18 missing/conditional/wrong-job controls rejected; restored green');
const retainedOwners = [
  ['ci.yml', 'lint', 'php ci/test-maintenance-session-identity.php'],
  ['ci.yml', 'lint', 'php ci/test-maintenance-resource-child-bootstrap.php'],
  ['regression.yml', 'unit', 'php ci/test-entity-autoloader-registration.php'],
  ['regression.yml', 'unit', 'php ci/test-refactor-doveadm-contract.php']
];
const retainedWorkflows = Object.fromEntries(['ci.yml', 'regression.yml'].map(file =>
  [file, yaml.parse(fs.readFileSync(path.join(__dirname, '../.github/workflows', file), 'utf8'))]));
function checkRetainedOwners(workflows) {
  for (const [file, jobName, command] of retainedOwners) {
    const owners = Object.entries(workflows[file].jobs).flatMap(([name, job]) =>
      job.steps.filter(step => runLines(step).includes(command)).map(step => ({ name, job, step })));
    assert.equal(owners.length, 1, `${command}: exactly one workflow owner required`);
    assert.equal(owners[0].name, jobName, `${command}: full-matrix owner required`);
    assert.equal(owners[0].job.if, undefined);
    assert.equal(owners[0].step.if, undefined);
    assert.deepEqual(owners[0].job.strategy.matrix.include.map(entry => String(entry.php)), ['8.3', '8.4', '8.5', '8.6']);
  }
}
checkRetainedOwners(retainedWorkflows);
for (const [file, jobName, command] of retainedOwners) {
  for (const conditional of [false, true]) {
    const changed = JSON.parse(JSON.stringify(retainedWorkflows));
    const job = changed[file].jobs[jobName];
    if (conditional) job.steps.find(step => runLines(step).includes(command)).if = 'false';
    else job.steps = job.steps.filter(step => !runLines(step).includes(command));
    assert.throws(() => checkRetainedOwners(changed), assert.AssertionError);
  }
}
checkRetainedOwners(retainedWorkflows);
function checkDoveadmNative(workflow) {
  const job = workflow.jobs['doveadm-native'];
  assert.equal(job.if, undefined, 'native Doveadm owner remains unconditional');
  assert.deepEqual(job.strategy.matrix.include.map(({ php, image }) => ({ php, image })), workflow.jobs.unit.strategy.matrix.include,
    'native Doveadm uses every exact maintained pinned runtime image');
  for (const lane of job.strategy.matrix.include) {
    assert.deepEqual(Object.keys(lane).sort(), ['php', 'image', 'build-essential-version', 'libcurl-dev-version'].sort());
    for (const field of ['build-essential-version', 'libcurl-dev-version']) {
      assert.match(lane[field] ?? '', /^[0-9][0-9A-Za-z.+:~_-]*$/, 'required exact Debian package version');
    }
  }
  assert.equal(job.strategy['fail-fast'], false);
  const steps = job.steps.filter(step => step.run === 'bash .github/scripts/run-doveadm-native.sh');
  assert.equal(steps.length, 1, 'one actual native Doveadm build/run owner required');
  assert.equal(steps[0].if, undefined);
  assert.deepEqual(steps[0].env, {
    DOVEADM_BASE_IMAGE: '${{ matrix.image }}', DOVEADM_PHP_MINOR: '${{ matrix.php }}',
    DOVEADM_BUILD_ESSENTIAL_VERSION: '${{ matrix.build-essential-version }}',
    DOVEADM_LIBCURL_DEV_VERSION: '${{ matrix.libcurl-dev-version }}'
  });
}
checkDoveadmNative(retainedWorkflows['regression.yml']);
for (const mutate of [
  w => { w.jobs['doveadm-native'].if = 'false'; },
  w => { w.jobs['doveadm-native'].strategy.matrix.include[0].image = 'untrusted:latest'; },
  w => { w.jobs['doveadm-native'].strategy['fail-fast'] = true; },
  w => { w.jobs['doveadm-native'].steps.find(step => step.run === 'bash .github/scripts/run-doveadm-native.sh').env.DOVEADM_PHP_MINOR = '8.2'; }
]) {
  const changed = JSON.parse(JSON.stringify(retainedWorkflows['regression.yml']));
  mutate(changed);
  assert.throws(() => checkDoveadmNative(changed), assert.AssertionError);
}
for (const field of ['build-essential-version', 'libcurl-dev-version']) {
  for (let lane = 0; lane < 4; lane++) {
    for (const invalid of [undefined, '', '*', 'latest', '12.9 extra']) {
      const changed = structuredClone(retainedWorkflows['regression.yml']);
      changed.jobs['doveadm-native'].strategy.matrix.include[lane][field] = invalid;
      assert.throws(() => checkDoveadmNative(changed), assert.AssertionError);
    }
  }
}
checkDoveadmNative(retainedWorkflows['regression.yml']);

// Execute the real discovery guard against private runner copies: missing calls
// must fail even when all remaining commands are valid and return success.
const guardSource = fs.readFileSync(path.join(__dirname, '../tests/assert-regression-coverage.sh'), 'utf8');
const guardStart = guardSource.indexOf('unit_runner_executes_tracked_tests_and_subset() {');
const guardEnd = guardSource.indexOf('\n}\n\nif ! unit_runner_executes_tracked_tests_and_subset', guardStart);
assert.ok(guardStart >= 0 && guardEnd > guardStart, 'actual unit discovery function required');
const guardCommand = 'set -euo pipefail\nreadonly unit_runner=tests/run-unit-tests.sh\n' +
  guardSource.slice(guardStart, guardEnd + 2) + '\nunit_runner_executes_tracked_tests_and_subset';
const runtimeOwnerRoot = fs.mkdtempSync(path.join(require('node:os').tmpdir(), 'vimbadmin-runtime-owner-'));
const runnerSource = fs.readFileSync(path.join(__dirname, '../tests/run-unit-tests.sh'), 'utf8');
const runOwner = () => require('node:child_process').spawnSync('/bin/bash', ['-c', guardCommand], {
  cwd: runtimeOwnerRoot, encoding: 'utf8'
});
try {
  fs.mkdirSync(path.join(runtimeOwnerRoot, 'tests'));
  const runner = path.join(runtimeOwnerRoot, 'tests/run-unit-tests.sh');
  fs.writeFileSync(runner, runnerSource, { mode: 0o755 });
  assert.equal(runOwner().status, 0, 'restored default runtime discovery');
  for (const command of [
    'bash ci/test-browser-desktop-negative-control-contract.sh',
    'bash ci/test-psalm-response-propagation.sh --runtime-only',
    'bash ci/test-psalm-flash-propagation.sh --runtime-only',
    'bash ci/test-psalm-mcp-body-source.sh --runtime-only',
    'selected_tests+=(ci/test-oss-genurl-components.php)',
    'selected_tests+=(ci/test-archive-zero-destination-contract.php)',
    'selected_tests+=(ci/test-nonstyle-rate-limit-cleanup-contract.php)'
  ]) {
    const line = `  ${command}\n`;
    assert.equal(runnerSource.split(line).length, 2, `unique runtime call: ${command}`);
    fs.writeFileSync(runner, runnerSource.replace(line, ''));
    assert.equal(runOwner().status, 1, `actual discovery rejects missing runtime call: ${command}`);
    fs.writeFileSync(runner, runnerSource);
    assert.equal(runOwner().status, 0, `restored runtime call: ${command}`);
  }
} finally {
  fs.rmSync(runtimeOwnerRoot, { recursive: true, force: true });
}
console.log('OK: retained full-minor owners and seven actual runtime discovery omission controls; restored green');
const psalmPipeline = yaml.parse(fs.readFileSync(path.join(__dirname, '../.github/workflows/static-analysis.yml'), 'utf8'));
const psalmPipelineCommands = [
  'bash ci/test-psalm-optional-model-contract.sh',
  'psalm --no-progress --taint-analysis'
];
function checkPsalmPipeline(workflow) {
  const job = workflow.jobs['psalm-taint'];
  assert.equal(job.if, undefined, 'complete Psalm job remains unconditional');
  assert.equal(job.steps.length, 10, 'complete Psalm pipeline has ten steps');
  assert.ok(!job.steps.some(step => step.run === 'psalm --no-progress --output-format=text --config=psalm.xml'),
    'retired ordinary Psalm owner must remain absent');
  let previous = -1;
  for (const command of psalmPipelineCommands) {
    const owners = job.steps.filter(step => step.run === command);
    assert.equal(owners.length, 1, `${command}: exact pipeline owner required`);
    assert.equal(owners[0].if, undefined, `${command}: unconditional pipeline owner required`);
    const index = job.steps.indexOf(owners[0]);
    assert.ok(index > previous, 'model check and taint remain ordered');
    previous = index;
  }
}
checkPsalmPipeline(psalmPipeline);
for (const command of psalmPipelineCommands) {
  for (const mode of ['remove', 'replace', 'conditional']) {
    const changed = JSON.parse(JSON.stringify(psalmPipeline));
    const job = changed.jobs['psalm-taint'];
    if (mode === 'remove') job.steps = job.steps.filter(step => step.run !== command);
    else if (mode === 'replace') job.steps.find(step => step.run === command).run = '# removed check';
    else job.steps.find(step => step.run === command).if = 'false';
    assert.throws(() => checkPsalmPipeline(changed), assert.AssertionError);
  }
}
for (const mutate of [
  job => { job.if = 'false'; },
  job => { job.steps.reverse(); },
  job => { job.steps.push({ run: psalmPipelineCommands[0] }); },
  job => { job.steps.push({ run: psalmPipelineCommands[1] }); },
  job => { job.steps[0] = { run: 'psalm --no-progress --output-format=text --config=psalm.xml' }; }
]) {
  const changed = JSON.parse(JSON.stringify(psalmPipeline));
  mutate(changed.jobs['psalm-taint']);
  assert.throws(() => checkPsalmPipeline(changed), assert.AssertionError);
}
checkPsalmPipeline(psalmPipeline);
console.log('OK: actual ten-step model/taint Psalm pipeline; omission, replacement, conditional, order, duplicate and retired-owner controls; restored green');

// Exercise the real builder without Docker: a private uncommitted tree must
// determine the context, and cleanup must require matching creation receipts.
const nativeBuilder = path.join(__dirname, '../.github/scripts/run-doveadm-native.sh');
const nativeSource = fs.readFileSync(nativeBuilder, 'utf8');
const nativeRoot = fs.mkdtempSync(path.join(require('node:os').tmpdir(), 'doveadm-builder-contract-'));
const spawn = require('node:child_process').spawnSync;
function nativeCommand(command, args, options = {}) {
  const result = spawn(command, args, { cwd: nativeRoot, encoding: 'utf8', ...options });
  assert.equal(result.error, undefined);
  return result;
}
// Git tree archives use the current wall-clock mtime. Preserve all bytes except
// that timestamp and its derived checksum when comparing two tree archives.
function archiveIdentity(bytes) {
  const normalized = Buffer.from(bytes);
  let offset = 0;
  while (offset < bytes.length) {
    const header = bytes.subarray(offset, offset + 512);
    assert.equal(header.length, 512, 'complete tar header required');
    if (header.every(byte => byte === 0)) {
      assert.ok(bytes.subarray(offset).every(byte => byte === 0), 'tar end padding only');
      break;
    }
    const octal = (start, end) => {
      const text = header.subarray(start, end).toString('ascii').replace(/\0/g, '').trim();
      assert.match(text, /^[0-7]+$/, 'valid tar numeric field required');
      return Number.parseInt(text, 8);
    };
    const checksum = header.reduce((total, byte, index) => total + (index >= 148 && index < 156 ? 32 : byte), 0);
    assert.equal(octal(148, 156), checksum, 'valid tar header checksum required');
    const size = octal(124, 136);
    assert.ok(Number.isSafeInteger(size) && offset + 512 + size <= bytes.length, 'complete tar payload required');
    normalized.fill(0, offset + 136, offset + 156);
    offset += 512 + Math.ceil(size / 512) * 512;
  }
  assert.ok(offset < bytes.length, 'tar end marker required');
  return normalized;
}
function rewrittenHeader(bytes, start, value) {
  const changed = Buffer.from(bytes);
  changed.write(value, start, 'ascii');
  changed.fill(32, 148, 156);
  const checksum = changed.subarray(0, 512).reduce((total, byte) => total + byte, 0);
  changed.write(checksum.toString(8).padStart(6, '0') + '\0 ', 148, 'ascii');
  return changed;
}
try {
  const git = args => {
    const result = nativeCommand('git', args);
    assert.equal(result.status, 0, result.stderr);
    return result.stdout.trim();
  };
  git(['init', '--quiet']);
  const requiredInputs = ['.github/scripts/doveadm-native.Dockerfile',
    '.github/scripts/setup-php-container.sh', 'composer.json', 'composer.lock',
    'library/ViMbAdmin/Doveadm.php', 'ci/test-refactor-doveadm-contract.php',
    'ci/support/doveadm-curl-init-failure.c'];
  for (const file of [...requiredInputs, 'staged-marker']) {
    fs.mkdirSync(path.dirname(path.join(nativeRoot, file)), { recursive: true });
    fs.writeFileSync(path.join(nativeRoot, file), 'staged bytes\n');
  }
  git(['add', '--', ...requiredInputs, 'staged-marker']);
  const tree = git(['write-tree']);
  fs.writeFileSync(path.join(nativeRoot, 'staged-marker'), 'working bytes\n');
  fs.writeFileSync(path.join(nativeRoot, 'untracked-marker'), 'excluded\n');
  const fakeBin = path.join(nativeRoot, 'fake-bin');
  fs.mkdirSync(fakeBin);
  const dockerLog = path.join(nativeRoot, 'docker.log');
  fs.writeFileSync(path.join(fakeBin, 'docker'), '#!/bin/sh\nprintf called >> "$DOCKER_LOG"\nexit 91\n', { mode: 0o755 });
  const capturedArchive = path.join(nativeRoot, 'actual-builder-input.tar');
  const realGit = nativeCommand('sh', ['-c', 'command -v git']).stdout.trim();
  assert.ok(path.isAbsolute(realGit));
  fs.writeFileSync(path.join(fakeBin, 'git'), '#!/bin/sh\nif [ "$1" = archive ]; then\n  "$REAL_GIT" "$@" > "$CAPTURED_ARCHIVE" || exit $?\n  cat "$CAPTURED_ARCHIVE"\nelse\n  exec "$REAL_GIT" "$@"\nfi\n', { mode: 0o755 });
  const env = { ...process.env, PATH: `${fakeBin}:${process.env.PATH}`, DOCKER_LOG: dockerLog,
    REAL_GIT: realGit, CAPTURED_ARCHIVE: capturedArchive,
    RUNNER_ENVIRONMENT: 'self-hosted', GITHUB_RUN_ID: '307', GITHUB_RUN_ATTEMPT: '1',
    DOVEADM_PHP_MINOR: '8.3', DOVEADM_BASE_IMAGE: `mirror.gcr.io/library/php@sha256:${'a'.repeat(64)}`,
    DOVEADM_BUILD_ESSENTIAL_VERSION: '12.12', DOVEADM_LIBCURL_DEV_VERSION: '8.14.1-2+deb13u7',
    DOVEADM_CONTEXT_ONLY: '1', DOVEADM_SOURCE_TREE: tree, TMPDIR: nativeRoot };
  function contextCheck(candidateEnv) {
    const result = nativeCommand('bash', [nativeBuilder], { env: candidateEnv });
    assert.equal(result.status, 0, result.stderr);
    const context = result.stdout.match(/^Prepared native context: (.+)$/m)?.[1];
    assert.ok(context?.startsWith(`${nativeRoot}/`));
    assert.equal(fs.readFileSync(path.join(context, 'staged-marker'), 'utf8'), 'staged bytes\n');
    assert.equal(fs.existsSync(path.join(context, 'untracked-marker')), false);
    const manifest = JSON.parse(fs.readFileSync(path.join(context, '.doveadm-source-manifest.json'), 'utf8'));
    assert.equal(manifest.source_tree, tree);
    const sha = bytes => require('node:crypto').createHash('sha256').update(bytes).digest('hex');
    const actualArchive = fs.readFileSync(capturedArchive);
    const expectedArchive = nativeCommand('git', ['archive', '--format=tar', tree], { encoding: null });
    assert.equal(expectedArchive.status, 0, expectedArchive.stderr);
    assert.equal(manifest.archive_sha256, sha(actualArchive), 'manifest binds the exact builder archive input');
    assert.deepEqual(archiveIdentity(actualArchive), archiveIdentity(expectedArchive.stdout),
      'archive paths, types, modes and payloads bind the selected tree');
    const changedTimestamp = rewrittenHeader(actualArchive, 136, '00000000001\0');
    assert.throws(() => assert.equal(sha(actualArchive), sha(changedTimestamp)), assert.AssertionError);
    assert.deepEqual(archiveIdentity(actualArchive), archiveIdentity(changedTimestamp));
    for (const drift of [rewrittenHeader(actualArchive, 0, 'tampered-path'),
      rewrittenHeader(actualArchive, 100, '0000700\0')]) {
      assert.throws(() => assert.deepEqual(archiveIdentity(actualArchive), archiveIdentity(drift)), assert.AssertionError);
    }
    const changedPayload = Buffer.from(actualArchive);
    let fileOffset = 0;
    while (changedPayload[fileOffset + 156] !== 48 && changedPayload[fileOffset + 156] !== 0) {
      fileOffset += 512 + Math.ceil(Number.parseInt(changedPayload.subarray(fileOffset + 124, fileOffset + 136).toString('ascii'), 8) / 512) * 512;
    }
    changedPayload[fileOffset + 512] ^= 1;
    assert.throws(() => assert.deepEqual(archiveIdentity(actualArchive), archiveIdentity(changedPayload)), assert.AssertionError);
    assert.equal(manifest.tracked_inventory_sha256, sha(nativeCommand('git', ['ls-tree', '-r', '--full-tree', tree]).stdout));
    assert.equal(fs.existsSync(dockerLog), false, 'context qualification cannot touch Docker');
    fs.rmSync(context, { recursive: true });
  }
  contextCheck(env);
  for (const field of ['DOVEADM_BUILD_ESSENTIAL_VERSION', 'DOVEADM_LIBCURL_DEV_VERSION']) {
    for (const invalid of [undefined, '', '*', 'latest', '12.9 extra']) {
      const changed = { ...env };
      if (invalid === undefined) delete changed[field]; else changed[field] = invalid;
      assert.notEqual(nativeCommand('bash', [nativeBuilder], { env: changed }).status, 0);
      assert.equal(fs.existsSync(dockerLog), false, 'invalid package pin fails before Docker');
    }
  }
  // A tree-valued HEAD also tests the CI default without creating a commit.
  git(['update-ref', 'refs/context/tree', tree]);
  git(['symbolic-ref', 'HEAD', 'refs/context/tree']);
  const defaultEnv = { ...env };
  delete defaultEnv.DOVEADM_SOURCE_TREE;
  contextCheck(defaultEnv);
  for (const invalid of ['--help', 'missing-revision', git(['hash-object', '-w', 'staged-marker'])]) {
    assert.notEqual(nativeCommand('bash', [nativeBuilder], { env: { ...env, DOVEADM_SOURCE_TREE: invalid } }).status, 0);
  }
  git(['rm', '--cached', '--', requiredInputs[6]]);
  const missingTree = git(['write-tree']);
  assert.notEqual(nativeCommand('bash', [nativeBuilder], { env: { ...env, DOVEADM_SOURCE_TREE: missingTree } }).status, 0);
  assert.equal(fs.existsSync(dockerLog), false);
  fs.writeFileSync(path.join(fakeBin, 'docker'), `#!/bin/sh
printf '%s\\n' "$*" >> "$DOCKER_LOG"
if [ "$PREEXIST" = container ] || [ "$1" = image ]; then printf 'foreign-resource\\n'; fi
`, { mode: 0o755 });
  for (const resource of ['container', 'image']) {
    const result = nativeCommand('bash', [nativeBuilder], {
      env: { ...env, DOVEADM_CONTEXT_ONLY: '0', PREEXIST: resource }
    });
    assert.notEqual(result.status, 0, 'preexisting resource must be preserved');
    const calls = fs.readFileSync(dockerLog, 'utf8').trim().split('\n');
    assert.equal(calls.length, resource === 'container' ? 1 : 2);
    assert.ok(calls.every(line => /^(container|image) ls /.test(line)), 'preexisting resource refusal performs queries only');
    fs.unlinkSync(dockerLog);
  }

  const functions = nativeSource.slice(nativeSource.indexOf('persist_receipt()'), nativeSource.indexOf('\ntrap cleanup EXIT'));
  assert.ok(functions.includes('cleanup()'));
  const mock = `docker() {
    if [[ $1 == inspect ]]; then printf '%s\\n' "$MOCK_CONTAINER";
    elif [[ $1 == image && $2 == inspect ]]; then printf '%s\\n' "$MOCK_IMAGE";
    else printf '%s\\n' "$*" >> "$REMOVE_LOG"; fi
  }
  ${functions}
  trap cleanup EXIT
  exit "$CHECK_STATUS"`;
  let cleanupCases = 0;
  for (const minor of ['8.3', '8.4', '8.5', '8.6']) {
    for (const mismatch of ['', 'container-id', 'container-name', 'container-run', 'container-nonce',
      'container-tree', 'container-receipt', 'image-id', 'image-run', 'image-nonce', 'image-tree', 'image-receipt']) {
      const context = fs.mkdtempSync(path.join(nativeRoot, 'cleanup-'));
      const cid = 'b'.repeat(64), imageId = `sha256:${'c'.repeat(64)}`;
      const container = `vi-ci307-doveadm-native-${minor.replace('.', '')}`;
      const image = `vimbadmin-doveadm-native:${minor.replace('.', '')}`;
      const receipt = path.join(context, 'image.id'), removeLog = path.join(context, 'removed');
      fs.writeFileSync(path.join(context, 'container.id'), mismatch === 'container-receipt' ? 'wrong' : cid);
      fs.writeFileSync(receipt, mismatch === 'image-receipt' ? 'wrong' : imageId);
      fs.chmodSync(path.join(context, 'container.id'), 0o600);
      fs.chmodSync(receipt, 0o600);
      const containerFields = [cid, `/${container}`, 'run', 'nonce', tree];
      const imageFields = [imageId, 'run', 'nonce', tree];
      const ci = ['container-id', 'container-name', 'container-run', 'container-nonce', 'container-tree'].indexOf(mismatch);
      const ii = ['image-id', 'image-run', 'image-nonce', 'image-tree'].indexOf(mismatch);
      if (ci >= 0) containerFields[ci] = 'foreign';
      if (ii >= 0) imageFields[ii] = 'foreign';
      const result = nativeCommand('bash', ['-c', mock], { env: { ...process.env,
        DOVEADM_PHP_MINOR: minor, context, cid, image_id: imageId, image_receipt: receipt,
        container_receipt: path.join(context, 'container.id'), receipt_dir: context,
        build_attempted: '0', create_attempted: '0',
        container, image, owner: 'run', nonce: 'nonce', source_tree: tree,
        archive: '', retain_context: '1', REMOVE_LOG: removeLog, CHECK_STATUS: '0',
        MOCK_CONTAINER: containerFields.join(' '), MOCK_IMAGE: imageFields.join(' ') } });
      assert.equal(result.status, mismatch ? 1 : 0, `${minor}: ${mismatch}: ${result.stderr}`);
      const removed = fs.existsSync(removeLog) ? fs.readFileSync(removeLog, 'utf8') : '';
      assert.equal(removed.includes(`rm --force ${cid}\n`), !mismatch.startsWith('container-'));
      assert.equal(removed.includes(`image rm ${imageId}\n`), !mismatch.startsWith('image-'));
      cleanupCases++;
    }
  }
  const failure = nativeCommand('bash', ['-c', mock], { env: { ...process.env,
    context: nativeRoot, cid: '', image_id: '', archive: '', image_receipt: '',
    container_receipt: '', receipt_dir: nativeRoot, build_attempted: '0', create_attempted: '0',
    retain_context: '1', CHECK_STATUS: '37' } });
  assert.equal(failure.status, 37, 'cleanup preserves the original failed check status');
  console.log(`OK: staged/default tree contexts, malformed/blob/missing-input refusal, no Docker access, ${cleanupCases} literal cleanup receipt/identity/label controls and original failure status`);
} finally {
  fs.rmSync(nativeRoot, { recursive: true, force: true });
}
assert.throws(() => vm.runInNewContext(fs.readFileSync(path.join(__dirname, 'test-php-matrix-contract.cjs'), 'utf8'), {
  require(name) { if (name === 'yaml') throw new Error('missing parser'); return require(name); },
}), /requires the locked YAML parser/);
console.log('OK: once-only shared and three-engine command selection controls; parsed PHP 8.3–8.6 matrices; quote styles, extra lanes, malformed YAML, trigger placement and missing parser controls; restored green');
