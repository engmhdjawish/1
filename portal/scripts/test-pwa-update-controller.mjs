#!/usr/bin/env node
/**
 * Page-side PWA update controller tests (no browser needed).
 *
 * public/assets/pwa-update.js runs against a small model of the browser: virtual clock, a service worker
 * container with a registration/worker lifecycle, a controllable /api/app-version.php, sessionStorage,
 * a fake DOM and a fake message channel. Real Chromium coverage lives in test-pwa-update-e2e.mjs.
 *
 * Run: node portal/scripts/test-pwa-update-controller.mjs
 */
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const scriptsDir = path.dirname(fileURLToPath(import.meta.url));
const PORTAL_DIR = path.join(scriptsDir, '..');
const require = createRequire(import.meta.url);
const api = require(path.join(PORTAL_DIR, 'public', 'assets', 'pwa-update.js'));
const C = api.constants;

const ORIGIN = 'https://www.jawishco.sy';
const EPOCH = 1_800_000_000_000;
const A = 'aaaaaaaaaaaa';
const B = 'bbbbbbbbbbbb';
const D = 'dddddddddddd';

const flush = () => new Promise((resolve) => setImmediate(resolve));

class FakeClock {
  constructor() {
    this.now = EPOCH;
    this.nextId = 1;
    this.timers = new Map();
    this.setTimeout = (fn, ms = 0) => {
      const id = this.nextId++;
      this.timers.set(id, { at: this.now + Math.max(0, ms), fn });
      return id;
    };
    this.clearTimeout = (id) => {
      this.timers.delete(id);
    };
  }

  async advance(ms) {
    const target = this.now + ms;
    for (;;) {
      await flush();
      const due = [...this.timers.entries()]
        .filter(([, timer]) => timer.at <= target)
        .sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
      if (!due) {
        break;
      }
      this.timers.delete(due[0]);
      this.now = Math.max(this.now, due[1].at);
      due[1].fn();
    }
    this.now = target;
    await flush();
  }
}

class FakeStorage {
  constructor({ broken = false } = {}) {
    this.map = new Map();
    this.broken = broken;
  }

  getItem(key) {
    if (this.broken) {
      throw new Error('SecurityError');
    }
    return this.map.has(key) ? this.map.get(key) : null;
  }

  setItem(key, value) {
    if (this.broken) {
      throw new Error('QuotaExceededError');
    }
    this.map.set(key, String(value));
  }

  removeItem(key) {
    if (this.broken) {
      throw new Error('SecurityError');
    }
    this.map.delete(key);
  }
}

class FakePort {
  constructor() {
    this.onmessage = null;
    this.peer = null;
    this.closed = false;
  }

  postMessage(data) {
    const peer = this.peer;
    queueMicrotask(() => {
      if (!peer.closed && typeof peer.onmessage === 'function') {
        peer.onmessage({ data });
      }
    });
  }

  close() {
    this.closed = true;
  }
}

class FakeMessageChannel {
  constructor() {
    this.port1 = new FakePort();
    this.port2 = new FakePort();
    this.port1.peer = this.port2;
    this.port2.peer = this.port1;
  }
}

class FakeWorker {
  constructor(world, scriptURL, { answers = true, notifies = true } = {}) {
    this.world = world;
    this.scriptURL = new URL(scriptURL, ORIGIN).href;
    this.state = 'installing';
    this.answers = answers;
    this.notifies = notifies;
    this.messages = [];
    this.registration = null;
  }

  get build() {
    return new URL(this.scriptURL).searchParams.get('b') || 'legacy';
  }

  postMessage(message, transfer = []) {
    this.messages.push(message);
    if (message && message.type === 'GET_BUILD' && this.answers && transfer[0]) {
      transfer[0].postMessage({ type: 'PORTAL_SW_BUILD', build: this.build });
    }
    if (message && message.type === 'SKIP_WAITING' && this.world.container.honourSkipWaiting) {
      this.world.container.activate(this.registration, this);
    }
  }
}

class FakeRegistration {
  constructor(container) {
    this.container = container;
    this.scope = ORIGIN + '/';
    this.active = null;
    this.waiting = null;
    this.installing = null;
    this.updateCalls = 0;
    this.unregistered = false;
  }

  async update() {
    this.updateCalls += 1;
  }

  async unregister() {
    this.unregistered = true;
    this.container.registration = null;
    return true;
  }
}

class FakeServiceWorkerContainer {
  constructor(world, { lifecycle = 'auto', installMs = 50 } = {}) {
    this.world = world;
    this.lifecycle = lifecycle;
    this.installMs = installMs;
    this.honourSkipWaiting = lifecycle === 'stuck-waiting-honours-skip';
    this.controller = null;
    this.registration = null;
    this.registerCalls = [];
    this.registerError = null;
    this.newWorkerTraits = {};
    this.listeners = { message: [], controllerchange: [] };
  }

  addEventListener(type, fn) {
    this.listeners[type].push(fn);
  }

  emit(type, event = {}) {
    for (const fn of this.listeners[type]) {
      fn(event);
    }
  }

  async getRegistration() {
    return this.registration ?? undefined;
  }

  async getRegistrations() {
    return this.registration ? [this.registration] : [];
  }

  seed(scriptURL, { controlled = true, answers = true, notifies = true } = {}) {
    const registration = new FakeRegistration(this);
    const worker = new FakeWorker(this.world, scriptURL, { answers, notifies });
    worker.state = 'activated';
    worker.registration = registration;
    registration.active = worker;
    this.registration = registration;
    if (controlled) {
      this.controller = worker;
    }
    return worker;
  }

  register(url, options) {
    this.registerCalls.push({ url, options: structuredClone(options) });
    if (this.registerError) {
      return Promise.reject(this.registerError);
    }
    const absolute = new URL(url, ORIGIN).href;
    let registration = this.registration;
    if (!registration) {
      registration = new FakeRegistration(this);
      this.registration = registration;
    }
    const newest = registration.installing || registration.waiting || registration.active;
    if (newest && newest.scriptURL === absolute) {
      return Promise.resolve(registration);
    }
    const worker = new FakeWorker(this.world, absolute, this.newWorkerTraits);
    worker.registration = registration;
    registration.installing = worker;
    if (this.lifecycle !== 'stuck-installing') {
      this.world.clock.setTimeout(() => {
        if (registration.installing !== worker) {
          return;
        }
        worker.state = 'installed';
        registration.installing = null;
        if (this.lifecycle === 'auto') {
          this.activate(registration, worker);
        } else {
          registration.waiting = worker;
        }
      }, this.installMs);
    }

    return Promise.resolve(registration);
  }

  activate(registration, worker) {
    if (registration.active) {
      registration.active.state = 'redundant';
    }
    registration.waiting = null;
    registration.installing = null;
    registration.active = worker;
    worker.state = 'activated';
    this.controller = worker;
    this.emit('controllerchange');
    if (worker.notifies) {
      this.emit('message', { data: { type: 'PORTAL_SW_ACTIVATED', build: worker.build } });
    }
  }
}

class FakeCaches {
  constructor(names) {
    this.names = new Set(names);
  }

  async keys() {
    return [...this.names];
  }

  async delete(name) {
    return this.names.delete(name);
  }
}

class FakeLocation {
  constructor(href) {
    const url = new URL(href);
    this.href = url.href;
    this.origin = url.origin;
    this.host = url.host;
    this.pathname = url.pathname;
    this.search = url.search;
    this.hash = url.hash;
    this.replaced = [];
    this.reloads = 0;
  }

  replace(target) {
    this.replaced.push(target);
  }

  reload() {
    this.reloads += 1;
  }
}

class FakeElement {
  constructor(tagName = 'div') {
    this.tagName = tagName.toUpperCase();
    this.attributes = {};
    this.style = { cssText: '' };
    this.children = [];
    this.parentNode = null;
    this.textContent = '';
  }

  setAttribute(name, value) {
    this.attributes[name] = value;
  }

  appendChild(child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  }

  removeChild(child) {
    this.children = this.children.filter((entry) => entry !== child);
    child.parentNode = null;
  }
}

function field(overrides = {}) {
  return {
    tagName: 'INPUT',
    type: 'text',
    value: '',
    defaultValue: '',
    readOnly: false,
    disabled: false,
    files: null,
    isContentEditable: false,
    textContent: '',
    ...overrides,
  };
}

class FakeDocument {
  constructor({ pageBuild, visible }) {
    this.pageBuild = pageBuild;
    this.visibilityState = visible ? 'visible' : 'hidden';
    this.activeElement = null;
    this.body = new FakeElement('body');
    this.documentElement = new FakeElement('html');
    this.fields = [];
    this.modalOpen = false;
    this.listeners = {};
  }

  querySelector(selector) {
    if (selector === 'meta[name="portal-build"]') {
      return this.pageBuild === null ? null : { getAttribute: () => this.pageBuild };
    }
    if (selector.includes('dialog[open]')) {
      return this.modalOpen ? {} : null;
    }
    return null;
  }

  querySelectorAll(selector) {
    return selector === 'input, textarea' ? this.fields : [];
  }

  createElement(tag) {
    return new FakeElement(tag);
  }

  addEventListener(type, fn) {
    (this.listeners[type] ??= []).push(fn);
  }

  dispatch(type, event = {}) {
    for (const fn of this.listeners[type] ?? []) {
      fn(event);
    }
  }
}

function createWorld(options = {}) {
  const o = {
    pageBuild: A,
    serverBuild: A,
    url: ORIGIN + '/store.php?x=1#frag',
    activeUrl: null,
    controlled: true,
    activeAnswers: true,
    activeNotifies: true,
    random: 0.5,
    secure: true,
    hasSw: true,
    visible: true,
    online: true,
    lifecycle: 'auto',
    newWorkerTraits: {},
    session: new FakeStorage(),
    local: new FakeStorage(),
    cacheNames: [`jawish-b-${A}-static`, 'jawish-fonts-v1', 'foreign-cache'],
    ...options,
  };
  const world = {};
  const clock = new FakeClock();
  world.clock = clock;
  world.loc = new FakeLocation(o.url);
  world.doc = new FakeDocument({ pageBuild: o.pageBuild, visible: o.visible });
  world.server = { build: o.serverBuild, mode: 'ok', delayMs: 0, hits: [] };
  world.session = o.session;
  world.local = o.local;
  world.caches = new FakeCaches(o.cacheNames);
  world.random = { value: o.random };
  world.nav = { onLine: o.online };
  world.winListeners = {};

  if (o.hasSw) {
    world.container = new FakeServiceWorkerContainer(world, { lifecycle: o.lifecycle });
    world.container.newWorkerTraits = o.newWorkerTraits;
    world.nav.serviceWorker = world.container;
    if (o.activeUrl) {
      world.container.seed(o.activeUrl, {
        controlled: o.controlled,
        answers: o.activeAnswers,
        notifies: o.activeNotifies,
      });
    }
  }

  const fakeFetch = (url, init = {}) => {
    world.server.hits.push({ url, init });
    const respond = () => {
      switch (world.server.mode) {
        case 'network':
          return Promise.reject(new TypeError('Failed to fetch'));
        case 'http500':
          return Promise.resolve({ ok: false, status: 500, json: async () => ({}) });
        case 'badjson':
          return Promise.resolve({ ok: true, status: 200, json: async () => { throw new SyntaxError('bad json'); } });
        case 'badbuild':
          return Promise.resolve({ ok: true, status: 200, json: async () => ({ ok: true, build: '../../etc/passwd' }) });
        case 'nobuild':
          return Promise.resolve({ ok: true, status: 200, json: async () => ({ ok: true }) });
        case 'hang':
          return new Promise(() => {});
        default:
          return Promise.resolve({ ok: true, status: 200, json: async () => ({ ok: true, build: world.server.build }) });
      }
    };
    const result = world.server.delayMs > 0
      ? new Promise((resolve, reject) => {
        clock.setTimeout(() => respond().then(resolve, reject), world.server.delayMs);
      })
      : respond();

    return new Promise((resolve, reject) => {
      if (init.signal) {
        const onAbort = () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
        if (init.signal.aborted) {
          onAbort();
          return;
        }
        init.signal.addEventListener('abort', onAbort, { once: true });
      }
      result.then(resolve, reject);
    });
  };

  world.env = {
    document: world.doc,
    location: world.loc,
    navigator: world.nav,
    fetch: fakeFetch,
    AbortController,
    MessageChannel: FakeMessageChannel,
    caches: world.caches,
    isSecureContext: o.secure,
    sessionStorage: world.session,
    localStorage: world.local,
    now: () => clock.now,
    setTimeout: clock.setTimeout,
    clearTimeout: clock.clearTimeout,
    random: () => world.random.value,
    addEventListener: (type, fn) => {
      (world.winListeners[type] ??= []).push(fn);
    },
  };
  world.controller = api.createController(world.env);
  world.advance = (ms) => clock.advance(ms);
  world.fireWindow = (type, event = {}) => {
    for (const fn of world.winListeners[type] ?? []) {
      fn(event);
    }
  };
  world.go = async (ms = 0) => {
    await world.controller.start();
    await clock.advance(ms);
    return world;
  };
  world.registerUrls = () => world.container.registerCalls.map((call) => call.url);
  world.serverHits = () => world.server.hits.length;

  return world;
}

/* ------------------------------------------------------------------------------------------------ */

describe('pure helpers', () => {
  test('parseBuildFromUrl reads ?b= and maps everything else to "legacy"', () => {
    assert.equal(api.parseBuildFromUrl('/sw.js?b=abc123def456'), 'abc123def456');
    assert.equal(api.parseBuildFromUrl('https://www.jawishco.sy/sw.js?b=v1.2_x-y'), 'v1.2_x-y');
    assert.equal(api.parseBuildFromUrl('/sw.js?v=17'), 'legacy');
    assert.equal(api.parseBuildFromUrl('/sw.js'), 'legacy');
    assert.equal(api.parseBuildFromUrl('/sw.js?b='), 'legacy');
    assert.equal(api.parseBuildFromUrl('/sw.js?b=../../x'), 'legacy');
    assert.equal(api.parseBuildFromUrl('/sw.js?b=' + 'x'.repeat(33)), 'legacy');
    assert.equal(api.parseBuildFromUrl(null), 'legacy');
  });

  test('isRealBuild rejects "legacy", empties, hostile strings and non-strings', () => {
    assert.equal(api.isRealBuild(A), true);
    assert.equal(api.isRealBuild('legacy'), false);
    assert.equal(api.isRealBuild(''), false);
    assert.equal(api.isRealBuild('a b'), false);
    assert.equal(api.isRealBuild('a/b'), false);
    assert.equal(api.isRealBuild(undefined), false);
    assert.equal(api.isRealBuild(12), false);
  });

  test('buildSwUrl always yields the one registration URL shape', () => {
    assert.equal(api.buildSwUrl(A), '/sw.js?b=' + A);
    assert.equal(api.buildSwUrl('a b'), '/sw.js?b=a%20b');
  });

  test('backoffDelay doubles from 30 s, is jittered +-20 % and capped at 15 min', () => {
    assert.equal(api.backoffDelay(1, 0.5), 30_000);
    assert.equal(api.backoffDelay(2, 0.5), 60_000);
    assert.equal(api.backoffDelay(3, 0.5), 120_000);
    assert.equal(api.backoffDelay(0, 0.5), 30_000);
    assert.equal(api.backoffDelay(1, 0), 24_000);
    assert.equal(api.backoffDelay(1, 1), 36_000);
    for (let failures = 1; failures < 40; failures++) {
      assert.ok(api.backoffDelay(failures, 1) <= C.BACKOFF_MAX_MS);
      assert.ok(api.backoffDelay(failures + 1, 0.5) >= api.backoffDelay(failures, 0.5));
    }
    assert.equal(api.backoffDelay(30, 0.5), C.BACKOFF_MAX_MS);
  });

  test('isQuietPath covers login, checkout/cart, dashboard and confirmation pages only', () => {
    for (const quiet of [
      '/login.php', '/staff-login.php', '/customer-login.php', '/register.php', '/logout.php',
      '/cart.php', '/store-cart.php', '/dashboard/', '/dashboard/orders.php', '/checkout', '/checkout/step-2',
      '/payment', '/order-confirmation.php', '/store-order-confirmation.php',
    ]) {
      assert.equal(api.isQuietPath(quiet), true, quiet);
    }
    for (const calm of ['/', '/index.php', '/store.php', '/product.php', '/s/ab12cd', '/about.php', '/my-orders.php', '']) {
      assert.equal(api.isQuietPath(calm), false, calm);
    }
  });

  test('readPageBuild accepts valid ids and rejects missing or hostile meta values', () => {
    const doc = (content) => ({
      querySelector: () => (content === undefined ? null : { getAttribute: () => content }),
    });
    assert.equal(api.readPageBuild(doc(A)), A);
    assert.equal(api.readPageBuild(doc('  ' + A + ' ')), A);
    assert.equal(api.readPageBuild(doc(undefined)), null);
    assert.equal(api.readPageBuild(doc('')), null);
    assert.equal(api.readPageBuild(doc('<script>')), null);
    assert.equal(api.readPageBuild({ querySelector: () => { throw new Error('boom'); } }), null);
  });

  test('decideVerdict', () => {
    assert.equal(api.decideVerdict({ pageBuild: A, serverBuild: A, activeBuild: A }), 'UP TO DATE');
    assert.equal(api.decideVerdict({ pageBuild: A, serverBuild: null, activeBuild: A }), 'UNKNOWN');
    assert.equal(api.decideVerdict({ pageBuild: A, serverBuild: B, activeBuild: A, waitingBuild: B }), 'UPDATE PENDING');
    assert.equal(api.decideVerdict({ pageBuild: A, serverBuild: B, activeBuild: A, installingBuild: B }), 'UPDATE PENDING');
    assert.equal(api.decideVerdict({ pageBuild: A, serverBuild: B, activeBuild: A, attemptBuild: B }), 'UPDATE PENDING');
    assert.equal(api.decideVerdict({ pageBuild: A, serverBuild: B, activeBuild: A, pendingReloadTarget: B }), 'UPDATE PENDING');
    assert.equal(api.decideVerdict({ pageBuild: A, serverBuild: B, activeBuild: A }), 'STALE');
    assert.equal(api.decideVerdict({ pageBuild: B, serverBuild: B, activeBuild: A }), 'STALE');
    assert.equal(api.decideVerdict({ pageBuild: B, serverBuild: B, activeBuild: null }), 'STALE');
    assert.equal(api.decideVerdict({ pageBuild: A, serverBuild: B, activeBuild: B }), 'STALE');
  });
});

describe('evaluateReloadSafety', () => {
  const base = { pathname: '/store.php', visibilityState: 'visible', now: EPOCH, lastInteractionAt: 0 };
  const verdict = (extra) => api.evaluateReloadSafety({ ...base, ...extra });

  test('visible, idle page with no input is safe', () => {
    assert.deepEqual(verdict({}), { safe: true, reason: 'idle', retry: false });
  });

  test('hidden page is safe', () => {
    assert.deepEqual(verdict({ visibilityState: 'hidden', lastInteractionAt: EPOCH }), { safe: true, reason: 'hidden', retry: false });
  });

  test('quiet paths are never safe and never retried', () => {
    for (const pathname of ['/login.php', '/dashboard/orders.php', '/cart.php', '/checkout']) {
      assert.deepEqual(verdict({ pathname, visibilityState: 'hidden' }), { safe: false, reason: 'quiet-path', retry: false });
    }
  });

  test('a pending submit blocks until its grace window ends, even when hidden', () => {
    assert.equal(verdict({ pendingSubmitUntil: EPOCH + 1000, visibilityState: 'hidden' }).reason, 'pending-submit');
    assert.equal(verdict({ pendingSubmitUntil: EPOCH, visibilityState: 'hidden' }).safe, true);
  });

  test('selected file, open dialog and unsaved text block even when hidden', () => {
    assert.equal(verdict({ hasFileSelected: true, visibilityState: 'hidden' }).reason, 'file-selected');
    assert.equal(verdict({ modalOpen: true, visibilityState: 'hidden' }).reason, 'dialog-open');
    assert.equal(verdict({ hasDirtyText: true, visibilityState: 'hidden' }).reason, 'unsaved-input');
    for (const reason of ['file-selected', 'dialog-open', 'unsaved-input']) {
      const probe = verdict({ hasFileSelected: reason === 'file-selected', modalOpen: reason === 'dialog-open', hasDirtyText: reason === 'unsaved-input' });
      assert.equal(probe.retry, true);
    }
  });

  test('focused field with content and recent interaction block visible pages', () => {
    assert.equal(verdict({ focusedHasContent: true }).reason, 'typing');
    assert.equal(verdict({ lastInteractionAt: EPOCH - 4999 }).reason, 'recent-interaction');
    assert.equal(verdict({ lastInteractionAt: EPOCH - C.IDLE_MS }).safe, true);
  });
});

describe('inspectDocument', () => {
  const docWith = (fields, extra = {}) => ({
    body: {},
    documentElement: {},
    activeElement: null,
    querySelectorAll: () => fields,
    querySelector: () => (extra.modal ? {} : null),
    ...extra.doc,
  });

  test('empty page', () => {
    assert.deepEqual(api.inspectDocument(docWith([])), {
      focusedHasContent: false, hasDirtyText: false, hasFileSelected: false, modalOpen: false,
    });
  });

  test('typed text in a text-like field is dirty; defaults, readonly, disabled, hidden and checkboxes are not', () => {
    const cases = [
      [field({ value: 'abc' }), true],
      [field({ value: 'abc', defaultValue: 'abc' }), false],
      [field({ value: '  ' }), false],
      [field({ value: 'abc', readOnly: true }), false],
      [field({ value: 'abc', disabled: true }), false],
      [field({ type: 'hidden', value: 'token' }), false],
      [field({ type: 'checkbox', value: 'on' }), false],
      [field({ type: 'password', value: 'secret' }), true],
      [field({ type: 'search', value: 'milk' }), true],
      [field({ tagName: 'TEXTAREA', type: 'textarea', value: 'note' }), true],
    ];
    for (const [element, dirty] of cases) {
      assert.equal(api.inspectDocument(docWith([element])).hasDirtyText, dirty, JSON.stringify(element));
    }
  });

  test('file input only counts when a file is actually selected', () => {
    assert.equal(api.inspectDocument(docWith([field({ type: 'file', files: { length: 0 } })])).hasFileSelected, false);
    assert.equal(api.inspectDocument(docWith([field({ type: 'file', files: { length: 1 } })])).hasFileSelected, true);
    assert.equal(api.inspectDocument(docWith([field({ type: 'file', files: { length: 1 }, value: 'C:\\fakepath\\a.png' })])).hasDirtyText, false);
  });

  test('focused element: text with content, empty text, select, button, body', () => {
    const focus = (activeElement) => api.inspectDocument(docWith([], { doc: { activeElement } })).focusedHasContent;
    assert.equal(focus(field({ value: 'x' })), true);
    assert.equal(focus(field({ value: '' })), false);
    assert.equal(focus(field({ tagName: 'SELECT', type: 'select-one' })), true);
    assert.equal(focus(field({ type: 'submit', value: 'Send' })), false);
    assert.equal(focus(field({ type: 'checkbox', value: 'on' })), false);
    assert.equal(focus({ tagName: 'DIV', isContentEditable: true, textContent: 'hello' }), true);
    assert.equal(focus({ tagName: 'DIV', isContentEditable: true, textContent: '' }), false);
    const doc = docWith([]);
    doc.activeElement = doc.body;
    assert.equal(api.inspectDocument(doc).focusedHasContent, false);
  });

  test('open dialogs and the cart drawer count as modal', () => {
    assert.equal(api.inspectDocument(docWith([], { modal: true })).modalOpen, true);
  });

  test('an unreadable DOM never throws', () => {
    const hostile = { get activeElement() { throw new Error('nope'); }, querySelectorAll() { throw new Error('nope'); } };
    assert.doesNotThrow(() => api.inspectDocument(hostile));
  });
});

describe('start() gating', () => {
  test('no portal-build meta: nothing happens', async () => {
    const w = createWorld({ pageBuild: null });
    await w.go(120_000);
    assert.equal(w.container.registerCalls.length, 0);
    assert.equal(w.serverHits(), 0);
    assert.deepEqual(w.doc.listeners, {});
  });

  test('insecure context: nothing happens', async () => {
    const w = createWorld({ secure: false });
    await w.go(120_000);
    assert.equal(w.container.registerCalls.length, 0);
    assert.equal(w.serverHits(), 0);
  });

  test('no service worker support: nothing happens and nothing throws', async () => {
    const w = createWorld({ hasSw: false });
    await w.go(120_000);
    assert.equal(w.serverHits(), 0);
    assert.equal(w.loc.replaced.length, 0);
    assert.deepEqual(await w.controller.check('x', { force: true }), null);
  });

  test('start() is idempotent', async () => {
    const w = createWorld();
    await w.controller.start();
    await w.controller.start();
    assert.equal(w.container.registerCalls.length, 1);
    assert.equal(w.doc.listeners.visibilitychange.length, 1);
  });
});

describe('first install', () => {
  test('registers /sw.js?b=<page build> once with scope "/" and updateViaCache "none", then nudges update()', async () => {
    const w = createWorld({ activeUrl: null });
    await w.go(0);
    assert.deepEqual(w.registerUrls(), ['/sw.js?b=' + A]);
    assert.deepEqual(w.container.registerCalls[0].options, { scope: '/', updateViaCache: 'none' });
    assert.equal(w.container.registration.updateCalls, 1);
  });

  test('the worker claiming an uncontrolled page never reloads it', async () => {
    const w = createWorld({ activeUrl: null });
    await w.go(200_000);
    assert.ok(w.container.registration.active, 'worker became active');
    assert.equal(w.container.controller.build, A);
    assert.equal(w.loc.replaced.length, 0);
    assert.equal(w.loc.reloads, 0);
    assert.equal(w.container.registerCalls.length, 1, 'later checks agree with the page, no re-registration');
    assert.ok(w.controller.state.events.some((entry) => /first (install activated|claim)/.test(entry.message)));
  });

  test('even when the claim message arrives after controllerchange, no reload', async () => {
    const w = createWorld({ activeUrl: null, pageBuild: A, serverBuild: A });
    await w.go(5_000);
    w.container.emit('message', { data: { type: 'PORTAL_SW_ACTIVATED', build: A } });
    await w.advance(1_000);
    assert.equal(w.loc.replaced.length, 0);
  });
});

describe('steady state', () => {
  test('page, server and worker agree: checks are quiet (no register, no reload)', async () => {
    const w = createWorld({ activeUrl: '/sw.js?b=' + A });
    await w.go(30 * 60_000);
    assert.equal(w.container.registerCalls.length, 0);
    assert.equal(w.loc.replaced.length, 0);
    assert.ok(w.serverHits() >= 3, 'periodic checks ran');
    const hit = w.server.hits[0];
    assert.equal(hit.url, '/api/app-version.php');
    assert.equal(hit.init.cache, 'no-store');
    assert.equal(hit.init.credentials, 'omit');
    assert.ok(hit.init.signal, 'an AbortController signal guards the 4 s timeout');
  });

  test('first check is jittered between 0 and 45 s after load', async () => {
    const early = createWorld({ activeUrl: '/sw.js?b=' + A, random: 0 });
    await early.controller.start();
    await early.advance(1);
    assert.equal(early.serverHits(), 1);

    const late = createWorld({ activeUrl: '/sw.js?b=' + A, random: 0.99 });
    await late.controller.start();
    await late.advance(40_000);
    assert.equal(late.serverHits(), 0);
    await late.advance(6_000);
    assert.equal(late.serverHits(), 1);
  });

  test('checks repeat about every 10 minutes while visible', async () => {
    const w = createWorld({ activeUrl: '/sw.js?b=' + A, random: 0 });
    await w.go(1);
    assert.equal(w.serverHits(), 1);
    await w.advance(C.CHECK_INTERVAL_MS - 1_000);
    assert.equal(w.serverHits(), 1);
    await w.advance(2_000);
    assert.equal(w.serverHits(), 2);
    await w.advance(C.CHECK_INTERVAL_MS);
    assert.equal(w.serverHits(), 3);
  });

  test('hidden pages stop polling; becoming visible checks again (respecting the 20 s gap)', async () => {
    const w = createWorld({ activeUrl: '/sw.js?b=' + A, random: 0 });
    await w.go(1);
    assert.equal(w.serverHits(), 1);

    w.doc.visibilityState = 'hidden';
    w.doc.dispatch('visibilitychange');
    await w.advance(3 * C.CHECK_INTERVAL_MS);
    assert.equal(w.serverHits(), 1, 'no timer-driven checks while hidden');

    w.doc.visibilityState = 'visible';
    w.doc.dispatch('visibilitychange');
    await w.advance(10);
    assert.equal(w.serverHits(), 2);

    w.doc.visibilityState = 'hidden';
    w.doc.dispatch('visibilitychange');
    w.doc.visibilityState = 'visible';
    w.doc.dispatch('visibilitychange');
    await w.advance(10);
    assert.equal(w.serverHits(), 2, 'flapping inside the 20 s gap does not hammer the endpoint');

    w.doc.visibilityState = 'hidden';
    w.doc.dispatch('visibilitychange');
    await w.advance(C.MIN_CHECK_GAP_MS + 1_000);
    w.doc.visibilityState = 'visible';
    w.doc.dispatch('visibilitychange');
    await w.advance(10);
    assert.equal(w.serverHits(), 3);
  });

  test('online event forces a check even inside the gap; pageshow only when restored from bfcache', async () => {
    const w = createWorld({ activeUrl: '/sw.js?b=' + A, random: 0 });
    await w.go(1);
    assert.equal(w.serverHits(), 1);
    w.fireWindow('pageshow', { persisted: false });
    await w.advance(10);
    assert.equal(w.serverHits(), 1);
    w.fireWindow('pageshow', { persisted: true });
    await w.advance(10);
    assert.equal(w.serverHits(), 2);
    w.fireWindow('online');
    await w.advance(10);
    assert.equal(w.serverHits(), 3);
  });

  test('concurrent checks share one request', async () => {
    const w = createWorld({ activeUrl: '/sw.js?b=' + A });
    w.server.delayMs = 500;
    const first = w.controller.check('a', { force: true });
    const second = w.controller.check('b', { force: true });
    assert.equal(first, second);
    await w.advance(600);
    assert.equal(w.serverHits(), 1);
  });
});

describe('update flow', () => {
  test('stale page: new build is registered, takes over, and the page reloads once (hash dropped)', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, random: 0 });
    await w.go(2_000);
    assert.deepEqual(w.registerUrls(), ['/sw.js?b=' + B]);
    assert.deepEqual(w.container.registerCalls[0].options, { scope: '/', updateViaCache: 'none' });
    assert.ok(w.container.registration.updateCalls >= 1, 'registration.update() nudge after register');
    assert.deepEqual(w.loc.replaced, [ORIGIN + '/store.php?x=1']);
    assert.equal(w.loc.reloads, 0, 'location.replace is used so a POST page is never re-submitted');
    assert.deepEqual(JSON.parse(w.session.getItem(C.RELOADED_KEY)), [B]);
    assert.equal(w.session.getItem(C.JUST_UPDATED_KEY), B);
  });

  test('both the controllerchange and the PORTAL_SW_ACTIVATED signal arrive, still exactly one reload', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, random: 0 });
    await w.go(5_000);
    w.container.emit('message', { data: { type: 'PORTAL_SW_ACTIVATED', build: B } });
    w.container.emit('controllerchange');
    await w.advance(5_000);
    assert.equal(w.loc.replaced.length, 1);
  });

  test('loop guard: a reloaded page that is still old does not reload again for the same build', async () => {
    const first = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, random: 0 });
    await first.go(2_000);
    assert.equal(first.loc.replaced.length, 1);

    const second = createWorld({
      pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + B, random: 0, session: first.session, local: first.local,
    });
    await second.go(2_000);
    second.container.emit('message', { data: { type: 'PORTAL_SW_ACTIVATED', build: B } });
    await second.advance(60_000);
    assert.equal(second.loc.replaced.length, 0);
    assert.ok(second.controller.state.events.some((entry) => /already done in this tab|loop guard/.test(entry.message)));
  });

  test('no sessionStorage at all: the page never auto-reloads (cannot prove it will not loop)', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, random: 0, session: new FakeStorage({ broken: true }) });
    await w.go(5_000);
    assert.equal(w.loc.replaced.length, 0);
    assert.equal(w.container.registerCalls.length, 1, 'registration still moves to the new build');
  });

  test('a newer build shipped while the page stays open updates again (new target, new guard entry)', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, random: 0 });
    await w.go(2_000);
    assert.equal(w.loc.replaced.length, 1);
    w.server.build = D;
    w.fireWindow('online');
    await w.advance(2_000);
    assert.deepEqual(w.registerUrls(), ['/sw.js?b=' + B, '/sw.js?b=' + D]);
    assert.equal(w.loc.replaced.length, 2);
    assert.deepEqual(JSON.parse(w.session.getItem(C.RELOADED_KEY)), [B, D]);
  });

  test('legacy ?v=17 registration with fresh HTML: registration moves to ?b=<build> without any reload', async () => {
    const w = createWorld({ pageBuild: B, serverBuild: B, activeUrl: '/sw.js?v=17', activeAnswers: false, activeNotifies: false });
    await w.go(5_000);
    assert.deepEqual(w.registerUrls(), ['/sw.js?b=' + B]);
    assert.equal(w.container.controller.build, B);
    assert.equal(w.loc.replaced.length, 0, 'the page already runs the new build');
  });

  test('legacy ?v=17 registration with stale HTML (old page from an old worker cache): update + reload', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?v=17', activeAnswers: false, activeNotifies: false });
    await w.go(5_000);
    assert.deepEqual(w.registerUrls(), ['/sw.js?b=' + B]);
    assert.equal(w.loc.replaced.length, 1);
  });

  test('a rewritten legacy worker (same ?v=17 URL, new bytes, announces "legacy") makes the page re-check instead of trusting it', async () => {
    const w = createWorld({ pageBuild: B, serverBuild: B, activeUrl: '/sw.js?b=' + B, random: 0.99 });
    await w.controller.start();
    w.container.activate(
      w.container.registration,
      Object.assign(new FakeWorker(w, '/sw.js?v=17', {}), { registration: w.container.registration }),
    );
    await w.advance(2_000);
    assert.ok(w.serverHits() >= 1, 'a legacy activation forces a version check');
    assert.deepEqual(w.registerUrls(), ['/sw.js?b=' + B]);
  });

  test('every registration the controller ever makes uses exactly /sw.js?b=<build> with the same options', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?v=17', activeAnswers: false, random: 0 });
    await w.go(2_000);
    w.server.build = D;
    w.fireWindow('online');
    await w.advance(2_000);
    for (const call of w.container.registerCalls) {
      assert.match(call.url, /^\/sw\.js\?b=[0-9a-z]{12}$/);
      assert.deepEqual(call.options, { scope: '/', updateViaCache: 'none' });
    }
  });

  test('a waiting worker for the server build is nudged with SKIP_WAITING instead of re-registering', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, random: 0, lifecycle: 'stuck-waiting-honours-skip' });
    await w.go(2_000);
    assert.deepEqual(w.registerUrls(), ['/sw.js?b=' + B]);
    const waiting = w.container.registration.waiting;
    assert.ok(waiting, 'worker B is waiting');
    assert.equal(waiting.messages.some((m) => m.type === 'SKIP_WAITING'), false, 'not yet nudged');
    w.fireWindow('online');
    await w.advance(2_000);
    assert.equal(w.container.registerCalls.length, 1, 'no second register');
    assert.ok(w.container.registration.active.build === B, 'nudge activated it');
    assert.equal(w.loc.replaced.length, 1);
  });
});

describe('failure handling', () => {
  for (const mode of ['network', 'http500', 'badjson', 'badbuild', 'nobuild']) {
    test(`version endpoint failure "${mode}" is silent: no register, no reload, failure recorded`, async () => {
      const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, random: 0 });
      w.server.mode = mode;
      await w.go(5_000);
      assert.equal(w.container.registerCalls.length, 0);
      assert.equal(w.loc.replaced.length, 0);
      assert.equal(w.controller.state.failures, 1);
      const last = JSON.parse(w.local.getItem(C.LAST_CHECK_KEY));
      assert.equal(last.ok, false);
    });
  }

  test('a hanging endpoint is aborted after 4 s and the controller is not left wedged', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, random: 0 });
    w.server.mode = 'hang';
    await w.go(3_900);
    assert.ok(w.controller.state.inFlight, 'still waiting at 3.9 s');
    await w.advance(200);
    assert.equal(w.controller.state.inFlight, null);
    assert.equal(w.controller.state.failures, 1);
    assert.equal(JSON.parse(w.local.getItem(C.LAST_CHECK_KEY)).detail, 'timeout');
    w.server.mode = 'ok';
    w.fireWindow('online');
    await w.advance(2_000);
    assert.deepEqual(w.registerUrls(), ['/sw.js?b=' + B]);
  });

  test('retries back off 30 s, 60 s, 120 s ... and success resets to the 10 min cadence', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: A, activeUrl: '/sw.js?b=' + A, random: 0 });
    w.server.mode = 'network';
    await w.controller.start();
    w.random.value = 0.5;
    const t0 = w.clock.now;
    const until = (ms) => w.advance(t0 + ms - w.clock.now);

    await until(1);
    assert.equal(w.serverHits(), 1, 'first check at load');
    await until(29_999);
    assert.equal(w.serverHits(), 1);
    await until(30_001);
    assert.equal(w.serverHits(), 2, 'retry after ~30 s');
    await until(89_999);
    assert.equal(w.serverHits(), 2);
    await until(90_001);
    assert.equal(w.serverHits(), 3, 'retry after another ~60 s');
    assert.equal(w.controller.state.failures, 3);

    w.server.mode = 'ok';
    await until(209_999);
    assert.equal(w.serverHits(), 3);
    await until(210_001);
    assert.equal(w.serverHits(), 4, 'retry after ~120 s succeeds');
    assert.equal(w.controller.state.failures, 0);
    await until(210_000 + C.CHECK_INTERVAL_MS - 1);
    assert.equal(w.serverHits(), 4);
    await until(210_000 + C.CHECK_INTERVAL_MS + 1);
    assert.equal(w.serverHits(), 5, 'back on the 10 min cadence');
  });

  test('register() rejecting (network, security) is swallowed and recorded', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, random: 0 });
    w.container.registerError = new TypeError('Failed to register a ServiceWorker');
    await w.go(2_000);
    assert.equal(w.loc.replaced.length, 0);
    assert.ok(w.controller.state.events.some((entry) => /register failed/.test(entry.message)));
  });

  test('storage that throws on every call never breaks the checks', async () => {
    const w = createWorld({ activeUrl: '/sw.js?b=' + A, random: 0, session: new FakeStorage({ broken: true }), local: new FakeStorage({ broken: true }) });
    await w.go(C.CHECK_INTERVAL_MS + 5_000);
    assert.ok(w.serverHits() >= 2);
  });
});

describe('offline and slow networks', () => {
  test('offline: checks are skipped silently (no fetch), recorded as offline', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, random: 0, online: false });
    await w.go(60_000);
    assert.equal(w.serverHits(), 0);
    assert.equal(w.container.registerCalls.length, 0);
    assert.equal(JSON.parse(w.local.getItem(C.LAST_CHECK_KEY)).detail, 'offline');
  });

  test('coming back online registers the new build and safely reloads', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, random: 0, online: false });
    await w.go(60_000);
    w.nav.onLine = true;
    w.fireWindow('online');
    await w.advance(2_000);
    assert.deepEqual(w.registerUrls(), ['/sw.js?b=' + B]);
    assert.equal(w.loc.replaced.length, 1);
  });

  test('a slow (not failing) endpoint within 4 s still gets honoured', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, random: 0 });
    w.server.delayMs = 3_000;
    await w.go(8_000);
    assert.deepEqual(w.registerUrls(), ['/sw.js?b=' + B]);
    assert.equal(w.loc.replaced.length, 1);
  });
});

describe('safe reload gating (end to end through the controller)', () => {
  async function stalePage(extra = {}) {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, random: 0, ...extra });
    return w;
  }

  test('typed text defers the reload; clearing it and idling lets it through', async () => {
    const w = await stalePage();
    const input = field({ value: 'half typed address' });
    w.doc.fields = [input];
    w.doc.activeElement = input;
    await w.go(20_000);
    assert.equal(w.loc.replaced.length, 0);
    assert.equal(w.controller.state.pendingReload.target, B);
    assert.equal(w.container.controller.build, B, 'the new worker is already in control');

    input.value = '';
    w.doc.activeElement = null;
    w.doc.dispatch('input');
    await w.advance(2_000);
    assert.equal(w.loc.replaced.length, 0, 'recent interaction still protects the page');
    await w.advance(C.IDLE_MS + C.RELOAD_RETRY_MS);
    assert.equal(w.loc.replaced.length, 1);
  });

  test('hidden page with nothing typed reloads immediately on activation', async () => {
    const w = await stalePage({ visible: false });
    await w.controller.start();
    await w.controller.check('manual', { force: true });
    await w.advance(2_000);
    assert.equal(w.loc.replaced.length, 1);
  });

  test('page that goes hidden while a reload is pending reloads at that moment', async () => {
    const w = await stalePage();
    const input = field({ value: 'abc' });
    w.doc.activeElement = input;
    w.doc.fields = [];
    await w.go(5_000);
    assert.equal(w.loc.replaced.length, 0);
    w.doc.visibilityState = 'hidden';
    w.doc.dispatch('visibilitychange');
    assert.equal(w.loc.replaced.length, 1);
  });

  test('unsaved text, a selected file or an open dialog hold the reload even once hidden', async () => {
    for (const [label, mutate, release] of [
      ['unsaved text', (w) => { w.doc.fields = [field({ value: 'typed' })]; }, (w) => { w.doc.fields = []; }],
      ['file', (w) => { w.doc.fields = [field({ type: 'file', files: { length: 1 } })]; }, (w) => { w.doc.fields = []; }],
      ['dialog', (w) => { w.doc.modalOpen = true; }, (w) => { w.doc.modalOpen = false; }],
    ]) {
      const w = await stalePage();
      mutate(w);
      await w.go(5_000);
      w.doc.visibilityState = 'hidden';
      w.doc.dispatch('visibilitychange');
      assert.equal(w.loc.replaced.length, 0, label);
      release(w);
      w.doc.dispatch('visibilitychange');
      assert.equal(w.loc.replaced.length, 1, `${label} released`);
    }
  });

  test('a form submit in flight blocks the reload for its grace window', async () => {
    const w = await stalePage();
    await w.controller.start();
    w.doc.dispatch('submit');
    await w.controller.check('manual', { force: true });
    await w.advance(10_000);
    assert.equal(w.loc.replaced.length, 0);
    await w.advance(C.SUBMIT_GRACE_MS + C.RELOAD_RETRY_MS + C.IDLE_MS);
    assert.equal(w.loc.replaced.length, 1);
  });

  test('scrolling or tapping within the last 5 s delays the reload until idle', async () => {
    const w = await stalePage();
    await w.controller.start();
    await w.advance(1);
    w.doc.dispatch('scroll');
    await w.advance(1_500);
    assert.equal(w.loc.replaced.length, 0);
    await w.advance(C.IDLE_MS + C.RELOAD_RETRY_MS);
    assert.equal(w.loc.replaced.length, 1);
  });

  for (const quiet of ['/login.php', '/staff-login.php', '/dashboard/orders.php', '/cart.php', '/store-order-confirmation.php']) {
    test(`${quiet} is never reloaded in place, and does not busy-poll while it waits`, async () => {
      const w = await stalePage({ url: ORIGIN + quiet });
      await w.go(10_000);
      assert.equal(w.loc.replaced.length, 0);
      assert.equal(w.controller.state.pendingReload.waitingFor, 'quiet-path');
      assert.equal(w.controller.state.reloadTimer, null, 'no retry timer for a path that will never become safe');
      w.doc.visibilityState = 'hidden';
      w.doc.dispatch('visibilitychange');
      assert.equal(w.loc.replaced.length, 0);
      assert.equal(w.container.controller.build, B, 'but the worker took over, so the next navigation is fresh');
    });
  }

  test('the cart drawer open on the store page holds the reload', async () => {
    const w = await stalePage();
    w.doc.modalOpen = true;
    await w.go(10_000);
    assert.equal(w.loc.replaced.length, 0);
  });
});

describe('escape hatch', () => {
  test('active worker whose URL claims the server build but never answers GET_BUILD: unregister, purge jawish-* caches, reload once', async () => {
    const w = createWorld({ pageBuild: B, serverBuild: B, activeUrl: '/sw.js?b=' + B, activeAnswers: false, random: 0 });
    await w.go(C.GET_BUILD_TIMEOUT_MS + 2_000);
    assert.equal(w.container.registration, null, 'registration removed');
    assert.deepEqual([...w.caches.names], ['foreign-cache'], 'only jawish-* caches are deleted');
    assert.deepEqual(w.loc.replaced, [ORIGIN + '/store.php?x=1']);
    assert.deepEqual(JSON.parse(w.session.getItem(C.ESCAPED_KEY)), [B]);
    assert.equal(w.controller.state.lastEscape.reason, 'worker-silent-on-get-build');
  });

  test('once per build per tab session: the next time it only registers normally', async () => {
    const first = createWorld({ pageBuild: B, serverBuild: B, activeUrl: '/sw.js?b=' + B, activeAnswers: false, random: 0 });
    await first.go(C.GET_BUILD_TIMEOUT_MS + 2_000);
    assert.equal(first.loc.replaced.length, 1);

    const second = createWorld({
      pageBuild: B, serverBuild: B, activeUrl: '/sw.js?b=' + B, activeAnswers: false, random: 0,
      session: first.session, local: first.local, cacheNames: ['jawish-b-x-static', 'foreign-cache'],
    });
    await second.go(C.GET_BUILD_TIMEOUT_MS + 2_000);
    assert.ok(second.container.registration, 'not unregistered again');
    assert.equal(second.loc.replaced.length, 0);
    assert.deepEqual([...second.caches.names].sort(), ['foreign-cache', 'jawish-b-x-static']);
  });

  test('a healthy worker (answers GET_BUILD) never triggers it', async () => {
    const w = createWorld({ pageBuild: B, serverBuild: B, activeUrl: '/sw.js?b=' + B, random: 0 });
    await w.go(C.CHECK_INTERVAL_MS * 3);
    assert.ok(w.container.registration);
    assert.equal(w.loc.replaced.length, 0);
    assert.equal(w.session.getItem(C.ESCAPED_KEY), null);
  });

  test('a mute worker never triggers it while the server is unreachable or the browser is offline', async () => {
    const down = createWorld({ pageBuild: B, serverBuild: B, activeUrl: '/sw.js?b=' + B, activeAnswers: false, random: 0 });
    down.server.mode = 'network';
    await down.go(120_000);
    assert.ok(down.container.registration);
    assert.equal(down.loc.replaced.length, 0);

    const offline = createWorld({ pageBuild: B, serverBuild: B, activeUrl: '/sw.js?b=' + B, activeAnswers: false, random: 0, online: false });
    await offline.go(120_000);
    assert.ok(offline.container.registration);
    assert.equal(offline.loc.replaced.length, 0);
  });

  test('update that never activates within ~30 s: escape (installing worker stuck)', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, lifecycle: 'stuck-installing', random: 0 });
    await w.go(C.ESCAPE_AFTER_MS - 1_000);
    assert.ok(w.container.registration, 'not yet');
    assert.equal(w.loc.replaced.length, 0);
    await w.advance(2_000);
    assert.equal(w.container.registration, null);
    assert.deepEqual([...w.caches.names], ['foreign-cache']);
    assert.equal(w.loc.replaced.length, 1);
    assert.equal(w.controller.state.lastEscape.reason, 'update-did-not-activate');
  });

  test('waiting worker gets one SKIP_WAITING nudge first, then the escape if it still does not activate', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, lifecycle: 'stuck-waiting', random: 0 });
    await w.go(C.ESCAPE_AFTER_MS + 1_000);
    const waiting = w.container.registration.waiting;
    assert.ok(waiting);
    assert.ok(waiting.messages.filter((m) => m.type === 'SKIP_WAITING').length >= 1);
    assert.ok(w.container.registration, 'nudge happened, escape not yet');
    await w.advance(6_000);
    assert.equal(w.container.registration, null);
    assert.equal(w.loc.replaced.length, 1);
  });

  test('offline at the 30 s mark defers the escape; it does not fire while offline', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, lifecycle: 'stuck-installing', random: 0 });
    await w.go(1_000);
    w.nav.onLine = false;
    await w.advance(C.ESCAPE_AFTER_MS * 3);
    assert.ok(w.container.registration);
    assert.equal(w.loc.replaced.length, 0);
    w.nav.onLine = true;
    await w.advance(C.ESCAPE_AFTER_MS + 1_000);
    assert.equal(w.container.registration, null);
  });

  test('a build that moved on while the attempt was pending re-targets instead of escaping', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, lifecycle: 'stuck-installing', random: 0 });
    await w.go(1_000);
    w.server.build = D;
    await w.advance(C.ESCAPE_AFTER_MS + 1_000);
    assert.ok(w.container.registration, 'no escape');
    assert.equal(w.loc.replaced.length, 0);
    assert.ok(w.registerUrls().includes('/sw.js?b=' + D));
  });

  test('an unreachable server at the 30 s mark postpones the decision', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, lifecycle: 'stuck-installing', random: 0 });
    await w.go(1_000);
    w.server.mode = 'network';
    await w.advance(C.ESCAPE_AFTER_MS * 2);
    assert.ok(w.container.registration);
    assert.equal(w.loc.replaced.length, 0);
  });

  test('escape is skipped when sessionStorage is unusable (could not guard against loops)', async () => {
    const w = createWorld({ pageBuild: B, serverBuild: B, activeUrl: '/sw.js?b=' + B, activeAnswers: false, random: 0, session: new FakeStorage({ broken: true }) });
    await w.go(C.GET_BUILD_TIMEOUT_MS + 2_000);
    assert.ok(w.container.registration);
    assert.equal(w.loc.replaced.length, 0);
  });

  test('escape reload uses its own loop-guard key, so it is not blocked by an earlier normal reload for the same build', async () => {
    const w = createWorld({ pageBuild: B, serverBuild: B, activeUrl: '/sw.js?b=' + B, activeAnswers: false, random: 0 });
    w.session.setItem(C.RELOADED_KEY, JSON.stringify([B]));
    await w.go(C.GET_BUILD_TIMEOUT_MS + 2_000);
    assert.equal(w.loc.replaced.length, 1);
    assert.deepEqual(JSON.parse(w.session.getItem(C.RELOADED_KEY)), [B, 'escape:' + B]);
  });
});

describe('completion toast', () => {
  test('after an update reload the page shows a short Arabic toast once, then removes it', async () => {
    const session = new FakeStorage();
    session.setItem(C.JUST_UPDATED_KEY, B);
    const w = createWorld({ pageBuild: B, serverBuild: B, activeUrl: '/sw.js?b=' + B, session });
    await w.go(0);
    assert.equal(session.getItem(C.JUST_UPDATED_KEY), null);
    assert.equal(w.doc.body.children.length, 1);
    const toast = w.doc.body.children[0];
    assert.equal(toast.textContent, 'تم تحديث التطبيق إلى أحدث إصدار');
    assert.equal(toast.attributes.role, 'status');
    assert.equal(toast.attributes.dir, 'rtl');
    await w.advance(10_000);
    assert.equal(w.doc.body.children.length, 0);
  });

  test('no toast when the page is not on the build the reload was for', async () => {
    const session = new FakeStorage();
    session.setItem(C.JUST_UPDATED_KEY, B);
    const w = createWorld({ pageBuild: A, serverBuild: A, activeUrl: '/sw.js?b=' + A, session });
    await w.go(0);
    assert.equal(w.doc.body.children.length, 0);
    assert.equal(session.getItem(C.JUST_UPDATED_KEY), null);
  });
});

describe('getStatus() for the diagnostics panel', () => {
  test('UP TO DATE when page, server and active worker agree', async () => {
    const w = createWorld({ activeUrl: '/sw.js?b=' + A, random: 0.99 });
    await w.controller.start();
    const status = await Promise.resolve(w.controller.getStatus()).then(async (promise) => {
      await w.advance(4_000);
      return promise;
    });
    assert.equal(status.verdict, 'UP TO DATE');
    assert.equal(status.pageBuild, A);
    assert.equal(status.serverBuild, A);
    assert.equal(status.origin, ORIGIN);
    assert.equal(status.host, 'www.jawishco.sy');
    assert.equal(status.online, true);
    assert.equal(status.controller, true);
    assert.equal(status.registration.active.urlBuild, A);
    assert.equal(status.registration.active.answeredBuild, A);
    assert.equal(status.registration.waiting, null);
    assert.equal(status.registration.installing, null);
    assert.deepEqual(status.caches, [`jawish-b-${A}-static`, 'jawish-fonts-v1', 'foreign-cache']);
  });

  test('STALE when page and worker are older than the server and no update is on its way yet', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, random: 0.99 });
    await w.controller.start();
    const pending = w.controller.getStatus();
    await w.advance(4_000);
    const status = await pending;
    assert.equal(status.verdict, 'STALE');
    assert.equal(status.serverBuild, B);
    assert.equal(status.registration.active.answeredBuild, A);
  });

  test('UPDATE PENDING right after load when only the page is fresh and the worker is older (update already started)', async () => {
    const w = createWorld({ pageBuild: B, serverBuild: B, activeUrl: '/sw.js?b=' + A, lifecycle: 'stuck-installing', random: 0.99 });
    await w.go(100);
    const pending = w.controller.getStatus();
    await w.advance(4_000);
    const status = await pending;
    assert.equal(status.verdict, 'UPDATE PENDING');
  });

  test('UPDATE PENDING while a worker for the server build is installing', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, lifecycle: 'stuck-installing', random: 0 });
    await w.go(1_000);
    const pending = w.controller.getStatus();
    await w.advance(4_000);
    const status = await pending;
    assert.equal(status.verdict, 'UPDATE PENDING');
    assert.equal(status.registration.installing.urlBuild, B);
  });

  test('UNKNOWN (with the error) when the server cannot be reached', async () => {
    const w = createWorld({ activeUrl: '/sw.js?b=' + A, random: 0.99 });
    w.server.mode = 'network';
    const pending = w.controller.getStatus();
    await w.advance(1_000);
    const status = await pending;
    assert.equal(status.verdict, 'UNKNOWN');
    assert.ok(status.serverError);
  });

  test('reports last check result, pending reload and the legacy worker as "legacy"', async () => {
    const w = createWorld({ pageBuild: B, serverBuild: B, activeUrl: '/sw.js?v=17', activeAnswers: false, activeNotifies: false, lifecycle: 'stuck-installing' });
    await w.go(100);
    const pending = w.controller.getStatus();
    await w.advance(4_000);
    const status = await pending;
    assert.equal(status.registration.active.urlBuild, 'legacy');
    assert.equal(status.registration.active.answeredBuild, null);
    assert.equal(status.registration.installing.urlBuild, B);
    assert.equal(status.lastCheck.ok, true);
    assert.equal(status.lastCheck.serverBuild, B);
    assert.equal(status.verdict, 'UPDATE PENDING');
  });

  test('is read-only: no register, unregister, reload or cache deletion', async () => {
    const w = createWorld({ pageBuild: A, serverBuild: B, activeUrl: '/sw.js?b=' + A, activeAnswers: false, random: 0.99 });
    const pending = w.controller.getStatus();
    await w.advance(4_000);
    await pending;
    assert.equal(w.container.registerCalls.length, 0);
    assert.ok(w.container.registration);
    assert.equal(w.loc.replaced.length, 0);
    assert.equal(w.caches.names.size, 3);
  });
});

describe('boot()', () => {
  function fakeWindow(overrides = {}) {
    const w = createWorld({ activeUrl: null, ...overrides.world });
    const listeners = {};
    const win = {
      document: w.doc,
      location: w.loc,
      navigator: w.nav,
      fetch: w.env.fetch,
      AbortController,
      MessageChannel: FakeMessageChannel,
      caches: w.caches,
      isSecureContext: true,
      sessionStorage: w.session,
      localStorage: w.local,
      setTimeout: w.clock.setTimeout,
      clearTimeout: w.clock.clearTimeout,
      addEventListener: (type, fn) => {
        (listeners[type] ??= []).push(fn);
      },
      ...overrides.win,
    };
    return { w, win, listeners };
  }

  test('publishes window.PortalPwaUpdate (buildUrl/register/check/getStatus/pageBuild) before the first registration', async () => {
    const { w, win } = fakeWindow();
    const seenAtRegister = [];
    const originalRegister = w.container.register.bind(w.container);
    w.container.register = (url, options) => {
      seenAtRegister.push(Boolean(win.PortalPwaUpdate));
      return originalRegister(url, options);
    };
    const handle = api.boot(win);
    assert.equal(win.PortalPwaUpdate, handle);
    assert.deepEqual(Object.keys(handle).sort(), ['buildUrl', 'check', 'getStatus', 'pageBuild', 'register']);
    assert.equal(handle.pageBuild, A);
    assert.equal(handle.buildUrl(B), '/sw.js?b=' + B);
    await w.advance(10);
    assert.deepEqual(seenAtRegister, [true]);
    assert.deepEqual(w.registerUrls(), ['/sw.js?b=' + A]);
  });

  test('booting twice never creates a second controller or second set of handlers', async () => {
    const { w, win, listeners } = fakeWindow();
    const first = api.boot(win);
    const second = api.boot(win);
    assert.equal(first, second);
    await w.advance(10);
    assert.equal(w.container.registerCalls.length, 1);
    assert.equal(w.container.listeners.controllerchange.length, 1);
    assert.equal(w.container.listeners.message.length, 1);
    assert.equal(listeners.online.length, 1);
  });

  test('without fetch it declines to start and leaves the inline fallback in charge', () => {
    const { win } = fakeWindow({ win: { fetch: undefined } });
    assert.equal(api.boot(win), null);
    assert.equal(win.PortalPwaUpdate, undefined);
  });
});

describe('one registration URL everywhere (static)', () => {
  const skipDirs = new Set(['node_modules', 'vendor', 'storage', '.git', 'scripts', 'dev-test', 'tests']);

  function walk(dir, out = []) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      if (entry.isDirectory()) {
        if (!skipDirs.has(entry.name)) {
          walk(path.join(dir, entry.name), out);
        }
      } else if (/\.(php|js|mjs|html)$/.test(entry.name) && !entry.name.endsWith('.min.js')) {
        out.push(path.join(dir, entry.name));
      }
    }
    return out;
  }

  const files = walk(PORTAL_DIR).map((file) => ({
    rel: path.relative(PORTAL_DIR, file).split(path.sep).join('/'),
    src: fs.readFileSync(file, 'utf8'),
  }));

  test('the only code that registers a service worker is pwa-update.js and the head fallback', () => {
    const registrars = files
      .filter(({ rel, src }) => rel !== 'public/sw.js' && /\.register\(/.test(src) && /sw\.js/.test(src))
      .map(({ rel }) => rel)
      .sort();
    assert.deepEqual(registrars, ['public/assets/pwa-update.js', 'views/partials/head-icons.php']);
  });

  test('no literal registration of /sw.js?v=N survives anywhere', () => {
    const offenders = files.filter(({ rel, src }) => rel !== 'public/sw.js' && /sw\.js\?v=/.test(src)).map(({ rel }) => rel);
    assert.deepEqual(offenders, []);
  });

  test('any literal register() argument is /sw.js?b=...', () => {
    for (const { rel, src } of files) {
      for (const match of src.matchAll(/\.register\(\s*(['"`])([^'"`]+)\1/g)) {
        assert.ok(match[2].startsWith('/sw.js?b='), `${rel}: ${match[0]}`);
      }
    }
  });

  test('pwa.js no longer owns registration, reload-on-controllerchange or the tap-to-update banner', () => {
    const pwa = fs.readFileSync(path.join(PORTAL_DIR, 'public/assets/pwa.js'), 'utf8');
    for (const forbidden of ['serviceWorker', 'controllerchange', 'location.reload', 'showUpdateBanner', 'applyUpdate', 'pwa-update-banner', 'SKIP_WAITING']) {
      assert.equal(pwa.includes(forbidden), false, forbidden);
    }
  });

  test('pwa-update.js is plain ES5 syntax so an old Chrome cannot silently choke on it', () => {
    const src = fs.readFileSync(path.join(PORTAL_DIR, 'public/assets/pwa-update.js'), 'utf8');
    const code = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
    for (const [name, pattern] of [
      ['optional chaining', /\?\./], ['nullish coalescing', /\?\?/], ['arrow function', /=>/],
      ['const/let', /\b(const|let)\b/], ['class', /\bclass\b/], ['template literal', /`/],
      ['async/await', /\b(async|await)\b/], ['spread', /\.\.\./],
    ]) {
      assert.equal(pattern.test(code), false, name);
    }
  });
});

describe('inline head fallback (views/partials/head-icons.php)', () => {
  const headSrc = fs.readFileSync(path.join(PORTAL_DIR, 'views/partials/head-icons.php'), 'utf8');

  function loadFallback({ updater = false, registration = null, secure = true, hasSw = true } = {}) {
    const blocks = [...headSrc.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((match) => match[1]);
    assert.equal(blocks.length, 1, 'exactly one inline script in the head partial');
    const code = blocks[0].replace(/<\?=\s*json_encode\([^?]*\?>/, JSON.stringify(B));
    assert.ok(!code.includes('<?'), 'no PHP left in the extracted script');
    const calls = [];
    const listeners = {};
    const win = {
      isSecureContext: secure,
      addEventListener: (type, fn) => {
        (listeners[type] ??= []).push(fn);
      },
    };
    if (updater) {
      win.PortalPwaUpdate = {};
    }
    const navigator = hasSw
      ? {
        serviceWorker: {
          getRegistration: async () => registration,
          register: async (url, options) => {
            calls.push({ url, options: structuredClone(options) });
          },
        },
      }
      : {};
    vm.runInNewContext(code, { window: win, navigator, encodeURIComponent });

    return {
      calls,
      listeners,
      async fireLoad() {
        for (const fn of listeners.load ?? []) {
          fn();
        }
        await flush();
      },
    };
  }

  test('registers /sw.js?b=<build> on load when the controller is absent and nothing is registered', async () => {
    const fb = loadFallback();
    assert.equal(fb.calls.length, 0, 'waits for load');
    await fb.fireLoad();
    assert.deepEqual(fb.calls, [{ url: '/sw.js?b=' + B, options: { scope: '/', updateViaCache: 'none' } }]);
  });

  test('replaces a legacy ?v=17 registration', async () => {
    const fb = loadFallback({ registration: { active: { scriptURL: ORIGIN + '/sw.js?v=17' } } });
    await fb.fireLoad();
    assert.equal(fb.calls.length, 1);
  });

  test('never touches an existing ?b= registration (no downgrade, no flip-flop)', async () => {
    const fb = loadFallback({ registration: { active: { scriptURL: ORIGIN + '/sw.js?b=' + A }, installing: null, waiting: null } });
    await fb.fireLoad();
    assert.equal(fb.calls.length, 0);
  });

  test('does nothing when pwa-update.js is present, in an insecure context, or without service workers', async () => {
    for (const options of [{ updater: true }, { secure: false }, { hasSw: false }]) {
      const fb = loadFallback(options);
      await fb.fireLoad();
      assert.equal(fb.calls.length, 0, JSON.stringify(options));
    }
  });

  test('head partial loads the versioned controller script early (defer) and keeps the manifest link', () => {
    assert.match(headSrc, /portal_defer_script\('\/assets\/pwa-update\.js'\)/);
    assert.match(headSrc, /<link rel="manifest" href="\/manifest\.php">/);
    assert.match(headSrc, /<meta name="portal-build" content="<\?= h\(\$pwaBuild\) \?>">/);
  });
});
