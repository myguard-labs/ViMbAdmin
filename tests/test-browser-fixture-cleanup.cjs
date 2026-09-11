#!/usr/bin/env node
'use strict';
/* global require, __dirname, process, URL */

// Run with node tests/test-browser-fixture-cleanup.cjs [--negative-control].
// Controls mutate cleanup in memory; production files stay intact.
// --negative-control restores sequential cleanup; --omit-server-close checks all paths.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const adapter = path.join(__dirname, '../.github/scripts/browser/run-fixture.cjs');
let source = fs.readFileSync(adapter, 'utf8');
if (process.argv.includes('--negative-control')) {
  const fixed = `    try {
      if (browserContext) await browserContext.close();
    } finally {
      if (server) await new Promise(resolve => server.close(resolve));
    }`;
  assert.equal(source.split(fixed).length, 2, 'control must replace exactly one cleanup block');
  source = source.replace(fixed, `    if (browserContext) await browserContext.close();
    if (server) await new Promise(resolve => server.close(resolve));`);
}
if (process.argv.includes('--omit-server-close')) {
  const close = 'if (server) await new Promise(resolve => server.close(resolve));';
  assert.equal(source.split(close).length, 2, 'control must remove exactly one server close');
  source = source.replace(close, '// Server cleanup deliberately omitted by negative control.');
}

for (const failure of ['none', 'close', 'navigation', 'launch']) {
  test(`HTTP fixture cleanup: ${failure}`, async () => {
    let server;
    let serverCloseCalls = 0;
    let contextCloseCalls = 0;
    let launchCalls = 0;
    let navigationCalls = 0;
    const diagnostics = [];
    const output = [];
    const injectedError = new Error(`injected ${failure} failure`);
    const fakeProcess = {
      argv: ['node', adapter, 'chromium', __dirname,
        'http://127.0.0.1:8765/test-browser-fixture-adapter.sh'],
      exitCode: 0,
      stdout: { write: value => output.push(value) }
    };
    const page = {
      on() {},
      async goto(target) {
        navigationCalls++;
        // Exercise the actual listener before injecting a browser failure.
        await new Promise((resolve, reject) => {
          http.get(target, { agent: false }, response => {
            response.resume();
            response.on('end', () => {
              try {
                assert.equal(response.statusCode, 200);
                resolve();
              } catch (error) { reject(error); }
            });
            response.on('error', reject);
          }).on('error', reject);
        });
        if (failure === 'navigation') throw injectedError;
      },
      async waitForFunction() {},
      async evaluate() { return { result: 'pass', failures: '' }; },
      async content() { return '<body data-test-result="pass"></body>'; }
    };
    const engine = {
      async launchPersistentContext() {
        launchCalls++;
        assert.equal(server.listening, true, 'server must be live before browser launch');
        if (failure === 'launch') throw injectedError;
        return {
          pages: () => [page],
          async close() {
            contextCloseCalls++;
            if (failure === 'close') throw injectedError;
          }
        };
      }
    };
    try {
      const completion = vm.runInNewContext(source, {
        URL,
        process: fakeProcess,
        console: { error: message => diagnostics.push(message) },
        require(name) {
          if (name === 'playwright') return { chromium: engine, firefox: engine, webkit: engine };
          if (name === 'node:http') return {
            createServer(handler) {
              assert.equal(server, undefined, 'only one fixture server may be created');
              server = http.createServer(handler);
              const close = server.close;
              server.close = function (...args) {
                serverCloseCalls++;
                return close.apply(this, args);
              };
              return server;
            }
          };
          return require(name);
        }
      }, { filename: adapter });
      assert.equal(typeof completion?.then, 'function', 'await the actual main().catch promise');
      await completion;
      assert.equal(launchCalls, 1);
      assert.equal(navigationCalls, failure === 'launch' ? 0 : 1);
      assert.equal(contextCloseCalls, failure === 'launch' ? 0 : 1);
      assert.equal(fakeProcess.exitCode, failure === 'none' ? 0 : 1);
      assert.deepEqual(diagnostics.filter(line => line.includes('FAIL:')),
        failure === 'none' ? [] : [`[chromium] FAIL: ${injectedError.message}`],
        'the original error must survive cleanup');
      assert.equal(output.length, ['none', 'close'].includes(failure) ? 1 : 0);
      assert.equal(server.listening, false, 'fixture HTTP server must stop listening even when context.close rejects');
      assert.equal(serverCloseCalls, 1, 'adapter must close the fixture HTTP server exactly once');
    } finally {
      // Bypass the observer: rescue a leaked server without masking the assertion.
      if (server?.listening) {
        await new Promise(resolve => http.Server.prototype.close.call(server, resolve));
      }
    }
  });
}
