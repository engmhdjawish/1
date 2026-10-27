#!/usr/bin/env node
/**
 * On-device status panel: pure helpers (tap counter, verdict text, cross-host link scan, report rows).
 * The rendered panel is covered in real Chromium by test-pwa-update-e2e.mjs.
 *
 * Run: node portal/scripts/test-pwa-status-panel.mjs
 */
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const PORTAL_DIR = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(import.meta.url);
const status = require(path.join(PORTAL_DIR, 'public', 'assets', 'pwa-status.js'));
const update = require(path.join(PORTAL_DIR, 'public', 'assets', 'pwa-update.js'));

const A = 'aaaaaaaaaaaa';
const B = 'bbbbbbbbbbbb';
const NOW = 1_800_000_000_000;

const row = (rows, key) => rows.find((entry) => entry.key === key);

const baseReport = (overrides = {}) => ({
  generatedAt: '2026-10-27T12:00:00.000Z',
  url: 'https://www.jawishco.sy/?pwa-status=1',
  origin: 'https://www.jawishco.sy',
  host: 'www.jawishco.sy',
  displayMode: 'standalone',
  online: true,
  userAgent: 'Mozilla/5.0 (Linux; Android 14) Chrome/130',
  update: {
    pageBuild: B,
    serverBuild: B,
    verdict: 'UP TO DATE',
    controller: true,
    registration: {
      scope: 'https://www.jawishco.sy/',
      active: { scriptURL: `https://www.jawishco.sy/sw.js?b=${B}`, state: 'activated', urlBuild: B, answeredBuild: B },
      waiting: null,
      installing: null,
    },
    caches: [`jawish-b-${B}-static`],
    lastCheck: { at: NOW - 30_000, ok: true, serverBuild: B, detail: '' },
    failures: 0,
    pendingReload: null,
    lastActivation: { build: B, at: NOW - 90_000, via: 'message' },
    lastEscape: null,
    events: [{ at: NOW - 1000, message: 'check (api)' }],
  },
  manifest: {
    id: 'https://www.jawishco.sy/?source=pwa',
    start_url: 'https://www.jawishco.sy/?source=pwa',
    scope: 'https://www.jawishco.sy/',
    display: 'standalone',
    name: 'جاويش للتجارة',
    short_name: 'جاويش',
    iconRevision: '7',
  },
  registrations: [{ scope: 'https://www.jawishco.sy/', scriptURL: `https://www.jawishco.sy/sw.js?b=${B}` }],
  caches: [`jawish-b-${B}-static`, 'jawish-fonts-v1'],
  crossHostLinks: { count: 0, samples: [] },
  errors: [],
  ...overrides,
});

describe('tap counter', () => {
  test('five taps within the gap open the panel on the fifth', () => {
    let t = 0;
    const counter = status.createTapCounter({ now: () => t });
    const results = [];
    for (let i = 0; i < 5; i++) {
      t += 300;
      results.push(counter.tap());
    }
    assert.deepEqual(results, [false, false, false, false, true]);
  });

  test('a pause longer than the gap restarts the count', () => {
    let t = 0;
    const counter = status.createTapCounter({ now: () => t });
    for (let i = 0; i < 4; i++) {
      t += 200;
      counter.tap();
    }
    t += status.constants.TAP_GAP_MS + 1;
    assert.equal(counter.tap(), false);
    for (let i = 0; i < 3; i++) {
      t += 200;
      assert.equal(counter.tap(), false);
    }
    t += 200;
    assert.equal(counter.tap(), true);
  });

  test('the counter resets after opening so the sixth tap does not reopen', () => {
    let t = 0;
    const counter = status.createTapCounter({ now: () => t });
    for (let i = 0; i < 5; i++) {
      t += 100;
      counter.tap();
    }
    t += 100;
    assert.equal(counter.tap(), false);
  });
});

describe('verdict wording', () => {
  test('every verdict pwa-update.js can produce has Arabic text and a tone', () => {
    for (const verdict of ['UP TO DATE', 'UPDATE PENDING', 'STALE', 'UNKNOWN']) {
      const info = status.verdictInfo(verdict);
      assert.ok(info.label && /[\u0600-\u06FF]/.test(info.label), verdict);
      assert.ok(['ok', 'warn', 'bad', 'muted'].includes(info.tone), verdict);
      assert.ok(info.hint.length > 10, verdict);
    }
    assert.equal(status.verdictInfo('whatever').tone, 'muted');
  });

  test('verdicts decided by the controller map to the expected tones', () => {
    const up = update.decideVerdict({ pageBuild: B, serverBuild: B, activeBuild: B });
    const stale = update.decideVerdict({ pageBuild: A, serverBuild: B, activeBuild: A });
    const pending = update.decideVerdict({ pageBuild: A, serverBuild: B, activeBuild: A, installingBuild: B });
    assert.equal(status.verdictInfo(up).tone, 'ok');
    assert.equal(status.verdictInfo(stale).tone, 'bad');
    assert.equal(status.verdictInfo(pending).tone, 'warn');
  });
});

describe('cross-host links', () => {
  const location = { host: 'jawishco.sy', origin: 'https://jawishco.sy', href: 'https://jawishco.sy/store.php' };
  const anchor = (href) => ({ getAttribute: () => href });

  test('flags www/apex siblings and http downgrades, ignores everything else', () => {
    const found = status.findCrossHostLinks(
      [
        anchor('/store.php'),
        anchor('#top'),
        anchor('tel:+963900000000'),
        anchor('https://wa.me/963900000000'),
        anchor('https://www.jawishco.sy/s/abc'),
        anchor('http://jawishco.sy/index.php'),
        anchor('https://jawishco.sy/index.php'),
        anchor('mailto:a@b.c'),
        anchor('javascript:void(0)'),
        anchor(''),
      ],
      location,
    );
    assert.equal(found.count, 2);
    assert.deepEqual(found.samples, ['https://www.jawishco.sy/s/abc', 'http://jawishco.sy/index.php']);
  });

  test('keeps at most five samples but counts all of them', () => {
    const anchors = Array.from({ length: 9 }, (_, i) => anchor(`https://www.jawishco.sy/p${i}`));
    const found = status.findCrossHostLinks(anchors, location);
    assert.equal(found.count, 9);
    assert.equal(found.samples.length, 5);
  });
});

describe('report rows', () => {
  test('a healthy standalone app reads green', () => {
    const rows = status.summarizeReport(baseReport(), NOW);
    assert.equal(row(rows, 'verdict').tone, 'ok');
    assert.equal(row(rows, 'displayMode').tone, 'ok');
    assert.equal(row(rows, 'origin').tone, 'ok');
    assert.equal(row(rows, 'pageBuild').tone, 'ok');
    assert.equal(row(rows, 'activeBuild').tone, 'ok');
    assert.equal(row(rows, 'workerUrl').tone, 'ok');
    assert.equal(row(rows, 'caches').tone, 'ok');
    assert.equal(row(rows, 'crossHostLinks').tone, 'ok');
    assert.equal(row(rows, 'registrations').tone, 'ok');
    assert.match(row(rows, 'lastCheck').value, /قبل 30 ثانية/);
    assert.match(row(rows, 'manifest').value, /icon revision: 7/);
  });

  test('a browser toolbar launch and cross-host links are called out', () => {
    const rows = status.summarizeReport(
      baseReport({ displayMode: 'minimal-ui', crossHostLinks: { count: 3, samples: ['https://jawishco.sy/a'] } }),
      NOW,
    );
    assert.equal(row(rows, 'displayMode').tone, 'warn');
    assert.match(row(rows, 'displayMode').hint, /خارج نطاق التطبيق/);
    assert.equal(row(rows, 'crossHostLinks').tone, 'bad');
    assert.match(row(rows, 'crossHostLinks').value, /^3\n/);
  });

  test('an old worker, stale caches and a mismatching scope are flagged', () => {
    const report = baseReport();
    report.update.pageBuild = A;
    report.update.verdict = 'STALE';
    report.update.registration.active = {
      scriptURL: 'https://www.jawishco.sy/sw.js?v=17',
      state: 'activated',
      urlBuild: 'legacy',
      answeredBuild: 'legacy',
    };
    report.caches = ['jawish-static-v17', 'jawish-fonts-v1'];
    report.manifest.scope = 'https://jawishco.sy/';
    const rows = status.summarizeReport(report, NOW);
    assert.equal(row(rows, 'verdict').tone, 'bad');
    assert.equal(row(rows, 'pageBuild').tone, 'warn');
    assert.equal(row(rows, 'activeBuild').tone, 'warn');
    assert.equal(row(rows, 'workerUrl').tone, 'warn');
    assert.equal(row(rows, 'caches').tone, 'warn');
    assert.match(row(rows, 'caches').hint, /jawish-static-v17/);
    assert.equal(row(rows, 'origin').tone, 'bad');
  });

  test('offline and pending reload explain themselves', () => {
    const report = baseReport({ online: false });
    report.update.verdict = 'UPDATE PENDING';
    report.update.pendingReload = { target: B, waitingFor: 'typing' };
    report.update.registration.waiting = { scriptURL: `https://www.jawishco.sy/sw.js?b=${B}`, state: 'installed', urlBuild: B, answeredBuild: null };
    const rows = status.summarizeReport(report, NOW);
    assert.equal(row(rows, 'online').tone, 'warn');
    assert.match(row(rows, 'online').hint, /أول تحميل ناجح/);
    assert.match(row(rows, 'pendingReload').value, /جارٍ الكتابة/);
    assert.match(row(rows, 'workerQueue').value, /ينتظر/);
  });

  test('a page without the updater is reported instead of crashing', () => {
    const rows = status.summarizeReport(baseReport({ update: null, errors: ['pwa-update.js لم يُحمَّل في هذه الصفحة'] }), NOW);
    assert.equal(row(rows, 'verdict').tone, 'muted');
    assert.equal(row(rows, 'pageBuild').tone, 'bad');
    assert.ok(rows.some((entry) => entry.key.startsWith('error')));
  });

  test('the copied text carries every row plus recent events', () => {
    const text = status.reportToText(baseReport(), NOW);
    assert.match(text, /^تقرير حالة التطبيق/);
    assert.match(text, /نسخة الخادم: bbbbbbbbbbbb/);
    assert.match(text, /آخر الأحداث:/);
    assert.match(text, /check \(api\)/);
  });
});

describe('manifest summary', () => {
  const location = { href: 'https://www.jawishco.sy/', origin: 'https://www.jawishco.sy' };

  test('keeps the install identity and reads the icon revision', () => {
    const picked = status.pickManifest(
      {
        id: '/?source=pwa',
        start_url: 'https://www.jawishco.sy/?source=pwa',
        scope: 'https://www.jawishco.sy/',
        display: 'standalone',
        short_name: 'جاويش',
        icons: [{ src: '/assets/icons/icon-192.png?webapk=7' }],
      },
      location,
    );
    assert.equal(picked.id, 'https://www.jawishco.sy/?source=pwa');
    assert.equal(picked.iconRevision, '7');
    assert.equal(picked.scope, 'https://www.jawishco.sy/');
  });
});

describe('wiring', () => {
  const read = (relative) => fs.readFileSync(path.join(PORTAL_DIR, relative), 'utf8');

  test('the footer exposes the tap target and the layout loads the panel script', () => {
    assert.match(read('views/partials/site-footer.php'), /data-pwa-status-trigger/);
    assert.match(read('views/layout.php'), /portal_defer_script\('\/assets\/pwa-status\.js'\)/);
    assert.match(read('public/pwa-id.php'), /portal_defer_script\('\/assets\/pwa-status\.js'\)/);
  });

  test('the panel is built with textContent only (no HTML injection from report data)', () => {
    const source = read('public/assets/pwa-status.js');
    assert.equal(/\.innerHTML\s*=/.test(source), false);
    assert.equal(/insertAdjacentHTML/.test(source), false);
  });

  test('the panel never touches cookies or storage of the login', () => {
    const source = read('public/assets/pwa-status.js');
    assert.equal(/document\.cookie|localStorage\.clear|sessionStorage\.clear|cookieStore/.test(source), false);
  });
});
