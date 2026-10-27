#!/usr/bin/env node
/**
 * Real-Chromium end-to-end test of the zero-touch PWA update (no clicks, no reinstall).
 *
 * It starts the PHP built-in server over the real portal/public tree (portal/scripts/fixtures/e2e/router.php,
 * no database) and drives Chrome like a customer's installed app:
 *
 *   1. an OLD service worker generation (real historical sw.js files from fixtures/sw-legacy) is installed and
 *      controls the page, the deploy happens (the old /sw.js bytes are replaced by the real one), the customer
 *      just opens the app: it must end up on the new build by itself and the old caches must be gone
 *   2. a stale page that is already open reloads itself once when idle, defers while the customer is typing,
 *      and never loops
 *   3. offline: the offline page appears and returns to the app by itself when the network is back
 *   4. slow network: a version check slower than the timeout neither blocks the page nor breaks the next check
 *   5. the customer login form still appears (plain POST, relative redirect) when login-page.js never loads
 *   6. the five-tap status panel opens and reports the live state
 *   7. login after a legacy host-only session cookie (see CookieDeduper): signed in after the redirect
 *
 * Setup (not part of the repo's dependencies):
 *   npm install --no-save playwright-core        # in any directory, e.g. /tmp/pw-e2e
 *   PLAYWRIGHT_CORE_PATH=/tmp/pw-e2e/node_modules/playwright-core CHROME_PATH=/usr/local/bin/google-chrome \
 *     node portal/scripts/test-pwa-update-e2e.mjs
 * Optional: E2E_BASELINE_ROOT=<portal dir of an older checkout> adds a "before the fix" run of scenario 7;
 *           E2E_ARTIFACTS=<dir> keeps screenshots (default: a temp dir).
 * Skips (exit 0) when playwright-core or Chrome is not available.
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { pathToFileURL, fileURLToPath } from 'node:url';

const scriptsDir = path.dirname(fileURLToPath(import.meta.url));
const PORTAL_DIR = path.join(scriptsDir, '..');
const ROUTER = path.join(scriptsDir, 'fixtures', 'e2e', 'router.php');
const FIXTURES_DIR = path.join(scriptsDir, 'fixtures', 'sw-legacy');
const ARTIFACTS = process.env.E2E_ARTIFACTS || fs.mkdtempSync(path.join(os.tmpdir(), 'pwa-e2e-artifacts-'));
fs.mkdirSync(ARTIFACTS, { recursive: true });

const requireCjs = createRequire(import.meta.url);
const statusPanel = requireCjs(path.join(PORTAL_DIR, 'public', 'assets', 'pwa-status.js'));
const UP_TO_DATE_LABEL = statusPanel.verdictInfo('UP TO DATE').label;

const skip = (reason) => {
  console.log(`SKIP pwa update e2e: ${reason}`);
  process.exit(0);
};

const loadPlaywright = async () => {
  const candidates = [process.env.PLAYWRIGHT_CORE_PATH, 'playwright-core', '/tmp/pw-e2e/node_modules/playwright-core'].filter(Boolean);
  for (const candidate of candidates) {
    try {
      if (candidate.startsWith('/')) {
        const pkg = JSON.parse(fs.readFileSync(path.join(candidate, 'package.json'), 'utf8'));
        const entry = typeof pkg.main === 'string' ? pkg.main : 'index.js';
        return (await import(pathToFileURL(path.join(candidate, entry)).href)).default ?? (await import(pathToFileURL(path.join(candidate, entry)).href));
      }
      return await import(candidate);
    } catch {
      /* try the next one */
    }
  }
  return null;
};

const playwright = await loadPlaywright();
if (!playwright) skip('playwright-core not found (npm install --no-save playwright-core, then set PLAYWRIGHT_CORE_PATH)');
const chromium = playwright.chromium ?? playwright.default?.chromium;
const chromePath = [process.env.CHROME_PATH, '/usr/local/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser']
  .filter(Boolean)
  .find((candidate) => fs.existsSync(candidate));
if (!chromePath) skip('no Chrome/Chromium executable (set CHROME_PATH)');

const freePort = () =>
  new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address();
      server.close(() => resolve(port));
    });
  });

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const startPhp = async (env) => {
  const port = await freePort();
  const sessions = path.join(env.E2E_STATE_DIR ?? os.tmpdir(), `sessions-${port}`);
  fs.mkdirSync(sessions, { recursive: true });
  const child = spawn('php', ['-d', `session.save_path=${sessions}`, '-S', `127.0.0.1:${port}`, '-t', path.join(PORTAL_DIR, 'public'), ROUTER], {
    env: { ...process.env, PORTAL_FORCE_HTTPS: '0', PORTAL_APP_URL: `http://127.0.0.1:${port}`, ...env },
    stdio: 'ignore',
  });
  for (let i = 0; i < 60; i += 1) {
    try {
      const response = await fetch(`http://127.0.0.1:${port}/api/app-version.php`);
      if (response.ok) return { port, child };
    } catch {
      /* not up yet */
    }
    await sleep(100);
  }
  child.kill();
  throw new Error('php -S did not start');
};

const failures = [];
let passed = 0;
const check = async (name, fn) => {
  const started = Date.now();
  try {
    const detail = await fn();
    passed += 1;
    console.log(`ok   ${name}${detail ? ` - ${detail}` : ''} (${Date.now() - started} ms)`);
  } catch (error) {
    failures.push(name);
    console.log(`FAIL ${name}: ${error && error.message ? error.message : error}`);
  }
};
const expect = (condition, message) => {
  if (!condition) throw new Error(message);
};

/** Polls an (async) page function until it returns something truthy. waitForFunction does not await async predicates reliably. */
const until = async (page, fn, { timeout = 30000, interval = 250, label = 'condition' } = {}) => {
  const deadline = Date.now() + timeout;
  let last = null;
  while (Date.now() < deadline) {
    try {
      last = await page.evaluate(fn);
      if (last) return last;
    } catch (error) {
      last = `evaluate failed: ${error.message}`;
    }
    await sleep(interval);
  }
  throw new Error(`timed out waiting for ${label}; last value: ${JSON.stringify(last)}`);
};

const stateDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pwa-e2e-state-'));
const legacyFile = path.join(stateDir, 'legacy-sw.js');
const main = await startPhp({ E2E_STATE_DIR: stateDir });
const base = `http://127.0.0.1:${main.port}`;
const serverBuild = async () => (await (await fetch(`${base}/api/app-version.php`)).json()).build;
const LEGACY_PATTERN = /^jawish-v\d+/;

const browser = await chromium.launch({
  executablePath: chromePath,
  headless: true,
  args: ['--no-sandbox', '--host-resolver-rules=MAP www.jawishco.test 127.0.0.1, MAP jawishco.test 127.0.0.1'],
});
console.log(`Chrome ${browser.version()} | php -S ${base} | artifacts: ${ARTIFACTS}`);

const registrationInfo = (page) =>
  page.evaluate(async () => {
    const registrations = await navigator.serviceWorker.getRegistrations();
    return {
      count: registrations.length,
      active: registrations[0]?.active?.scriptURL ?? null,
      waiting: registrations[0]?.waiting?.scriptURL ?? null,
      installing: registrations[0]?.installing?.scriptURL ?? null,
      controller: navigator.serviceWorker.controller?.scriptURL ?? null,
      caches: await caches.keys(),
    };
  });

const verdict = (page) => page.evaluate(async () => (await window.PortalPwaUpdate.getStatus()).verdict);

/** Installs `fixture` as the live worker, "deploys", opens the app, returns once it is up to date. */
const legacyTakeover = async (fixture) => {
  fs.copyFileSync(path.join(FIXTURES_DIR, fixture.file), legacyFile);
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto(`${base}/e2e/legacy.html?v=${fixture.cacheVersion.replace('jawish-v', '')}`);
  await page.waitForFunction(() => document.title === 'legacy-ready', null, { timeout: 20000 });
  await page.reload();
  await page.waitForFunction(() => !!navigator.serviceWorker.controller, null, { timeout: 10000 });
  const before = await registrationInfo(page);
  expect(!/[?&]b=/.test(before.controller ?? ''), `fixture ${fixture.file} should be a legacy worker, got ${before.controller}`);

  fs.unlinkSync(legacyFile);
  const build = await serverBuild();
  const opened = Date.now();
  await page.goto(`${base}/e2e/page.html`);
  await page.waitForFunction(() => /[?&]b=/.test(navigator.serviceWorker.controller?.scriptURL ?? ''), null, { timeout: 30000, polling: 50 });
  const takeover = Date.now() - opened;
  await until(page, async () => (await window.PortalPwaUpdate.getStatus()).verdict === 'UP TO DATE', { label: 'verdict UP TO DATE' });
  const elapsed = takeover;
  // The new worker answers (and controls the page) as soon as it activates; the purge of the old caches finishes a moment later.
  await until(page, async () => !(await caches.keys()).some((name) => /^jawish-v\d+/.test(name)), { timeout: 10000, interval: 100, label: 'old caches purged' });
  const purged = Date.now() - opened;
  const after = await registrationInfo(page);
  expect(after.count === 1, `exactly one registration expected, got ${after.count}`);
  expect(after.active?.endsWith(`/sw.js?b=${build}`), `active worker should be /sw.js?b=${build}, got ${after.active}`);
  expect(after.controller?.endsWith(`/sw.js?b=${build}`), `controller should be the new worker, got ${after.controller}`);
  expect(!after.waiting && !after.installing, 'nothing may be left waiting/installing');
  expect(!after.caches.some((name) => LEGACY_PATTERN.test(name)), `old caches must be gone, still have ${after.caches.join(', ')}`);
  expect(after.caches.includes(`jawish-b-${build}-static`), `new cache missing: ${after.caches.join(', ')}`);
  return { context, page, build, elapsed, purged, before, after };
};

const fixtures = JSON.parse(fs.readFileSync(path.join(FIXTURES_DIR, 'MANIFEST.json'), 'utf8')).fixtures;
const production = fixtures.find((entry) => entry.file.startsWith('v24'));
let live = null;

console.log('\n== 1. old worker generations take over by themselves ==');
for (const fixture of fixtures) {
  await check(`${fixture.cacheVersion} (${fixture.file}) -> new build, no clicks`, async () => {
    const result = await legacyTakeover(fixture);
    if (fixture === production) {
      live = result;
    } else {
      await result.context.close();
    }
    return `new worker controls the page after ${result.elapsed} ms, old caches gone after ${result.purged} ms, verdict UP TO DATE, caches before: [${result.before.caches.join(', ')}] after: [${result.after.caches.join(', ')}]`;
  });
}

if (live) {
  const { context, build } = live;
  let page = live.page;
  const freshTab = async () => {
    await page.close();
    page = await context.newPage();
    return page;
  };

  console.log('\n== 2. an already-open stale page ==');
  await check('stale page reloads once by itself, shows the "updated" toast, and does not loop', async () => {
    await freshTab();
    const navigations = [];
    page.on('framenavigated', (frame) => {
      if (frame === page.mainFrame()) navigations.push(frame.url());
    });
    await page.goto(`${base}/e2e/page.html?stale=s1`);
    await page.waitForFunction(() => !document.getElementById('marker').textContent.includes('aaaaaaaaaaaa'), null, { timeout: 30000 });
    await page.waitForFunction(() => Array.from(document.querySelectorAll('[role="status"]')).some((el) => el.textContent.includes('تم تحديث التطبيق')), null, { timeout: 8000 });
    await sleep(8000);
    page.removeAllListeners('framenavigated');
    expect(navigations.length === 2, `expected goto + one reload, got ${navigations.length}: ${navigations.join(' | ')}`);
    expect((await page.textContent('#marker')).includes(build), 'page should now carry the server build');
    return `navigations: ${navigations.length}`;
  });

  await check('typing defers the reload; it happens after the customer stops', async () => {
    await freshTab();
    let release;
    const held = new Promise((resolve) => {
      release = resolve;
    });
    await page.route('**/api/app-version.php*', async (route) => {
      await held;
      await route.continue();
    });
    const navigations = [];
    const onNav = (frame) => {
      if (frame === page.mainFrame()) navigations.push(frame.url());
    };
    page.on('framenavigated', onNav);
    await page.goto(`${base}/e2e/page.html?stale=s2`);
    await page.click('#note');
    await page.fill('#note', 'نص لم يُحفظ بعد');
    release();
    await sleep(9000);
    expect(navigations.length === 1, `must not reload while typing, navigations: ${navigations.length}`);
    expect((await page.inputValue('#note')) === 'نص لم يُحفظ بعد', 'typed text must survive');
    await page.fill('#note', '');
    await page.evaluate(() => document.activeElement && document.activeElement.blur());
    await page.waitForFunction(() => !document.getElementById('marker').textContent.includes('aaaaaaaaaaaa'), null, { timeout: 30000 });
    page.off('framenavigated', onNav);
    await page.unroute('**/api/app-version.php*');
    return `reload after idle, navigations: ${navigations.length}`;
  });

  console.log('\n== 3. offline ==');
  await check('offline shows the offline page; back online returns to the app by itself', async () => {
    await freshTab();
    await page.goto(`${base}/e2e/page.html`);
    await page.waitForFunction(() => !!navigator.serviceWorker.controller, null, { timeout: 10000 });
    await context.setOffline(true);
    await page.goto(`${base}/e2e/page.html`);
    const offlineTitle = await page.title();
    expect(offlineTitle === 'لا يوجد اتصال', `expected the offline page, got title "${offlineTitle}"`);
    await context.setOffline(false);
    await page.waitForFunction(() => document.title === 'e2e page', null, { timeout: 15000 });
    return 'offline page -> app';
  });

  console.log('\n== 4. slow network ==');
  await check('a version check slower than the timeout does not block the page, the next one succeeds', async () => {
    await freshTab();
    let delay = true;
    await page.route('**/api/app-version.php*', async (route) => {
      if (delay) await sleep(6500);
      await route.continue().catch(() => {});
    });
    await page.goto(`${base}/e2e/page.html?stale=s3`);
    await page.click('#note');
    const typedAt = Date.now();
    await page.type('#note', 'abc', { delay: 20 });
    expect(Date.now() - typedAt < 3000, 'page must stay responsive while the check is slow');
    const slowStatus = await page.evaluate(async () => {
      const status = await window.PortalPwaUpdate.getStatus();
      return { serverBuild: status.serverBuild, serverError: status.serverError, verdict: status.verdict };
    });
    expect(slowStatus.serverBuild === null && !!slowStatus.serverError, `slow check should surface an error, got ${JSON.stringify(slowStatus)}`);
    delay = false;
    await page.fill('#note', '');
    await page.evaluate(() => document.activeElement && document.activeElement.blur());
    await page.evaluate(() => window.PortalPwaUpdate.check());
    await page.waitForFunction(() => !document.getElementById('marker').textContent.includes('aaaaaaaaaaaa'), null, { timeout: 30000 });
    await page.unroute('**/api/app-version.php*');
    return `slow check -> "${slowStatus.serverError}", recovered`;
  });

  console.log('\n== 6. status panel ==');
  await check('five quick taps open the panel; it reports UP TO DATE with matching builds', async () => {
    await freshTab();
    await page.setViewportSize({ width: 412, height: 915 });
    await page.goto(`${base}/e2e/page.html`);
    await until(page, async () => (await window.PortalPwaUpdate.getStatus()).verdict === 'UP TO DATE', { timeout: 20000, label: 'verdict UP TO DATE' });
    const trigger = page.locator('[data-pwa-status-trigger]').first();
    for (let i = 0; i < 4; i += 1) await trigger.click();
    expect((await page.locator('#pwa-status-panel').count()) === 0, 'four taps must not open the panel');
    await trigger.click();
    await page.waitForSelector('#pwa-status-panel .ps-badge', { timeout: 15000 });
    await page.waitForFunction(() => !document.querySelector('#pwa-status-panel .ps-sub').textContent.includes('جارٍ الفحص'), null, { timeout: 15000 });
    const text = await page.locator('#pwa-status-panel').innerText();
    expect(text.includes(UP_TO_DATE_LABEL), `verdict text missing: ${text.slice(0, 200)}`);
    expect(text.includes(build), 'panel must show the server build');
    await page.screenshot({ path: path.join(ARTIFACTS, 'status-panel.png') });
    expect(/browser/.test(text), 'headless tab should be reported as a plain browser');
    expect(text.includes('روابط تخرج من نطاق التطبيق'), 'the cross-host links row must be present');
    await page.keyboard.press('Escape');
    expect((await page.locator('#pwa-status-panel').count()) === 0, 'Escape closes the panel');
    await page.goto(`${base}/e2e/page.html?pwa-status=1`);
    await page.waitForSelector('#pwa-status-panel', { timeout: 10000 });
    return 'taps + ?pwa-status=1';
  });

  await context.close();
}

console.log('\n== 5. login form without login-page.js ==');
const loginCase = async (name, prepare, expectScriptMounted) => {
  await check(name, async () => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.setViewportSize({ width: 412, height: 915 });
    await prepare(page);
    await page.goto(`${base}/e2e/login`, { waitUntil: 'load' });
    await page.waitForSelector('input[name="customer_phone"]', { timeout: 9000 });
    await sleep(1600);
    const phones = await page.locator('input[name="customer_phone"]').count();
    const passwords = await page.locator('input[name="customer_password"]').count();
    expect(phones === 1 && passwords === 1, `exactly one phone and one password field expected, got ${phones}/${passwords}`);
    expect((await page.locator('[data-customer-login-placeholder]').count()) === 0, 'the "preparing the form" placeholder must be gone');
    const readonlyGuard = await page.locator('input[name="customer_phone"][readonly]').count();
    expect(expectScriptMounted ? readonlyGuard === 1 : readonlyGuard === 0, `script-mounted fields carry the readonly autofill guard (${readonlyGuard})`);
    await page.click('input[name="customer_phone"]');
    await page.fill('input[name="customer_phone"]', '0912345678');
    await page.fill('input[name="customer_password"]', 'secret123');
    if (!expectScriptMounted) await page.screenshot({ path: path.join(ARTIFACTS, 'login-form-fallback.png') });
    await Promise.all([page.waitForURL('**/e2e/after-login*', { timeout: 10000 }), page.click('button[type="submit"]')]);
    const landed = new URL(page.url());
    expect(landed.origin === base, `login must stay on the same origin, landed on ${landed.origin}`);
    expect(landed.searchParams.get('redirect') === '/store.php', 'return path must be preserved');
    expect(landed.searchParams.get('phone') === '0912345678' && landed.searchParams.get('password_len') === '9', 'credentials must reach the server');
    await context.close();
    return `landed ${landed.pathname}${landed.search}`;
  });
};
await loginCase('login-page.js blocked: the form appears from the no-script markup and submits', (page) => page.route('**/assets/login-page.js*', (route) => route.abort()), false);
await loginCase('login-page.js healthy: one form, mounted by the script', async () => {}, true);
await loginCase('login-page.js slow (2.5 s): still exactly one form', (page) => page.route('**/assets/login-page.js*', async (route) => {
  await sleep(2500);
  await route.continue().catch(() => {});
}), true);

console.log('\n== 7. login behind a legacy host-only session cookie (real Chrome cookie jar) ==');
const cookieRun = async (label, portalRoot) => {
  const server = await startPhp({
    E2E_STATE_DIR: stateDir,
    PORTAL_APP_URL: 'http://www.jawishco.test',
    PORTAL_COOKIE_DOMAIN: '.jawishco.test',
    PORTAL_STORAGE_PATH: path.join(stateDir, `storage-${label}`),
    E2E_PORTAL_ROOT: portalRoot,
  });
  try {
    const context = await browser.newContext();
    const host = `www.jawishco.test:${server.port}`;
    await context.addCookies([{ name: 'portal_session', value: 'LEGACYHOSTONLYSESSION0001', url: `http://${host}/`, httpOnly: true, sameSite: 'Lax' }]);
    const page = await context.newPage();
    await page.goto(`http://${host}/e2e/probe?a=whoami`);
    await page.goto(`http://${host}/e2e/probe?a=login`);
    const who = (await page.locator('#who').innerText()).trim();
    const jar = (await context.cookies()).filter((cookie) => cookie.name === 'portal_session').map((cookie) => `${cookie.domain}${cookie.value === 'LEGACYHOSTONLYSESSION0001' ? '=LEGACY' : '=NEW'}`);
    await context.close();
    return { who, jar, label };
  } finally {
    server.child.kill();
  }
};
await check('with the fix: the redirect after login lands signed in', async () => {
  const result = await cookieRun('fixed', PORTAL_DIR);
  expect(result.who.startsWith('user=customer-1'), `expected a signed-in customer after the redirect, got "${result.who}"`);
  return `${result.who.split(' ')[0]} | cookies left in the jar: ${result.jar.join(', ') || 'none'}`;
});
if (process.env.E2E_BASELINE_ROOT) {
  await check('baseline (before the fix): same flow shows the bug', async () => {
    const result = await cookieRun('baseline', process.env.E2E_BASELINE_ROOT);
    expect(result.who.startsWith('user=guest'), `baseline did not reproduce the stuck login, got "${result.who}"`);
    return `${result.who.split(' ')[0]} (bug reproduced) | cookies in the jar: ${result.jar.join(', ')}`;
  });
}

await browser.close();
main.child.kill();
fs.rmSync(stateDir, { recursive: true, force: true });

console.log(`\n${passed} passed, ${failures.length} failed. Screenshots: ${ARTIFACTS}`);
if (failures.length > 0) {
  console.log(`FAILED: ${failures.join(' | ')}`);
  process.exit(1);
}
