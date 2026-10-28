#!/usr/bin/env node
'use strict';
/* global require, module, process, document, window, getComputedStyle, URL, console */

const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');

function controlGeometry(node) {
  const rect = node.getBoundingClientRect();
  const hit = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);
  return { x: rect.x, right: rect.right, top: rect.top, bottom: rect.bottom,
    width: rect.width, height: rect.height, hit: hit === node || node.contains(hit) };
}

// Executed in the page; return geometry/styles and control identifiers only.
function appearance() {
  const pick = selector => {
    const node = document.querySelector(selector);
    if (!node) return null;
    const style = getComputedStyle(node);
    return { color: style.color, background: style.backgroundColor, border: style.borderTopColor,
      outline: style.outlineStyle, boxShadow: style.boxShadow };
  };
  const legacy = ['.input-prepend', '.input-append', '.nav-tabs > li > a', '.popover .arrow', '.pagination ul',
    '.control-group.error input', '.dataTables_filter input', '.pager'];
  const darkSheet = [...document.styleSheets].find(sheet => sheet.href?.includes('/_skins/dark/skin.css'));
  const cascade = darkSheet ? [...darkSheet.cssRules].filter(rule => rule.selectorText).map(rule => ({
    selector: rule.selectorText,
    matches: [...document.querySelectorAll(rule.selectorText)].slice(0, 3).map(node => ({
      tag: node.tagName, properties: Object.fromEntries([...rule.style].map(property =>
        [property, getComputedStyle(node).getPropertyValue(property)]))
    }))
  })) : [];
  const missingLabels = [...document.querySelectorAll('input:not([type="hidden"]),textarea,select')]
    .filter(node => node.getClientRects().length && !node.labels?.length && !node.getAttribute('aria-label') &&
      !node.getAttribute('aria-labelledby') && !node.title && !['submit', 'button'].includes(node.type))
    .map(node => ({ tag: node.tagName, type: node.type, id: node.id }));
  return { body: pick('body'), heading: pick('.page-header h1'), button: pick('.btn'),
    headerCell: pick('.table thead th'), bodyCell: pick('.table tbody td'),
    inputGroup: pick('.input-group-text'), focused: pick(':focus'), legacyMatches: Object.fromEntries(legacy.map(selector =>
      [selector, document.querySelectorAll(selector).length])),
    horizontalOverflow: document.documentElement.scrollWidth - window.innerWidth,
    missingLabels, cascade };
}

async function armModalHidden(page) {
  await page.locator('#modal_dialog_shell').evaluate(node => {
    window.__visualModalHiddenDone = false;
    node.addEventListener('hidden.bs.modal', () => { window.__visualModalHiddenDone = true; }, { once: true });
  });
}

async function waitModalHidden(page) {
  await page.waitForFunction(() => window.__visualModalHiddenDone === true);
}

async function checkFocusNegativeControl(page, trigger, dialog) {
  const boundary = await page.evaluate(() => {
    const shell = document.createElement('div');
    let completed = false;
    shell.addEventListener('hidden.bs.modal', () => { completed = true; }, { once: true });
    const before = completed;
    shell.dispatchEvent(new window.Event('hidden.bs.modal'));
    return [before, completed];
  });
  assert.deepEqual(boundary, [false, true], 'hidden completion is gated on the event');
  await trigger.focus();
  await page.keyboard.press('Enter');
  await dialog.waitFor();
  await page.waitForFunction(() => document.querySelector('#modal_dialog_shell')?.contains(document.activeElement));
  assert.ok(await dialog.evaluate(node => node.contains(document.activeElement)), 'negative control starts with modal focus');
  await armModalHidden(page);
  await trigger.evaluate(node => { node.focus = () => {}; });
  try {
    await page.keyboard.press('Escape');
    await waitModalHidden(page);
    const restored = await trigger.evaluate(node => node === document.activeElement);
    assert.throws(() => assert.ok(restored, 'mailbox modal restores opener focus'), /mailbox modal restores opener focus/);
  } finally {
    await trigger.evaluate(node => { delete node.focus; });
  }
  await trigger.focus();
  await page.keyboard.press('Enter');
  await dialog.waitFor();
  await page.waitForFunction(() => document.querySelector('#modal_dialog_shell')?.contains(document.activeElement));
  await armModalHidden(page);
  await page.keyboard.press('Escape');
  await waitModalHidden(page);
  assert.ok(await trigger.evaluate(node => node === document.activeElement), 'restored focus handler passes after negative control');
  console.log('PASS: missing-restoration negative control and restored focus');
}

// These are focused checks of real rendered surfaces, not WCAG certification
// or a pixel baseline for an unknown operator's deployment/custom skin.
async function runApplicationSuite({ browserContext, targetOrigin, root, input, engine }) {
  assert.equal(input.mode, 'full');
  const skin = process.env.VIMBADMIN_VISUAL_SKIN;
  assert.ok(['default', 'dark'].includes(skin), 'explicit visual skin is required');
  const artifacts = process.env.VIMBADMIN_VISUAL_ARTIFACTS;
  assert.ok(artifacts.startsWith(`${root}/visual-artifacts/`));
  const ids = JSON.parse(await fs.readFile(`${input.stateDirectory}/tenant-ids.json`, 'utf8'));
  const report = { engine, skin, surfaces: [], limits: [
    'Fixture routes and named controls only; no deployment or comprehensive WCAG claim.',
    'Operator custom skin myskin is inventoried but is outside default/dark coverage.'
  ] };
  const screenshotMask = 'img[alt="TOTP QR code"], .page-content code, .page-content pre, input[type="password"]';
  report.screenshotMasks = screenshotMask;
  const surfaces = [
    ['maintenance', 'tenant_super', '/maintenance/index', 'form[action$="/maintenance/check-update"] button'],
    ['login', 'anonymous', '/auth/login', 'form[action="/auth/login"] button[type="submit"]'],
    ['lost-password', 'anonymous', '/auth/lost-password', 'form button[type="submit"]'],
    ['change-password', 'anonymous', '/auth/change-password', 'form button[type="submit"]'],
    ['totp', 'pending_totp', '/auth/totp', 'form button[type="submit"]'],
    ['totp-setup', 'pending_setup', '/auth/totp-setup', 'form button[type="submit"]'],
    ['admins', 'tenant_super', '/admin/list', '#add_admin'],
    ['admin-add', 'tenant_super', '/admin/add', 'form button[type="submit"]'],
    ['admin-password', 'tenant_super', `/admin/password/aid/${ids.admin.super}`, 'form button[type="submit"]'],
    ['admin-two-factor', 'tenant_super', '/admin/two-factor', 'form button[type="submit"]'],
    ['domains', 'tenant_super', '/domain/list', '#add_domain'],
    ['assigned-domains', 'tenant_assigned', '/domain/list', `#list_table a[href$="/mailbox/list/did/${ids.domain.owned}"]`],
    ['empty-domains', 'tenant_unassigned', '/domain/list', '#list_table_wrapper input[type="search"]'],
    ['domain-add', 'tenant_super', '/domain/add', 'form button[type="submit"]'],
    ['domain-edit', 'tenant_super', `/domain/edit/did/${ids.domain.owned}`, 'form button[type="submit"]'],
    ['domain-admins', 'tenant_super', `/domain/admins/did/${ids.domain.owned}`, '#add_admin'],
    ['mailboxes', 'tenant_assigned', `/mailbox/list/did/${ids.domain.owned}`, '#add_mailbox'],
    ['mailbox-add', 'tenant_assigned', `/mailbox/add/did/${ids.domain.owned}`, 'form button[type="submit"]'],
    ['mailbox-edit', 'tenant_assigned', `/mailbox/edit/mid/${ids.mailbox.owned}`, 'form button[type="submit"], form input[type="submit"]'],
    ['mailbox-password', 'tenant_assigned', `/mailbox/password/mid/${ids.mailbox.owned}`, 'form button[type="submit"]'],
    ['aliases', 'tenant_assigned', `/alias/list/did/${ids.domain.owned}`, '#add_alias'],
    ['alias-add', 'tenant_assigned', `/alias/add/did/${ids.domain.owned}`, 'form button[type="submit"]'],
    ['archives', 'tenant_super', '/archive/list', '#list_table_wrapper input[type="search"]'],
    ['logs', 'tenant_super', '/log/list', '#list_table_wrapper input[type="search"]'],
    ['queue', 'tenant_super', '/queue/index', 'form[action$="/queue/run-now"] button']
  ];
  for (const [name, role, route, actionSelector] of surfaces) {
    const state = JSON.parse(await fs.readFile(`${input.stateDirectory}/${role}.json`, 'utf8'));
    await browserContext.clearCookies();
    await browserContext.addCookies(state.cookies);
    const page = await browserContext.newPage();
    try {
      for (const width of [1440, 768, 375]) {
        await page.setViewportSize({ width, height: 900 });
        const response = await page.goto(`${targetOrigin}${route}`, { waitUntil: 'load', timeout: 15000 });
        assert.equal(response.status(), 200, `${name}: real application response`);
        assert.equal(new URL(page.url()).pathname, route, `${name}: route did not redirect`);
        await page.locator('.page-content').waitFor();
        const sheets = await page.locator('link[rel="stylesheet"]').evaluateAll(nodes => nodes.map(node => ({
          href: new URL(node.href).pathname, loaded: node.sheet !== null
        })));
        assert.ok(sheets.every(sheet => sheet.loaded), `${name}: all stylesheets loaded`);
        if (skin === 'dark') {
          assert.equal(sheets.at(-1).href, '/css/_skins/dark/skin.css', 'dark skin stylesheet is loaded last');
        } else {
          assert.ok(sheets.every(sheet => !sheet.href.includes('/_skins/')), 'default skin has no skin stylesheet');
        }
        const toggle = page.locator('.navbar-toggler');
        assert.equal(await toggle.getAttribute('aria-label'), 'Toggle navigation',
          'navigation toggle has accessible name and keyboard focus');
        assert.notEqual(await toggle.getAttribute('tabindex'), '-1',
          'navigation toggle has accessible name and keyboard focus');
        let navigationToggle = null;
        if (width < 992) {
          assert.ok(await toggle.isVisible(), `navigation toggle is visible at ${width}`);
          navigationToggle = await toggle.evaluate(controlGeometry);
          assert.ok(navigationToggle.x >= -1 && navigationToggle.right <= width + 1 &&
            navigationToggle.top >= -1 && navigationToggle.bottom <= 901 &&
            navigationToggle.width > 0 && navigationToggle.height > 0 && navigationToggle.hit,
          `navigation toggle is not clipped or covered at ${width}: ${JSON.stringify(navigationToggle)}`);
          await toggle.focus();
          assert.ok(await toggle.evaluate(node => node === document.activeElement), 'responsive toggle accepts focus');
          await page.keyboard.press('Enter');
          await page.locator('#vimbadmin-navbar.show').waitFor();
          await page.locator('#vimbadmin-navbar.collapsing').waitFor({ state: 'hidden' });
          assert.equal(await toggle.getAttribute('aria-expanded'), 'true', 'responsive navigation announces expanded state');
          await page.keyboard.press('Enter');
          await page.locator('#vimbadmin-navbar.show').waitFor({ state: 'hidden' });
          await page.locator('#vimbadmin-navbar.collapsing').waitFor({ state: 'hidden' });
        }
        const action = page.locator(actionSelector).first();
        if (name === 'maintenance') {
          assert.ok(await action.isVisible(), 'visual action is visible: maintenance release check');
        }
        await assert.doesNotReject(() => action.waitFor({ state: 'visible', timeout: 10000 }),
          `${name}: primary action visible at ${width}`);
        await action.scrollIntoViewIfNeeded();
        const geometry = await action.evaluate(controlGeometry);
        assert.ok(geometry.x >= -1 && geometry.right <= width + 1 && geometry.width > 0 &&
          geometry.height > 0 && geometry.hit, `${name}: action is not clipped or covered at ${width}: ${JSON.stringify(geometry)}`);
        await action.focus();
        assert.ok(await action.evaluate(node => node === document.activeElement), `${name}: action accepts focus`);
        const computed = await page.evaluate(appearance);
        assert.equal(computed.body.background, skin === 'dark' ? 'rgb(11, 18, 32)' : 'rgb(233, 236, 239)',
          `${name}: computed body skin color`);
        if (skin === 'dark') assert.equal(computed.heading.color, 'rgb(230, 237, 246)', `${name}: heading cascade`);
        const colors = [computed.body.color, computed.body.background].map(value => value.match(/[\d.]+/g).map(Number));
        const luminance = rgb => rgb.slice(0, 3).map(channel => channel / 255)
          .map(channel => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4)
          .reduce((total, channel, index) => total + channel * [0.2126, 0.7152, 0.0722][index], 0);
        const levels = colors.map(luminance).sort((a, b) => a - b);
        computed.bodyTextContrast = (levels[1] + 0.05) / (levels[0] + 0.05);
        assert.ok(computed.bodyTextContrast >= 4.5, `${name}: body text contrast is at least 4.5`);
        const hoverTarget = page.locator('.btn').first();
        if (await hoverTarget.isVisible()) {
          await hoverTarget.hover();
          computed.hoveredButton = await hoverTarget.evaluate(node => ({
            color: getComputedStyle(node).color, background: getComputedStyle(node).backgroundColor,
            border: getComputedStyle(node).borderTopColor
          }));
        }
        if (name === 'mailboxes') {
          const trigger = page.locator(`#modal-dialog-mailbox_settings_${ids.mailbox.owned}`);
          await trigger.focus();
          await page.keyboard.press('Enter');
          const dialog = page.locator('#modal_dialog_shell.show');
          await dialog.waitFor();
          await page.locator('#email_settings_dialog_title').waitFor();
          await page.locator('#modal_dialog_shell').evaluate(node => new Promise(resolve => {
            if (!node.classList.contains('show')) return resolve();
            const animations = node.getAnimations({ subtree: true });
            Promise.all(animations.map(animation => animation.finished)).then(resolve);
          }));
          assert.equal(await dialog.getAttribute('aria-labelledby'), 'email_settings_dialog_title', 'mailbox modal has accessible title');
          assert.ok(await dialog.evaluate(node => node.contains(document.activeElement)), 'mailbox modal traps initial focus');
          const bounds = await dialog.locator('.modal-dialog').boundingBox();
          assert.ok(bounds.x >= -1 && bounds.x + bounds.width <= width + 1, 'mailbox modal fits responsive viewport');
          await page.screenshot({ path: path.join(artifacts, `mailbox-modal-${width}.png`), fullPage: true,
            mask: [page.locator(screenshotMask)] });
          await armModalHidden(page);
          await page.keyboard.press('Escape');
          await waitModalHidden(page);
          assert.ok(await trigger.evaluate(node => node === document.activeElement), 'mailbox modal restores opener focus');
          if (width === 1440) await checkFocusNegativeControl(page, trigger, dialog);
          computed.modal = { named: true, fitsViewport: true, focusRestored: true };
        }
        // Only geometry/computed values and public routes enter the report.
        // Do not serialize DOM/forms: the real app contains fixture CSRF tokens.
        const screenshot = `${name}-${width}.png`;
        await page.screenshot({ path: path.join(artifacts, screenshot), fullPage: true,
          mask: [page.locator(screenshotMask)] });
        report.surfaces.push({ name, role, route, width, actionSelector, navigationToggle, computed, screenshot, sheets });
      }
    } catch (error) {
      await page.screenshot({ path: path.join(artifacts, `${name}-failure.png`), fullPage: true,
        mask: [page.locator(screenshotMask)] });
      throw error;
    } finally {
      await page.close();
      await fs.writeFile(path.join(artifacts, 'report.json'), `${JSON.stringify(report, null, 2)}\n`);
    }
  }
  assert.equal(report.surfaces.length, surfaces.length * 3, 'every inventoried visual surface and viewport observed');
  return { skin, surfaces: report.surfaces.length, report: 'report.json' };
}

module.exports = { runApplicationSuite };
