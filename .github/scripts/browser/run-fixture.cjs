#!/usr/bin/env node
'use strict';
/* global require, process, console, URL, document, navigator */

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

function option(args, name) {
  const prefix = `${name}=`;
  return args.find(arg => arg.startsWith(prefix))?.slice(prefix.length);
}

function loopbackApplicationUrl(value, name) {
  const url = new URL(value);
  if (url.protocol !== 'http:' || url.hostname !== '127.0.0.1' || url.port === '' ||
      url.username !== '' || url.password !== '' || url.pathname !== '/' ||
      url.search !== '' || url.hash !== '') {
    throw new Error(`${name} must be an HTTP 127.0.0.1 origin with an explicit port`);
  }
  return url;
}

async function applicationMode(engines, engine, root, args) {
  const origin = loopbackApplicationUrl(option(args, '--application-origin'), 'application origin');
  const target = new URL(option(args, '--application-target') || origin.href);
  const suitePath = await fs.realpath(option(args, '--application-suite'));
  const inputPath = await fs.realpath(option(args, '--application-input'));
  const profilePath = await fs.realpath(option(args, '--application-profile'));
  const expectedEngine = option(args, '--application-expected-engine') || engine;
  const mutation = process.env.VIMBADMIN_BROWSER_APP_MUTATION || '';
  for (const [name, filename] of [
    ['application suite', suitePath],
    ['application input', inputPath],
    ['application profile', profilePath]
  ]) {
    if (!filename.startsWith(`${root}/`)) throw new Error(`${name} is outside the fixture root`);
  }
  if (target.protocol !== 'http:' || target.username !== '' || target.password !== '') {
    throw new Error('application target must be an HTTP URL without credentials');
  }
  if (target.origin !== origin.origin) {
    throw new Error('application target is outside the declared application origin');
  }

  let browserContext;
  try {
    browserContext = await engines[engine].launchPersistentContext(profilePath, {
      headless: true, timeout: 15000
    });
    const identityPage = browserContext.pages()[0];
    const identityResponse = await identityPage.goto(new URL('/auth/login', target).href, {
      waitUntil: 'load', timeout: 15000
    });
    if (!identityResponse) throw new Error('application engine probe returned no response');
    const actualEngine = await identityPage.evaluate(() => {
      const ua = navigator.userAgent;
      return /Firefox\//.test(ua) ? 'firefox' : /Chrome\//.test(ua) ? 'chromium' :
        /AppleWebKit\//.test(ua) ? 'webkit' : 'unknown';
    });
    if (actualEngine !== expectedEngine) {
      throw new Error(`application engine assertion: expected ${expectedEngine}, got ${actualEngine}`);
    }

    // The suite is copied into the private fixture root and is the only code
    // allowed to drive application mode. Unlike static mode, no synthetic DOM
    // verdict is consulted and no rendered HTML (which can contain tokens) is
    // written to stdout.
    const suite = require(suitePath);
    if (typeof suite.runApplicationSuite !== 'function') {
      throw new Error('application suite must export runApplicationSuite');
    }
    const input = JSON.parse(await fs.readFile(inputPath, 'utf8'));
    const result = await suite.runApplicationSuite({
      browserContext,
      engine,
      expectedEngine,
      origin: origin.origin,
      targetOrigin: target.origin,
      root,
      input,
      mutation
    });
    process.stdout.write(`${JSON.stringify({ engine, application: true, result })}\n`);
  } finally {
    if (browserContext) await browserContext.close();
  }
}

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
  if (option(args, '--application-origin')) {
    await applicationMode(engines, engine, root, args);
    return;
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
