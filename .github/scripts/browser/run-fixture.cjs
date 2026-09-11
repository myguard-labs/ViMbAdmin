#!/usr/bin/env node
'use strict';
/* global require, process, console, URL, document */

// Adapt the existing dump-dom fixture interface to three browser engines.
// Invoked only inside the confined container by run-headless-chrome.sh.
// Inputs: engine, private fixture root, Chrome-style fixture arguments.
// Output: final DOM; diagnostics name the engine. No external network access.
// Side effects: one browser and, for HTTP fixtures, one loopback server; both
// are closed on success/failure. A missing verdict is a bounded hard failure.
const { chromium, firefox, webkit } = require('playwright');
const http = require('node:http');
const fs = require('node:fs/promises');
const path = require('node:path');
const { fileURLToPath } = require('node:url');

async function main() {
  const [engine, root, ...args] = process.argv.slice(2);
  if (engine === '--help') {
    console.log('Usage: run-fixture.cjs <chromium|firefox|webkit> <fixture-root> [Chrome fixture arguments]');
    return;
  }
  const engines = { chromium, firefox, webkit };
  if (!Object.hasOwn(engines, engine) || !path.isAbsolute(root || '')) {
    throw new Error('engine and absolute fixture root are required');
  }
  let target = args.find(arg => arg.startsWith('file://') || arg.startsWith('http://'));
  if (!target) throw new Error('fixture URL is required');
  const url = new URL(target);
  if (url.protocol === 'file:') {
    const filename = await fs.realpath(fileURLToPath(url));
    if (!filename.startsWith(`${root}/`)) {
      throw new Error('file URL is outside the fixture root');
    }
  } else if (url.origin !== 'http://127.0.0.1:8765') {
    throw new Error('HTTP URL is not the fixture loopback origin');
  }
  let server;
  let browserContext;
  try {
    if (url.protocol === 'http:') {
      // Binding port zero avoids collisions and readiness races. realpath keeps
      // requests, including symlinks and encoded traversal, within this fixture.
      server = http.createServer(async (request, response) => {
        try {
          const pathname = decodeURIComponent(new URL(request.url, 'http://localhost').pathname);
          const filename = await fs.realpath(path.join(root, pathname));
          if (!filename.startsWith(`${root}/`)) throw new Error('outside fixture');
          const body = await fs.readFile(filename);
          const types = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json' };
          response.writeHead(200, { 'Content-Type': `${types[path.extname(filename)] || 'application/octet-stream'}; charset=utf-8` });
          response.end(body);
        } catch {
          response.writeHead(404);
          response.end('fixture resource not found');
        }
      });
      await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
      });
      url.port = String(server.address().port);
      target = url.href;
    }
    // The compatibility lane deliberately checks cookies across invocations.
    // Keep state only in the already validated test-owned profile directory.
    browserContext = await engines[engine].launchPersistentContext(path.join(root, 'profile'), {
      headless: true, timeout: 15000
    });
    const page = browserContext.pages()[0];
    page.on('pageerror', error => console.error(`[${engine}] page error: ${error.message}`));
    await page.goto(target, { waitUntil: 'load', timeout: 15000 });
    // Both existing fixture families publish a terminal DOM verdict. Waiting
    // for it also accommodates real animation clocks outside Chromium.
    await page.waitForFunction(() => document.body && (
      ['pass', 'fail'].includes(document.body.dataset.testResult) ||
      ['PASS', 'FAIL'].includes(document.body.dataset.verdict)
    ), { }, { timeout: 10000 });
    const verdict = await page.evaluate(() => ({
      result: document.body.dataset.testResult || document.body.dataset.verdict,
      failures: document.body.dataset.testFailures || document.querySelector('#output')?.textContent || ''
    }));
    console.error(`[${engine}] fixture verdict: ${verdict.result} ${verdict.failures}`);
    process.stdout.write(await page.content());
  } finally {
    try {
      if (browserContext) await browserContext.close();
    } finally {
      if (server) await new Promise(resolve => server.close(resolve));
    }
  }
}

main().catch(error => {
  console.error(`[${process.argv[2] || 'unknown'}] FAIL: ${error.message}`);
  process.exitCode = 1;
});
