#!/usr/bin/env node
'use strict';
/* global require, process, console, __dirname */
// Test only the shell lifecycle; real PHP probe behavior has its own matrix.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const root = fs.mkdtempSync(path.join(os.tmpdir(), 'vimbadmin-entry-cleanup-control.'));
try {
  for (const dir of ['ci', 'ci/support', 'public', 'bin', 'tmp']) fs.mkdirSync(path.join(root, dir));
  const original = fs.readFileSync(path.join(__dirname, 'test-entry-autoload-control.sh'), 'utf8');
  fs.copyFileSync(path.join(__dirname, '../public/index.php'), path.join(root, 'public/index.php'));
  fs.copyFileSync(path.join(__dirname, 'support/entry-autoload-probe.php'), path.join(root, 'ci/support/entry-autoload-probe.php'));
  fs.writeFileSync(path.join(root, 'bin/php'), [
    '#!/usr/bin/env bash',
    'set -eu',
    'if [[ ${ENTRY_CLEANUP_FORCE_FAILURE:-} == 1 ]]; then exit 71; fi',
    'if [[ -n ${VIMBADMIN_ENTRY_AUTOLOAD_PROBE:-} ]]; then echo "Repositories\\Domain"; exit 1; fi',
    'if [[ -n ${VIMBADMIN_ENTRY_AUTOLOAD_CANDIDATE:-} ]]; then',
    '  printf "%s\\n" "because class Entities\\Admin is not available"',
    '  exit 1',
    'fi',
    'echo "ALL PASSED"',
    ''
  ].join('\n'), { mode: 0o700 });
  const script = path.join(root, 'ci/control.sh');
  function run(source, failure = false) {
    fs.writeFileSync(script, source);
    const result = spawnSync('bash', [script], { encoding: 'utf8', timeout: 15000,
      env: { ...process.env, PATH: `${root}/bin:${process.env.PATH}`, TMPDIR: `${root}/tmp`,
        VIMBADMIN_ENTRY_AUTOLOAD_CANDIDATE: '', VIMBADMIN_ENTRY_AUTOLOAD_PROBE: '',
        ENTRY_CLEANUP_FORCE_FAILURE: failure ? '1' : '' } });
    assert.equal(result.signal, null, 'cleanup control must terminate');
    const fixture = /^control_fixture=(.+)$/m.exec(result.stdout)?.[1];
    assert.ok(fixture?.startsWith(`${root}/tmp/`), 'record exact private fixture path');
    return { result, fixture };
  }
  function green(source) {
    const { result, fixture } = run(source);
    assert.equal(result.status, 0, result.stderr);
    assert.equal(fs.existsSync(fixture), false, 'successful entry control leaked its private fixture');
  }
  green(original);
  const failed = run(original, true);
  assert.equal(failed.result.status, 71, 'failure exit is preserved');
  assert.ok(fs.existsSync(path.join(failed.fixture, 'baseline.log')), 'failed control retains its evidence');
  assert.ok(failed.result.stderr.includes('Failed control evidence retained:'), 'failure reports retained evidence');
  const needle = 'rm -rf -- "${control_dir:?}"';
  assert.equal(original.split(needle).length, 2, 'remove exactly one successful-cleanup action');
  const mutant = original.replace(needle, ': # deliberately omit successful cleanup');
  assert.throws(() => green(mutant), /successful entry control leaked its private fixture/);
  green(original);
  console.log('OK: entry-control success removal, failure retention, named mutation red and restored green');
} finally {
  fs.rmSync(root, { recursive: true, force: true });
}
