'use strict';

/* global EventTarget, URL, __dirname, console, process, require */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {spawnSync} = require('node:child_process');

const filename = `${__dirname}/test-refactor-ui-events.cjs`;
const source = fs.readFileSync(filename, 'utf8');
const flags = [
  'VIMBADMIN_UI_EVENTS_DISABLE_FINISH_GUARD',
  'VIMBADMIN_UI_EVENTS_DISABLE_STALE_GUARD',
  'VIMBADMIN_UI_EVENTS_INVERT_ERROR_GUARD',
];
const cleanEnv = {...process.env};
for (const flag of [...flags, 'VIMBADMIN_UI_EVENTS_MUTATION_CHILD']) delete cleanEnv[flag];
const results = new Map(flags.map(flag => {
  const result = spawnSync(process.execPath, [filename], {
    env: {...cleanEnv, [flag]: '1'}, encoding: 'utf8', timeout: 30000, maxBuffer: 256 * 1024,
  });
  assert.equal(result.status, 1, `${flag}: manual mutation must fail`);
  assert.equal(result.stderr, '', `${flag}: manual mutation must bootstrap`);
  assert.equal(result.error, undefined);
  assert.equal(result.signal, null);
  return [flag, result];
}));

function runHarness(env = cleanEnv, alterResult = value => value) {
  const calls = [];
  const markers = [];
  const output = [];
  const stopped = new Error('harness exited');
  let status;
  const context = {
    EventTarget, URL, __dirname, __filename: filename,
    console: {log: value => output.push(value)},
    process: {env, execPath: process.execPath, exit(code) { status = code; throw stopped; }},
    require(name) {
      if (name !== 'node:child_process') return require(name);
      return {spawnSync(executable, args, options) {
        assert.equal(executable, process.execPath);
        assert.deepEqual(Array.from(args), [filename]);
        markers.push(options.env.VIMBADMIN_UI_EVENTS_MUTATION_CHILD);
        assert.equal(options.env.VIMBADMIN_SOURCE_ROOT, env.VIMBADMIN_SOURCE_ROOT);
        const selected = flags.filter(flag => options.env[flag] === '1');
        assert.equal(selected.length, 1, 'each child selects exactly one mutation');
        calls.push(selected[0]);
        assert.ok(calls.length <= flags.length, 'child spawning is bounded');
        return alterResult({...results.get(selected[0])}, selected[0]);
      }};
    },
  };
  try {
    vm.runInNewContext(source, context, {filename, timeout: 30000});
  } catch (error) {
    if (error !== stopped) throw error;
  }
  return {calls, markers, output, status};
}

const normal = runHarness();
assert.equal(normal.status, 0, normal.output.join('\n'));
assert.deepEqual(normal.calls, flags, 'all finish, stale and error mutations run exactly once');
assert.deepEqual(normal.markers, flags.map(() => '1'), 'every automatic child has the recursion marker');
console.log('PASS automatic finish/stale/error dispatch and exact child failures');

for (const flag of flags) {
  const scenarios = [
    ['success', value => ({...value, status: 0})],
    ['spawn error', value => ({...value, error: new Error('spawn failed')})],
    ['signal', value => ({...value, status: null, signal: 'SIGTERM'})],
    ['bootstrap', value => ({...value, stderr: 'bootstrap failed', stdout: ''})],
    ['count-only', value => ({...value, stdout: value.stdout.split('\n').slice(-2).join('\n')})],
    ['wrong identity', value => ({...value, stdout: value.stdout.replace('  FAIL public/js/', '  FAIL wrong/')})],
  ];
  for (const [name, alter] of scenarios) {
    const run = runHarness(cleanEnv, (value, selected) => selected === flag ? alter(value) : value);
    assert.equal(run.status, 1, `${flag}: ${name} must fail the parent harness`);
    const diagnostic = run.output.find(line => line.startsWith('  FAIL ') && line.includes('mutation fails at'));
    assert.ok(diagnostic, `${flag}: ${name} must fail the mutation identity assertion`);
    if (name === 'count-only' || name === 'wrong identity') {
      const expectedCount = flag === flags[1] ? 4 : 2;
      const observedCount = name === 'count-only' ? 0 : expectedCount;
      assert.ok(diagnostic.includes(`expected=${expectedCount}, observed=${observedCount}, status=1`),
        `${flag}: ${name} diagnostic must report the actual observed failure count`);
    }
  }
}
console.log('PASS every mutation rejects success, spawn/signal/bootstrap errors, counts and wrong identities');

const marked = runHarness({...cleanEnv, VIMBADMIN_UI_EVENTS_MUTATION_CHILD: '1'});
assert.equal(marked.status, 0);
assert.deepEqual(marked.calls, [], 'marked child cannot recurse even with its mutation flag omitted');
for (const flag of flags) {
  const manual = runHarness({...cleanEnv, [flag]: '1'});
  assert.equal(manual.status, 1);
  assert.deepEqual(manual.calls, [], `${flag}: manual mutations cannot recurse`);
}
console.log('PASS child-marker boundary and all manual flags prevent recursion');
