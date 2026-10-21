#!/usr/bin/env node
'use strict';
/* global require, process, console, __dirname */
// Test only the shell lifecycle; real PHP probe behavior has its own matrix.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const root = fs.mkdtempSync(path.join(os.tmpdir(), 'vimbadmin-entry-cleanup-control.'));
try {
  for (const dir of ['ci', 'ci/support', 'public', 'bin', 'tmp']) fs.mkdirSync(path.join(root, dir));
  const original = fs.readFileSync(path.join(__dirname, 'test-entry-autoload-control.sh'), 'utf8');
  fs.copyFileSync(path.join(__dirname, '../public/index.php'), path.join(root, 'public/index.php'));
  fs.copyFileSync(path.join(__dirname, 'support/entry-autoload-probe.php'), path.join(root, 'ci/support/entry-autoload-probe.php'));
  fs.writeFileSync(path.join(root, 'bin/php'), [
    '#!/usr/bin/env bash',
    'set -eu',
    'if [[ -n ${ENTRY_CLEANUP_FORCE_FAILURE:-} ]]; then',
    '  echo "ENTRY_CLEANUP_DIAGNOSTIC_BODY"',
    '  if [[ $ENTRY_CLEANUP_FORCE_FAILURE == bytes ]]; then',
    '    printf "%09000d\\n" 0',
    '    echo "ENTRY_CLEANUP_PAST_BYTE_CAP"',
    '  else',
    '    for ((i=0; i<60; i++)); do echo "bounded diagnostic line"; done',
    '    echo "ENTRY_CLEANUP_PAST_LINE_CAP"',
    '  fi',
    '  exit 71',
    'fi',
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
        ENTRY_CLEANUP_FORCE_FAILURE: failure === true ? '1' : failure || '' } });
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
  function failureDiagnostics(source, mode = true) {
    const failed = run(source, mode);
    assert.equal(failed.result.status, 71, 'failure exit is preserved');
    assert.ok(fs.existsSync(path.join(failed.fixture, 'baseline.log')), 'failed control retains its evidence');
    assert.ok(failed.result.stderr.includes('Failed control evidence retained:'), 'failure reports retained evidence');
    assert.ok(failed.result.stderr.includes('ENTRY_CLEANUP_DIAGNOSTIC_BODY'),
      'failed control did not emit the expected diagnostic body');
    assert.ok(!failed.result.stderr.includes('ENTRY_CLEANUP_PAST_LINE_CAP'), 'failure log line cap is enforced');
    assert.ok(!failed.result.stderr.includes('ENTRY_CLEANUP_PAST_BYTE_CAP'), 'failure log byte cap is enforced');
    assert.ok(failed.result.stderr.length < 9000, 'failure diagnostics remain byte bounded');
  }
  green(original);
  failureDiagnostics(original);
  failureDiagnostics(original, 'bytes');
  const needle = 'rm -rf -- "${control_dir:?}"';
  assert.equal(original.split(needle).length, 2, 'remove exactly one successful-cleanup action');
  const mutant = original.replace(needle, ': # deliberately omit successful cleanup');
  assert.throws(() => green(mutant), /successful entry control leaked its private fixture/);
  green(original);
  const logNeedle = 'head -c 8192 -- "$control_dir/$name" | sed -n \'1,40p\' >&2 || :';
  assert.equal(original.split(logNeedle).length, 2, 'remove exactly one failure-log emission');
  assert.throws(() => failureDiagnostics(original.replace(logNeedle, ': # omit failure diagnostic body')),
    /failed control did not emit the expected diagnostic body/);
  const artifact = fs.statSync(script);
  console.log(`control_command=bash ${script}`);
  console.log(`source_artifact_mtime=${artifact.mtime.toISOString()} source_artifact_bytes=${artifact.size}`);
  console.log(`source_artifact_sha256=${createHash('sha256').update(fs.readFileSync(script)).digest('hex')}`);
  console.log('observed red: failed control did not emit the expected diagnostic body');
  failureDiagnostics(original);
  green(original);
  console.log('OK: success cleanup, failure retention/status/bounded diagnostics; named mutations red and restored green');
} finally {
  fs.rmSync(root, { recursive: true, force: true });
}
