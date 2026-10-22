#!/usr/bin/env node
'use strict';
/* global require, process, console, __dirname, __filename, structuredClone */

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const inventoryPath = path.join(__dirname, 'browser-visual-inventory.json');

function verify(inventory) {
  const routes = [];
  const kernel = fs.readFileSync(path.join(root, 'src/Kernel/Http/Kernel.php'), 'utf8');
  const map = kernel.split('public const NATIVE_CONTROLLERS = [')[1].split('];')[0];
  for (const [, controller, cls] of map.matchAll(/'([a-z]+)'\s*=>\s*\\?([A-Za-z]+Controller)::class/g)) {
    const filename = `${controller === 'mcp' ? 'application/controllers' : 'src/Kernel/Controller'}/${cls}.php`;
    const source = fs.readFileSync(path.join(root, filename), 'utf8');
    for (const [, action] of source.matchAll(/^\s*public function ([A-Za-z][A-Za-z0-9]*)Action\s*\(/gm)) {
      routes.push(`${controller}/${action.replace(/[A-Z]/g, letter => `-${letter.toLowerCase()}`)}`);
    }
  }
  routes.push('kernel-health/*');
  assert.deepEqual(inventory.routes.toSorted(), routes.toSorted(), 'complete route inventory');
  assert.deepEqual(inventory.roles.toSorted(), ['admin-assigned-domain', 'admin-unassigned-domain', 'anonymous',
    'mcp-bearer', 'pending-2fa', 'queue-bearer', 'super-admin'], 'complete role inventory');
  const skins = new Set(['light-default']);
  for (const directory of ['application/views/_skins', 'public/css/_skins']) {
    for (const entry of fs.readdirSync(path.join(root, directory), { withFileTypes: true })) {
      if (entry.isDirectory()) skins.add(entry.name);
    }
  }
  assert.deepEqual(inventory.skins.toSorted(), [...skins].toSorted(), 'complete skin inventory');
  assert.ok(Object.keys(inventory.source_sha256).length > 12, 'source bindings are present');
  for (const [filename, digest] of Object.entries(inventory.source_sha256)) {
    assert.equal(crypto.createHash('sha256').update(fs.readFileSync(path.join(root, filename))).digest('hex'),
      digest, `stale source inventory: ${filename}`);
  }
}

try {
  const inventory = JSON.parse(fs.readFileSync(process.argv[2] || inventoryPath, 'utf8'));
  verify(inventory);
  if (process.argv[2]) {
    console.log('PASS: complete current route/role/skin inventory');
  } else {
    const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'vimbadmin-visual-inventory-'));
    try {
      for (const [name, change, assertion] of [
        ['omitted-route', row => row.routes.pop(), 'complete route inventory'],
        ['omitted-role', row => row.roles.pop(), 'complete role inventory'],
        ['omitted-skin', row => row.skins.pop(), 'complete skin inventory'],
        ['stale-source', row => { row.source_sha256['src/Kernel/Router.php'] = '0'.repeat(64); }, 'stale source inventory:']
      ]) {
        const candidate = structuredClone(inventory);
        change(candidate);
        const filename = path.join(scratch, `${name}.json`);
        fs.writeFileSync(filename, `${JSON.stringify(candidate)}\n`);
        const stat = fs.statSync(filename);
        console.log(`command=${JSON.stringify([process.execPath, __filename, filename])}`);
        console.log(`artifact_mtime=${stat.mtime.toISOString()} artifact_bytes=${stat.size} sha256=${crypto.createHash('sha256').update(fs.readFileSync(filename)).digest('hex')}`);
        const child = spawnSync(process.execPath, [__filename, filename], { encoding: 'utf8' });
        assert.equal(child.status, 1, `${name}: child observed red`);
        assert.ok(child.stderr.includes(assertion), `${name}: named assertion observed`);
        console.log(`RED ${name}: ${child.stderr.trim()}`);
      }
      verify(inventory);
      console.log(`PASS: restored inventory ${inventory.routes.length} routes, ${inventory.roles.length} roles, ${inventory.skins.length} skins`);
    } finally { fs.rmSync(scratch, { recursive: true }); }
  }
} catch (error) {
  console.error(error.message.split('\n')[0]);
  process.exitCode = 1;
}
