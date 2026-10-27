#!/usr/bin/env node
/**
 * Service worker behaviour tests (no browser needed).
 *
 * sw.js and the REAL historical generations in scripts/fixtures/sw-legacy/ run inside a
 * node:vm sandbox with a small model of the browser's service worker lifecycle, so we can
 * replay "old worker active, new bytes shipped" for every generation customers may still have.
 *
 * Run: node portal/scripts/test-pwa-auto-update.mjs
 */
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const scriptsDir = path.dirname(fileURLToPath(import.meta.url));
const SW_PATH = path.join(scriptsDir, '..', 'public', 'sw.js');
const LEGACY_DIR = path.join(scriptsDir, 'fixtures', 'sw-legacy');
const NEW_SW = fs.readFileSync(SW_PATH, 'utf8');
const ORIGIN = 'https://www.jawishco.sy';

const flush = () => new Promise((resolve) => setImmediate(resolve));

function tagResponse(response, type) {
  Object.defineProperty(response, 'type', { value: type, configurable: true });
  const nativeClone = response.clone.bind(response);
  response.clone = () => tagResponse(nativeClone(), type);
  return response;
}

function basic(body = '', init = {}) {
  return tagResponse(new Response(body, { status: 200, ...init }), 'basic');
}

class FakeClock {
  constructor() {
    this.now = 0;
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

class FakeNetwork {
  constructor(clock) {
    this.clock = clock;
    this.routes = new Map();
    this.log = [];
    this.offline = false;
    this.fallback = () => basic('', { status: 404 });
  }

  set(urlOrPath, handler) {
    const key = new URL(urlOrPath, ORIGIN).href;
    this.routes.set(key, typeof handler === 'function' ? handler : () => basic(String(handler)));
  }

  hang(urlOrPath) {
    this.set(urlOrPath, () => new Promise(() => {}));
  }

  requestsFor(urlOrPath) {
    const key = new URL(urlOrPath, ORIGIN).href;
    return this.log.filter((entry) => entry.url === key);
  }

  fetch = async (input, init = {}) => {
    const url = typeof input === 'string' ? new URL(input, ORIGIN).href : input.url;
    this.log.push({
      url,
      cache: init.cache ?? input.cache,
      method: input.method ?? init.method ?? 'GET',
      mode: input.mode,
    });
    if (this.offline) {
      throw new TypeError('Failed to fetch');
    }
    const handler = this.routes.get(url) ?? this.fallback;
    const result = handler(input, init);
    if (init.signal) {
      return new Promise((resolve, reject) => {
        const onAbort = () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
        if (init.signal.aborted) {
          onAbort();
          return;
        }
        init.signal.addEventListener('abort', onAbort, { once: true });
        Promise.resolve(result).then(resolve, reject);
      });
    }
    return result;
  };
}

class FakeCache {
  constructor(name, network) {
    this.name = name;
    this.network = network;
    this.entries = new Map();
  }

  keyOf(request) {
    return typeof request === 'string' ? new URL(request, ORIGIN).href : request.url;
  }

  async match(request) {
    const hit = this.entries.get(this.keyOf(request));
    return hit ? hit.clone() : undefined;
  }

  async put(request, response) {
    this.entries.set(this.keyOf(request), response);
  }

  async add(request) {
    const response = await this.network.fetch(request);
    if (!response.ok) {
      throw new TypeError('Request failed');
    }
    await this.put(request, response);
  }

  async addAll(requests) {
    const responses = await Promise.all(requests.map((request) => this.network.fetch(request)));
    if (responses.some((response) => !response.ok)) {
      throw new TypeError('Request failed');
    }
    responses.forEach((response, index) => this.entries.set(this.keyOf(requests[index]), response));
  }

  async keys() {
    return [...this.entries.keys()].map((url) => new Request(url));
  }

  async delete(request) {
    return this.entries.delete(this.keyOf(request));
  }

  urls() {
    return [...this.entries.keys()].map((url) => new URL(url).pathname + new URL(url).search);
  }
}

class FakeCacheStorage {
  constructor(network) {
    this.network = network;
    this.stores = new Map();
    this.failKeys = false;
  }

  async open(name) {
    if (!this.stores.has(name)) {
      this.stores.set(name, new FakeCache(name, this.network));
    }
    return this.stores.get(name);
  }

  async keys() {
    if (this.failKeys) {
      throw new Error('keys() failed');
    }
    return [...this.stores.keys()];
  }

  async delete(name) {
    return this.stores.delete(name);
  }

  async match(request) {
    for (const store of this.stores.values()) {
      const hit = await store.match(request);
      if (hit) {
        return hit;
      }
    }
    return undefined;
  }

  names() {
    return [...this.stores.keys()].sort();
  }
}

class FakeWindowClient {
  constructor(id, { url = ORIGIN + '/', visibilityState = 'visible', focused = false } = {}) {
    this.id = id;
    this.url = url;
    this.type = 'window';
    this.visibilityState = visibilityState;
    this.focused = focused;
    this.messages = [];
    this.navigations = [];
    this.focusCount = 0;
    this.controller = null;
  }

  postMessage(message) {
    this.messages.push(structuredClone(message));
  }

  async navigate(url) {
    this.navigations.push(url);
    return this;
  }

  async focus() {
    this.focusCount += 1;
    return this;
  }
}

class FakeBrowser {
  constructor() {
    this.clock = new FakeClock();
    this.network = new FakeNetwork(this.clock);
    this.caches = new FakeCacheStorage(this.network);
    this.clients = [];
    this.openedWindows = [];
    this.notifications = [];
    this.order = [];
    this.preloadEnabled = false;
    this.claimCalls = 0;
    this.matchAllOptions = [];
    this.workers = [];
    this.active = null;
    this.waiting = null;
    this.installing = null;
    const browser = this;
    this.registration = {
      get active() {
        return browser.active ? browser.active.handle : null;
      },
      get waiting() {
        return browser.waiting ? browser.waiting.handle : null;
      },
      get installing() {
        return browser.installing ? browser.installing.handle : null;
      },
      navigationPreload: {
        async enable() {
          browser.order.push('preload');
          browser.preloadEnabled = true;
        },
      },
      async showNotification(title, options) {
        browser.notifications.push({ title, options: structuredClone(options) });
      },
    };
    this.clientsApi = {
      async matchAll(options = {}) {
        browser.matchAllOptions.push(structuredClone(options));
        return browser.clients.filter((client) => options.includeUncontrolled || client.controller);
      },
      async claim() {
        browser.order.push('claim');
        browser.claimCalls += 1;
        browser.clients.forEach((client) => {
          client.controller = browser.active;
        });
      },
      async openWindow(url) {
        browser.openedWindows.push(url);
        return null;
      },
    };
  }

  addClient(id, options) {
    const client = new FakeWindowClient(id, options);
    this.clients.push(client);
    return client;
  }

  createWorker(source, scriptUrl) {
    const browser = this;
    const listeners = {};
    const worker = {
      scriptUrl,
      listeners,
      skipWaitingCalls: 0,
      handle: null,
      sandbox: null,
    };
    worker.handle = {
      scriptURL: new URL(scriptUrl, ORIGIN).href,
      state: 'parsed',
      postMessage: (data, ports = []) => browser.deliverMessage(worker, data, ports),
    };
    const resolveUrl = (input) => (typeof input === 'string' ? new URL(input, ORIGIN).href : input);
    class SwRequest extends Request {
      constructor(input, init) {
        super(resolveUrl(input), init);
      }
    }
    const sandbox = {
      console,
      URL,
      Response,
      Headers,
      AbortController,
      Request: SwRequest,
      setTimeout: this.clock.setTimeout,
      clearTimeout: this.clock.clearTimeout,
      caches: this.caches,
      fetch: this.network.fetch,
      registration: this.registration,
      clients: this.clientsApi,
      location: new URL(worker.handle.scriptURL),
      skipWaiting: async () => {
        worker.skipWaitingCalls += 1;
        browser.order.push('skipWaiting');
        if (browser.waiting === worker) {
          await browser.activate(worker);
        }
      },
      addEventListener: (type, listener) => {
        (listeners[type] ||= []).push(listener);
      },
    };
    sandbox.self = sandbox;
    worker.sandbox = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(source, sandbox, { filename: scriptUrl });
    this.workers.push(worker);
    return worker;
  }

  async dispatchExtendable(worker, type, extra = {}) {
    const pending = [];
    const event = {
      type,
      ...extra,
      waitUntil: (promise) => {
        pending.push(Promise.resolve(promise));
      },
    };
    (worker.listeners[type] || []).forEach((listener) => listener(event));
    let settled = 0;
    while (settled < pending.length) {
      const batch = pending.slice(settled);
      settled = pending.length;
      await Promise.all(batch);
    }
    return event;
  }

  async activate(worker) {
    const previous = this.active;
    if (this.waiting === worker) {
      this.waiting = null;
    }
    this.active = worker;
    worker.handle.state = 'activating';
    if (previous && previous !== worker) {
      previous.handle.state = 'redundant';
    }
    await this.dispatchExtendable(worker, 'activate');
    worker.handle.state = 'activated';
  }

  /** Browser "Update" algorithm: new worker installs; waits only if nobody asked to skip waiting. */
  async install(source, scriptUrl) {
    const worker = this.createWorker(source, scriptUrl);
    this.installing = worker;
    worker.handle.state = 'installing';
    await this.dispatchExtendable(worker, 'install');
    this.installing = null;
    worker.handle.state = 'installed';
    if (this.waiting && this.waiting !== worker) {
      this.waiting.handle.state = 'redundant';
    }
    this.waiting = worker;
    if (worker.skipWaitingCalls > 0 || !this.active) {
      await this.activate(worker);
    }
    return worker;
  }

  async deliverMessage(worker, data, ports = []) {
    const source = this.clients[0] ?? null;
    await this.dispatchExtendable(worker, 'message', { data, ports, source });
  }

  async fetchEvent(worker, requestLike, { preload } = {}) {
    let responded = null;
    const waits = [];
    const event = {
      type: 'fetch',
      request: requestLike,
      preloadResponse: Promise.resolve(preload),
      respondWith: (promise) => {
        responded = Promise.resolve(promise);
      },
      waitUntil: (promise) => {
        waits.push(Promise.resolve(promise).catch(() => undefined));
      },
    };
    (worker.listeners.fetch || []).forEach((listener) => listener(event));
    if (!responded) {
      return { intercepted: false };
    }
    const settle = async () => {
      let seen = 0;
      while (seen < waits.length) {
        const batch = waits.slice(seen);
        seen = waits.length;
        await Promise.all(batch);
      }
    };
    return {
      intercepted: true,
      response: responded,
      settle,
    };
  }

  /** Convenience: run a fetch event to completion and return the response (or the rejection). */
  async handle(worker, requestLike, options) {
    const outcome = await this.fetchEvent(worker, requestLike, options);
    if (!outcome.intercepted) {
      return outcome;
    }
    let response;
    let error;
    try {
      response = await outcome.response;
    } catch (caught) {
      error = caught;
    }
    await outcome.settle();
    return { intercepted: true, response, error };
  }
}

function makePort() {
  const received = [];
  return { port: { postMessage: (message) => received.push(structuredClone(message)) }, received };
}

function getRequest(urlPath, init = {}) {
  return new Request(new URL(urlPath, ORIGIN).href, init);
}

function navigationRequest(urlPath = '/store.php') {
  return { url: new URL(urlPath, ORIGIN).href, method: 'GET', mode: 'navigate', headers: new Headers() };
}

async function bodyOf(response) {
  return response.text();
}

const SEED_NETWORK = (network) => {
  network.set('/offline.html', '<html>offline</html>');
  network.set('/icons/icon-192.png', 'icon192');
  network.set('/icons/icon-512.png', 'icon512');
  [
    '/icons/app-icon.svg',
    '/css/site-brand.css',
    '/css/site-header.css',
    '/css/site-footer.css',
    '/css/material-image-frame.css',
    '/css/store-ui.css',
    '/css/store-cart.css',
    '/css/home-page.css',
    '/css/pwa-install.css',
    '/assets/store-pref.js',
    '/assets/site-analytics.js',
    '/assets/pwa.js',
    '/favicon.ico',
  ].forEach((p) => network.set(p, `server:${p}`));
};

describe('sw.js build identity', () => {
  test('build comes from ?b= and names the static cache generation', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const worker = browser.createWorker(NEW_SW, '/sw.js?b=abc123def456');
    assert.equal(worker.sandbox.BUILD, undefined, 'consts are not leaked to globalThis');
    await browser.install(NEW_SW, '/sw.js?b=abc123def456');
    assert.ok(browser.caches.names().includes('jawish-b-abc123def456-static'));
  });

  test('missing or hostile ?b= falls back to "legacy"', async () => {
    for (const url of ['/sw.js', '/sw.js?v=17', '/sw.js?b=', '/sw.js?b=a%20b', '/sw.js?b=%3Cscript%3E', '/sw.js?b=' + 'x'.repeat(40)]) {
      const browser = new FakeBrowser();
      SEED_NETWORK(browser.network);
      await browser.install(NEW_SW, url);
      assert.ok(browser.caches.names().includes('jawish-b-legacy-static'), `${url} -> ${browser.caches.names()}`);
    }
  });

  test('GET_BUILD answers on the MessagePort', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const worker = await browser.install(NEW_SW, '/sw.js?b=feedface0001');
    const { port, received } = makePort();
    await browser.deliverMessage(worker, { type: 'GET_BUILD' }, [port]);
    assert.deepEqual(received, [{ type: 'PORTAL_SW_BUILD', build: 'feedface0001' }]);
  });

  test('GET_BUILD without a port answers the sending client', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const client = browser.addClient('c1');
    const worker = await browser.install(NEW_SW, '/sw.js?b=feedface0002');
    await browser.deliverMessage(worker, { type: 'GET_BUILD' }, []);
    assert.deepEqual(client.messages.filter((m) => m.type === 'PORTAL_SW_BUILD'), [{ type: 'PORTAL_SW_BUILD', build: 'feedface0002' }]);
  });

  test('legacy workers never answer GET_BUILD (documents why the page must time out)', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const legacy = fs.readFileSync(path.join(LEGACY_DIR, 'v24-226-gated-final.js'), 'utf8');
    const worker = await browser.install(legacy, '/sw.js?v=17');
    const { port, received } = makePort();
    await browser.deliverMessage(worker, { type: 'GET_BUILD' }, [port]);
    assert.deepEqual(received, []);
  });

  test('old SKIP_WAITING message is still honoured', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const worker = await browser.install(NEW_SW, '/sw.js?b=feedface0003');
    const before = worker.skipWaitingCalls;
    await browser.deliverMessage(worker, { type: 'SKIP_WAITING' });
    assert.equal(worker.skipWaitingCalls, before + 1);
  });

  test('malformed messages are ignored', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const worker = await browser.install(NEW_SW, '/sw.js?b=feedface0004');
    for (const data of [undefined, null, 'GET_BUILD', 42, {}, { type: 'NOPE' }]) {
      await browser.deliverMessage(worker, data, []);
    }
    assert.equal(browser.clients.length, 0);
  });
});

describe('sw.js install', () => {
  test('skipWaiting() is called without any page message, even while another worker is active', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const first = await browser.install(NEW_SW, '/sw.js?b=aaaaaaaaaaaa');
    assert.equal(browser.active, first);
    const second = await browser.install(NEW_SW, '/sw.js?b=bbbbbbbbbbbb');
    assert.ok(second.skipWaitingCalls >= 1);
    assert.equal(browser.active, second, 'second worker took over without waiting');
    assert.equal(browser.waiting, null);
    assert.equal(first.handle.state, 'redundant');
  });

  test('precaches the offline page and icons into the build cache', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    await browser.install(NEW_SW, '/sw.js?b=cccccccccccc');
    const cache = await browser.caches.open('jawish-b-cccccccccccc-static');
    assert.deepEqual(cache.urls().sort(), ['/icons/icon-192.png', '/icons/icon-512.png', '/offline.html']);
    const requests = browser.network.requestsFor('/offline.html');
    assert.equal(requests[0].cache, 'reload', 'precache bypasses the immutable HTTP cache');
  });

  test('install still completes when every precache request fails (offline)', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    browser.network.offline = true;
    const worker = await browser.install(NEW_SW, '/sw.js?b=dddddddddddd');
    assert.equal(browser.active, worker);
    assert.ok(worker.skipWaitingCalls >= 1);
  });

  test('install still completes when precache gets 404s', async () => {
    const browser = new FakeBrowser();
    const worker = await browser.install(NEW_SW, '/sw.js?b=eeeeeeeeeeee');
    assert.equal(browser.active, worker);
    const cache = await browser.caches.open('jawish-b-eeeeeeeeeeee-static');
    assert.deepEqual(cache.urls(), []);
  });

  test('a hanging precache cannot hold the install past its time budget', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    browser.network.fetch = () => new Promise(() => {});
    const worker = browser.createWorker(NEW_SW, '/sw.js?b=ffffffffffff');
    browser.installing = worker;
    let done = false;
    const install = browser.dispatchExtendable(worker, 'install').then(() => {
      done = true;
    });
    await flush();
    assert.equal(done, false, 'still waiting on the network');
    assert.equal(worker.skipWaitingCalls, 1, 'skipWaiting was requested immediately, not after precache');
    await browser.clock.advance(4000);
    assert.equal(done, false, 'per-request abort alone does not settle a fetch that ignores it');
    await browser.clock.advance(2100);
    await Promise.race([install, new Promise((resolve) => setTimeout(resolve, 500))]);
    assert.equal(done, true, 'install budget elapsed');
  });
});

describe('sw.js activate', () => {
  test('purges every older jawish-* generation, keeps current + fonts, never touches foreign caches', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    for (const name of ['jawish-v24-static', 'jawish-v1-static', 'jawish-b-oldbuild00000-static', 'jawish-b-legacy-static', 'jawish-fonts-v1', 'workbox-precache-v2', 'my-other-app', 'JAWISH-upper']) {
      await browser.caches.open(name);
    }
    await browser.install(NEW_SW, '/sw.js?b=111111111111');
    assert.deepEqual(browser.caches.names(), ['JAWISH-upper', 'jawish-b-111111111111-static', 'jawish-fonts-v1', 'my-other-app', 'workbox-precache-v2']);
  });

  test('enables navigation preload, claims clients, then tells every window (incl. uncontrolled)', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const controlled = browser.addClient('controlled');
    controlled.controller = {};
    const uncontrolled = browser.addClient('uncontrolled');
    const hidden = browser.addClient('hidden', { visibilityState: 'hidden' });
    await browser.install(NEW_SW, '/sw.js?b=222222222222');
    assert.equal(browser.preloadEnabled, true);
    assert.equal(browser.claimCalls, 1);
    assert.ok(browser.order.indexOf('claim') > browser.order.indexOf('preload'));
    assert.deepEqual(browser.matchAllOptions.at(-1), { type: 'window', includeUncontrolled: true });
    for (const client of [controlled, uncontrolled, hidden]) {
      assert.deepEqual(client.messages, [{ type: 'PORTAL_SW_ACTIVATED', build: '222222222222' }], client.id);
      assert.ok(client.controller, `${client.id} is now controlled`);
    }
  });

  test('a failing cache purge cannot block claim + notify', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const client = browser.addClient('c');
    browser.caches.failKeys = true;
    await browser.install(NEW_SW, '/sw.js?b=333333333333');
    assert.equal(browser.claimCalls, 1);
    assert.equal(client.messages.length, 1);
  });

  test('a throwing client does not stop the others from being notified', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const broken = browser.addClient('broken');
    broken.postMessage = () => {
      throw new Error('closed');
    };
    const healthy = browser.addClient('healthy');
    await browser.install(NEW_SW, '/sw.js?b=444444444444');
    assert.equal(healthy.messages.length, 1);
  });

  test('offline page survives the purge when the new generation could not precache it', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    await browser.install(NEW_SW, '/sw.js?b=555555555555');
    browser.network.offline = true;
    await browser.install(NEW_SW, '/sw.js?b=666666666666');
    const cache = await browser.caches.open('jawish-b-666666666666-static');
    assert.ok(cache.urls().includes('/offline.html'));
    assert.ok(!browser.caches.names().includes('jawish-b-555555555555-static'));
  });
});

describe('sw.js fetch routing', () => {
  async function installed(build = '777777777777') {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const worker = await browser.install(NEW_SW, `/sw.js?b=${build}`);
    return { browser, worker, cacheName: `jawish-b-${build}-static` };
  }

  test('never intercepts API, auth, dashboard, manifest, media, share-image, short links, version endpoint or sw.js', async () => {
    const { browser, worker } = await installed();
    for (const p of ['/api/store-cart.php', '/api/app-version.php', '/api/notifications.php?x=1', '/customer-login.php', '/staff-login.php', '/login.php', '/logout.php', '/register.php', '/cart.php', '/manifest.php', '/dashboard/orders.php', '/dashboard/x.js', '/media/logo.png', '/share-image.php?t=1', '/s/abc', '/s/abc123.js', '/sw.js', '/sw.js?b=zzz']) {
      const outcome = await browser.handle(worker, getRequest(p));
      assert.equal(outcome.intercepted, false, p);
    }
  });

  test('never intercepts non-GET requests, not even static-looking ones', async () => {
    const { browser, worker } = await installed();
    for (const method of ['POST', 'PUT', 'DELETE', 'PATCH']) {
      for (const p of ['/api/store-cart.php', '/assets/app.js', '/customer-login.php']) {
        const outcome = await browser.handle(worker, getRequest(p, { method, body: '{}' }));
        assert.equal(outcome.intercepted, false, `${method} ${p}`);
      }
    }
    assert.equal(browser.network.log.filter((entry) => entry.method !== 'GET').length, 0);
  });

  test('ignores plain .php subresources, extension-less paths and cross-origin requests', async () => {
    const { browser, worker } = await installed();
    for (const url of [ORIGIN + '/icons/icon-png.php?size=32', ORIGIN + '/products', 'https://cdn.example.com/lib.js', 'https://www.google-analytics.com/a.js']) {
      const outcome = await browser.handle(worker, new Request(url));
      assert.equal(outcome.intercepted, false, url);
    }
  });

  test('navigation: network answer is returned and NOT cached', async () => {
    const { browser, worker, cacheName } = await installed();
    browser.network.set('/store.php', '<html>fresh</html>');
    const outcome = await browser.handle(worker, navigationRequest('/store.php'));
    assert.equal(await bodyOf(outcome.response), '<html>fresh</html>');
    const cache = await browser.caches.open(cacheName);
    assert.ok(!cache.urls().includes('/store.php'));
    assert.ok(![...browser.caches.stores.values()].some((store) => store.urls().includes('/store.php')));
  });

  test('navigation: uses the navigation preload response when present', async () => {
    const { browser, worker } = await installed();
    const before = browser.network.log.length;
    const outcome = await browser.handle(worker, navigationRequest('/store.php'), { preload: basic('<html>preloaded</html>') });
    assert.equal(await bodyOf(outcome.response), '<html>preloaded</html>');
    assert.equal(browser.network.log.length, before, 'no second network request');
  });

  test('navigation: offline shows offline.html, online again shows the live page', async () => {
    const { browser, worker } = await installed();
    browser.network.set('/store.php', '<html>live</html>');
    browser.network.offline = true;
    const offline = await browser.handle(worker, navigationRequest('/store.php'));
    assert.equal(await bodyOf(offline.response), '<html>offline</html>');
    browser.network.offline = false;
    const online = await browser.handle(worker, navigationRequest('/store.php'));
    assert.equal(await bodyOf(online.response), '<html>live</html>');
  });

  test('navigation to login/logout pages is network-only as well', async () => {
    const { browser, worker } = await installed();
    for (const p of ['/customer-login.php', '/logout.php', '/login.php?next=%2F']) {
      browser.network.set(p, `<html>${p}</html>`);
      const online = await browser.handle(worker, navigationRequest(p));
      assert.equal(await bodyOf(online.response), `<html>${p}</html>`);
    }
    assert.ok(![...browser.caches.stores.values()].some((store) => store.urls().some((u) => u.includes('login') || u.includes('logout'))));
  });

  test('navigation: no offline page anywhere -> network error, not a hang', async () => {
    const browser = new FakeBrowser();
    const worker = await browser.install(NEW_SW, '/sw.js?b=888888888888');
    browser.network.offline = true;
    const outcome = await browser.handle(worker, navigationRequest('/'));
    assert.equal(outcome.response.type, 'error');
  });

  test('JS: network-first, fetched with cache:no-cache, stored for later', async () => {
    const { browser, worker, cacheName } = await installed();
    browser.network.set('/assets/app.js', 'v1');
    const first = await browser.handle(worker, getRequest('/assets/app.js'));
    assert.equal(await bodyOf(first.response), 'v1');
    assert.equal(browser.network.requestsFor('/assets/app.js')[0].cache, 'no-cache', 'must bypass nginx immutable 30d caching');
    const cache = await browser.caches.open(cacheName);
    assert.ok(cache.urls().includes('/assets/app.js'));
  });

  test('JS: a new deploy is served immediately (no stale-while-revalidate lag)', async () => {
    const { browser, worker } = await installed();
    browser.network.set('/assets/app.js', 'old build');
    await browser.handle(worker, getRequest('/assets/app.js'));
    browser.network.set('/assets/app.js', 'new build');
    const next = await browser.handle(worker, getRequest('/assets/app.js'));
    assert.equal(await bodyOf(next.response), 'new build');
  });

  test('CSS/JS: network failure falls back to the cached copy', async () => {
    const { browser, worker } = await installed();
    browser.network.set('/css/site.css', 'body{}');
    browser.network.set('/assets/app.js', 'js');
    await browser.handle(worker, getRequest('/css/site.css'));
    await browser.handle(worker, getRequest('/assets/app.js'));
    browser.network.offline = true;
    assert.equal(await bodyOf((await browser.handle(worker, getRequest('/css/site.css'))).response), 'body{}');
    assert.equal(await bodyOf((await browser.handle(worker, getRequest('/assets/app.js'))).response), 'js');
  });

  test('JS: a hanging network answers from cache after the timeout and still refreshes the cache later', async () => {
    const { browser, worker } = await installed();
    browser.network.set('/assets/app.js', 'cached');
    await browser.handle(worker, getRequest('/assets/app.js'));

    let release;
    browser.network.set('/assets/app.js', () => new Promise((resolve) => {
      release = () => resolve(basic('fresh after delay'));
    }));
    const pending = browser.fetchEvent(worker, getRequest('/assets/app.js'));
    const outcome = await pending;
    let settled = false;
    outcome.response.then(() => {
      settled = true;
    });
    await browser.clock.advance(3400);
    assert.equal(settled, false, 'still inside the network-first window');
    await browser.clock.advance(200);
    assert.equal(settled, true);
    assert.equal(await bodyOf(await outcome.response), 'cached');

    release();
    await outcome.settle();
    await flush();
    browser.network.offline = true;
    const later = await browser.handle(worker, getRequest('/assets/app.js'));
    assert.equal(await bodyOf(later.response), 'fresh after delay', 'background refresh updated the cache');
  });

  test('JS: slow network with nothing cached keeps waiting instead of failing at the timeout', async () => {
    const { browser, worker } = await installed();
    let release;
    browser.network.set('/assets/never-seen.js', () => new Promise((resolve) => {
      release = () => resolve(basic('eventually'));
    }));
    const outcome = await browser.fetchEvent(worker, getRequest('/assets/never-seen.js'));
    let settled = false;
    outcome.response.then(() => {
      settled = true;
    }, () => {
      settled = true;
    });
    await browser.clock.advance(10_000);
    assert.equal(settled, false, 'no cached copy: wait for the network');
    release();
    await flush();
    assert.equal(await bodyOf(await outcome.response), 'eventually');
  });

  test('JS: offline with nothing cached is a network error (not a hang)', async () => {
    const { browser, worker } = await installed();
    browser.network.offline = true;
    const outcome = await browser.handle(worker, getRequest('/assets/never-cached.js'));
    assert.ok(outcome.error instanceof Error);
  });

  test('error responses are returned but not cached', async () => {
    const { browser, worker } = await installed();
    browser.network.set('/assets/missing.js', () => basic('nope', { status: 404 }));
    const outcome = await browser.handle(worker, getRequest('/assets/missing.js'));
    assert.equal(outcome.response.status, 404);
    assert.ok(![...browser.caches.stores.values()].some((store) => store.urls().includes('/assets/missing.js')));
  });

  test('?v= URLs are cache-first: second request never touches the network', async () => {
    const { browser, worker } = await installed();
    browser.network.set('/assets/app.js?v=1700000000', 'versioned');
    const first = await browser.handle(worker, getRequest('/assets/app.js?v=1700000000'));
    assert.equal(await bodyOf(first.response), 'versioned');
    assert.equal(browser.network.requestsFor('/assets/app.js?v=1700000000').length, 1);
    browser.network.set('/assets/app.js?v=1700000000', 'changed on server');
    const second = await browser.handle(worker, getRequest('/assets/app.js?v=1700000000'));
    assert.equal(await bodyOf(second.response), 'versioned');
    assert.equal(browser.network.requestsFor('/assets/app.js?v=1700000000').length, 1);
    const third = await browser.handle(worker, getRequest('/assets/app.js?v=1700000001'));
    assert.equal(third.response.status, 404, 'a new ?v= is a different cache entry (hits the network)');
  });

  test('unversioned images are network-first too', async () => {
    const { browser, worker } = await installed();
    browser.network.set('/icons/logo.png', 'png-1');
    await browser.handle(worker, getRequest('/icons/logo.png'));
    browser.network.set('/icons/logo.png', 'png-2');
    assert.equal(await bodyOf((await browser.handle(worker, getRequest('/icons/logo.png'))).response), 'png-2');
  });

  test('Google font files stay cached across generations', async () => {
    const { browser, worker } = await installed('999999999999');
    browser.network.set('https://fonts.gstatic.com/s/x.woff2', 'font-bytes');
    await browser.handle(worker, new Request('https://fonts.gstatic.com/s/x.woff2'));
    await browser.install(NEW_SW, '/sw.js?b=aaaaaaaaaaab');
    const fonts = await browser.caches.open('jawish-fonts-v1');
    assert.ok(fonts.urls().some((u) => u.includes('/s/x.woff2')));
    browser.network.offline = true;
    const worker2 = browser.active;
    const hit = await browser.handle(worker2, new Request('https://fonts.gstatic.com/s/x.woff2'));
    assert.equal(await bodyOf(hit.response), 'font-bytes');
  });
});

describe('sw.js push + notification click (unchanged behaviour)', () => {
  test('push shows an OS notification only when no window is visible/focused', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const worker = await browser.install(NEW_SW, '/sw.js?b=bbbbbbbbbbbc');
    const payload = { json: () => ({ id: 'n1', title: 'Hello', body: 'Body', url: '/my-orders.php' }), text: () => '' };

    const visible = browser.addClient('visible', { visibilityState: 'visible' });
    visible.messages.length = 0;
    await browser.dispatchExtendable(worker, 'push', { data: payload });
    assert.equal(browser.notifications.length, 0, 'visible window: no OS notification');
    assert.equal(visible.messages.at(-1).type, 'portal-notification');

    visible.visibilityState = 'hidden';
    await browser.dispatchExtendable(worker, 'push', { data: payload });
    assert.equal(browser.notifications.length, 1);
    assert.equal(browser.notifications[0].title, 'Hello');
    assert.equal(browser.notifications[0].options.tag, 'n1');
  });

  test('notification click on the apex worker rewrites a www URL onto the apex origin', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const worker = browser.createWorker(NEW_SW, 'https://jawishco.sy/sw.js?b=cccccccccccd');
    browser.network.set('https://jawishco.sy/api/notifications.php', 'ok');
    const client = browser.addClient('apex-window', { url: 'https://jawishco.sy/' });
    let closed = false;
    await browser.dispatchExtendable(worker, 'notificationclick', {
      notification: { close: () => { closed = true; }, data: { id: 'jawish-notification', url: 'https://www.jawishco.sy/my-orders.php?x=1#top' } },
    });
    assert.equal(closed, true);
    assert.deepEqual(client.navigations, ['https://jawishco.sy/my-orders.php?x=1#top']);
  });

  test('pushsubscriptionchange asks pages to re-subscribe', async () => {
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const worker = await browser.install(NEW_SW, '/sw.js?b=cccccccccccf');
    const client = browser.addClient('c');
    await browser.dispatchExtendable(worker, 'pushsubscriptionchange');
    assert.deepEqual(client.messages.at(-1), { type: 'portal-push-resubscribe' });
  });
});

describe('legacy generations: automatic takeover (no tap, no reinstall)', () => {
  const manifest = JSON.parse(fs.readFileSync(path.join(LEGACY_DIR, 'MANIFEST.json'), 'utf8'));

  test('fixtures are the untouched historical files', () => {
    assert.ok(manifest.fixtures.length >= 3);
    for (const fixture of manifest.fixtures) {
      const bytes = fs.readFileSync(path.join(LEGACY_DIR, fixture.file));
      assert.equal(crypto.createHash('sha256').update(bytes).digest('hex'), fixture.sha256, fixture.file);
      assert.equal(bytes.length, fixture.bytes, fixture.file);
    }
    const covered = manifest.fixtures.map((fixture) => fixture.cacheVersion);
    for (const needed of ['jawish-v16', 'jawish-v22', 'jawish-v24']) {
      assert.ok(covered.includes(needed), `${needed} generation covered`);
    }
  });

  test('the current NEW sw.js is not byte-identical to any shipped generation (so browsers see an update)', () => {
    for (const fixture of manifest.fixtures) {
      assert.notEqual(fs.readFileSync(path.join(LEGACY_DIR, fixture.file), 'utf8'), NEW_SW, fixture.file);
    }
  });

  for (const fixture of manifest.fixtures) {
    describe(`${fixture.cacheVersion} (${fixture.commit})`, () => {
      const legacySource = fs.readFileSync(path.join(LEGACY_DIR, fixture.file), 'utf8');

      async function withLegacyActive(registeredUrl) {
        const browser = new FakeBrowser();
        SEED_NETWORK(browser.network);
        const legacy = await browser.install(legacySource, registeredUrl);
        assert.equal(browser.active, legacy, 'legacy worker is active before the fix ships');
        await (await browser.caches.open('my-other-app')).put('/keep', basic('keep'));
        await (await browser.caches.open('workbox-precache-v2')).put('/keep2', basic('keep'));
        const controlled = browser.addClient('window-1');
        controlled.controller = legacy;
        const second = browser.addClient('window-2');
        second.controller = legacy;
        return { browser, legacy, clients: [controlled, second] };
      }

      for (const registeredUrl of ['/sw.js?v=17', '/sw.js']) {
        test(`browser update check of ${registeredUrl} (same URL, new bytes) installs, activates and claims on its own`, async () => {
          const { browser, legacy, clients } = await withLegacyActive(registeredUrl);
          assert.ok(browser.caches.names().some((name) => name.startsWith('jawish-')), 'legacy generation created its caches');
          browser.order.length = 0;
          browser.claimCalls = 0;

          const next = await browser.install(NEW_SW, registeredUrl);

          assert.equal(browser.active, next, 'new worker is active');
          assert.equal(browser.waiting, null, 'nothing is parked waiting for a tap');
          assert.equal(legacy.handle.state, 'redundant');
          assert.equal(next.skipWaitingCalls >= 1, true, 'skipWaiting() was called by the worker itself');
          assert.equal(browser.claimCalls, 1, 'clients.claim() ran');
          const jawish = browser.caches.names().filter((name) => name.startsWith('jawish-'));
          assert.deepEqual(jawish, ['jawish-b-legacy-static'].concat(browser.caches.names().includes('jawish-fonts-v1') ? ['jawish-fonts-v1'] : []).sort(), 'every older jawish cache is gone');
          assert.ok(browser.caches.names().includes('my-other-app') && browser.caches.names().includes('workbox-precache-v2'), 'foreign caches untouched');
          for (const client of clients) {
            assert.deepEqual(client.messages, [{ type: 'PORTAL_SW_ACTIVATED', build: 'legacy' }], 'open pages are told');
            assert.equal(client.controller, next, 'open pages are now controlled by the new worker');
          }
        });
      }

      test('then the page controller swaps /sw.js?v=17 for /sw.js?b=<build> and the new generation owns the caches', async () => {
        const { browser, clients } = await withLegacyActive('/sw.js?v=17');
        await browser.install(NEW_SW, '/sw.js?v=17');
        clients.forEach((client) => {
          client.messages.length = 0;
        });
        const worker = await browser.install(NEW_SW, '/sw.js?b=0123456789ab');
        assert.equal(browser.active, worker);
        assert.deepEqual(browser.caches.names().filter((name) => name.startsWith('jawish-') && name !== 'jawish-fonts-v1'), ['jawish-b-0123456789ab-static']);
        clients.forEach((client) => assert.deepEqual(client.messages, [{ type: 'PORTAL_SW_ACTIVATED', build: '0123456789ab' }]));
        const { port, received } = makePort();
        await browser.deliverMessage(worker, { type: 'GET_BUILD' }, [port]);
        assert.deepEqual(received, [{ type: 'PORTAL_SW_BUILD', build: '0123456789ab' }]);
      });

      test('rescue still works when the legacy worker already has an update parked in "waiting"', async () => {
        const { browser, legacy } = await withLegacyActive('/sw.js?v=17');
        const parked = await browser.install(legacySource, '/sw.js?v=17-parked');
        const next = await browser.install(NEW_SW, '/sw.js?b=0123456789ac');
        assert.equal(browser.active, next);
        assert.equal(browser.waiting, null);
        assert.ok(['redundant'].includes(legacy.handle.state));
        assert.ok(['redundant', 'activated'].includes(parked.handle.state));
      });
    });
  }

  test('control: a gated #226 worker parks its update until a tap (the bug being fixed)', async () => {
    const gated = fs.readFileSync(path.join(LEGACY_DIR, 'v24-226-gated-final.js'), 'utf8');
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    const active = await browser.install(gated, '/sw.js?v=17');
    const update = await browser.install(gated.replace('jawish-v24', 'jawish-v25'), '/sw.js?v=17');
    assert.equal(browser.active, active, 'old gated worker stays active');
    assert.equal(browser.waiting, update, 'update is parked waiting for the banner tap');
    assert.equal(update.skipWaitingCalls, 0);
  });

  test('control: early generations keep serving stale cache-first JS until replaced; the new worker serves fresh JS', async () => {
    const early = fs.readFileSync(path.join(LEGACY_DIR, 'v05-precache-cache-first.js'), 'utf8');
    const browser = new FakeBrowser();
    SEED_NETWORK(browser.network);
    browser.network.set('/assets/pwa.js', 'OLD pwa.js (with tap-to-update banner)');
    const legacy = await browser.install(early, '/sw.js');
    browser.network.set('/assets/pwa.js', 'NEW pwa.js');
    const stale = await browser.handle(legacy, getRequest('/assets/pwa.js'));
    assert.equal(await bodyOf(stale.response), 'OLD pwa.js (with tap-to-update banner)', 'v5 answers from its cache forever');

    const next = await browser.install(NEW_SW, '/sw.js');
    const fresh = await browser.handle(next, getRequest('/assets/pwa.js'));
    assert.equal(await bodyOf(fresh.response), 'NEW pwa.js');
  });
});
