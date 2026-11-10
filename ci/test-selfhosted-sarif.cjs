#!/usr/bin/env node
'use strict';
/* global require, console, __dirname */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const yaml = require('yaml');
const workflow = yaml.parse(fs.readFileSync(path.join(__dirname, '../.github/workflows/security.yml'), 'utf8'),
  { uniqueKeys: true, maxAliasCount: 100 });
const uploadPin = 'actions/upload-artifact@cf430e030ddbb5b0abf93d22962f4752f3646cd9';
const downloadPin = 'actions/download-artifact@9000827ccba6bdab643e8b6fd33ac0654aef8333';
const hostedRunner = 'ubuntu-24.04';
const condition = "always() && github.event_name != 'pull_request' && needs.semgrep.outputs.sarif-artifact-id != ''";
function check(w) {
  assert.deepEqual(w.permissions, { contents: 'read' }, 'PR scan token must remain read-only');
  const scan = w.jobs.semgrep;
  assert.equal(scan.permissions, undefined, 'scan must inherit read-only token');
  assert.deepEqual(scan.outputs, { 'sarif-artifact-id': '${{ steps.sarif_artifact.outputs.artifact-id }}' });
  const produce = scan.steps.find(step => step.id === 'sarif_artifact');
  assert.equal(produce.uses, uploadPin);
  assert.equal(produce.if, "always() && github.event_name != 'pull_request'");
  assert.deepEqual(produce.with, {
    name: 'semgrep-${{ github.sha }}-${{ github.run_attempt }}', path: 'semgrep.sarif',
    'if-no-files-found': 'error', 'retention-days': 1
  });
  assert.equal(scan.steps.filter(step => step.uses?.startsWith('github/codeql-action/upload-sarif@')).length, 0);
  const publish = w.jobs['sarif-upload'];
  assert.equal(publish.if, condition, 'write token must be unreachable on PR');
  assert.equal(publish.needs, 'semgrep');
  assert.deepEqual(publish.permissions, { contents: 'read', 'security-events': 'write' });
  assert.equal(publish['runs-on'], hostedRunner, 'SARIF requires the standard hosted Linux runner');
  const download = publish.steps.find(step => step.uses === downloadPin);
  assert.ok(download, 'immutable download action required');
  // With no run-id, repository, token or name input the action can only select
  // the producer output ID from this run. Never consume an external artifact.
  assert.deepEqual(download.with, {
    'artifact-ids': '${{ needs.semgrep.outputs.sarif-artifact-id }}', path: 'sarif', 'merge-multiple': true
  });
  const upload = publish.steps.find(step => step.name === 'Upload SARIF');
  assert.equal(upload.uses, 'github/codeql-action/upload-sarif@33d7b85da1a04171e211befe2c739303b9a4c066');
  assert.deepEqual(upload.with, { sarif_file: 'sarif/semgrep.sarif', category: 'semgrep' });
}
check(workflow);
const controls = [
  w => { w.jobs.semgrep.steps.find(s => s.id === 'sarif_artifact').uses = 'actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02'; },
  w => { w.jobs['sarif-upload'].steps.find(s => s.uses === downloadPin).uses = 'actions/download-artifact@d3f86a106a0bac45b974a628896c90dbdf5c8093'; },
  w => { w.jobs['sarif-upload'].steps.find(s => s.name === 'Upload SARIF').uses = 'github/codeql-action/upload-sarif@2892aa5e19bbd11bc0cff5427e3b750a04d9e3c2'; },
  w => { w.permissions['security-events'] = 'write'; },
  w => { w.jobs.semgrep.permissions = { 'security-events': 'write' }; },
  w => { w.jobs.semgrep.outputs['sarif-artifact-id'] = '${{ steps.other.outputs.artifact-id }}'; },
  w => { w.jobs.semgrep.steps.find(s => s.id === 'sarif_artifact').if = 'always()'; },
  w => { w.jobs.semgrep.steps.find(s => s.id === 'sarif_artifact').with.path = '*'; },
  w => { w.jobs['sarif-upload'].if = 'always()'; },
  w => { w.jobs['sarif-upload'].needs = 'unrelated'; },
  w => { w.jobs['sarif-upload'].permissions.contents = 'write'; },
  w => { w.jobs['sarif-upload']['runs-on'] = 'ubuntu-latest'; },
  w => { w.jobs['sarif-upload']['runs-on'] = ['self-hosted', 'vimbadmin-owned-local-v1', 'docker', 'vimbadmin-pristine-jit-v1']; },
  w => { w.jobs['sarif-upload']['runs-on'] = ['self-hosted', 'vimbadmin-owned-local-v1', 'docker', 'jit-env-input']; },
  w => { w.jobs['sarif-upload'].steps.find(s => s.uses === downloadPin).with['run-id'] = 'external'; },
  w => { w.jobs['sarif-upload'].steps.find(s => s.uses === downloadPin).with['artifact-ids'] = '123'; },
  w => { w.jobs['sarif-upload'].steps.find(s => s.name === 'Upload SARIF').with.sarif_file = 'unbound.sarif'; }
];
for (const runner of ['ubuntu-24.04-large', 'self-hosted', ['ubuntu-24.04'], ['self-hosted', 'docker']]) {
  controls.push(w => { w.jobs['sarif-upload']['runs-on'] = runner; });
}
for (const [i, mutate] of controls.entries()) {
  const changed = JSON.parse(JSON.stringify(workflow));
  mutate(changed);
  assert.throws(() => check(changed), assert.AssertionError, `SARIF causal control ${i}`);
}
check(workflow);
console.log(`Hosted SARIF provenance and privilege controls passed (${controls.length} rejected mutations).`);
