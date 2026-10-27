/**
 * Jawish store PWA service worker.
 *
 * Zero-touch updates: pages register /sw.js?b=<build> (build = content hash of the shipped
 * client code, see Portal\Support\PwaBuild). A new build is a new script URL, so the browser
 * always installs a new worker; that worker skips waiting, purges older caches, claims every
 * open page and announces itself (PORTAL_SW_ACTIVATED) so each page can reload when it is safe.
 * There is no "tap to update" step. Without ?b= (legacy registrations such as /sw.js?v=17) the
 * build is "legacy"; the page controller then replaces that registration with /sw.js?b=<build>.
 *
 * Caching:
 *  - HTML navigations: network (navigation preload), /offline.html when the network is gone. Never cached.
 *  - Versioned static files (?v=mtime): cache-first.
 *  - Other static files (JS/CSS/images): network-first with a short timeout, cache as fallback.
 *    They are fetched with cache:'no-cache' because nginx marks static files immutable for 30 days.
 *  - API, auth, dashboard, manifest, media, share images, short links and this script are never intercepted.
 */
const BUILD = (function resolveBuild() {
  try {
    const raw = new URL(self.location.href).searchParams.get('b');
    return raw && /^[A-Za-z0-9._-]{1,32}$/.test(raw) ? raw : 'legacy';
  } catch (_) {
    return 'legacy';
  }
})();
const CACHE_PREFIX = 'jawish-';
const STATIC_CACHE = CACHE_PREFIX + 'b-' + BUILD + '-static';
const FONT_CACHE = 'jawish-fonts-v1';
const OFFLINE_URL = '/offline.html';
const STATIC_MAX_ENTRIES = 120;
const PRECACHE_TIMEOUT_MS = 4000;
const INSTALL_BUDGET_MS = 6000;
const NETWORK_FIRST_TIMEOUT_MS = 3500;
const TIMED_OUT = {};

const PRECACHE_URLS = [
  OFFLINE_URL,
  '/icons/icon-192.png',
  '/icons/icon-512.png',
];

const NO_CACHE_PREFIXES = [
  '/dashboard/',
  '/api/',
  '/login.php',
  '/staff-login.php',
  '/customer-login.php',
  '/logout.php',
  '/register.php',
  '/cart.php',
  '/manifest.php',
  '/media/',
  '/share-image.php',
  '/s/',
  '/sw.js',
];

const STATIC_EXT = /\.(?:css|js|mjs|png|jpe?g|webp|gif|svg|ico|woff2?|ttf)$/i;

function withTimeout(promise, ms) {
  return new Promise((resolve) => {
    const timer = setTimeout(() => resolve(undefined), ms);
    promise.then(
      (value) => {
        clearTimeout(timer);
        resolve(value);
      },
      () => {
        clearTimeout(timer);
        resolve(undefined);
      }
    );
  });
}

async function safely(step) {
  try {
    await step();
  } catch (_) {
    /* one failing step must never block activation */
  }
}

async function precacheOne(cache, url) {
  const controller = typeof AbortController === 'function' ? new AbortController() : null;
  const timer = controller ? setTimeout(() => controller.abort(), PRECACHE_TIMEOUT_MS) : null;
  try {
    const response = await fetch(url, {
      cache: 'reload',
      signal: controller ? controller.signal : undefined,
    });
    if (response && response.ok) {
      await cache.put(url, response);
    }
  } catch (_) {
    /* precache is best effort */
  } finally {
    if (timer) {
      clearTimeout(timer);
    }
  }
}

async function precache() {
  const cache = await caches.open(STATIC_CACHE);
  await Promise.all(PRECACHE_URLS.map((url) => precacheOne(cache, url)));
}

self.addEventListener('install', (event) => {
  // Always take over right away; a worker that waits for a tap is how installed apps got stuck.
  self.skipWaiting();
  event.waitUntil(withTimeout(precache(), INSTALL_BUDGET_MS));
});

self.addEventListener('message', (event) => {
  const data = event && event.data;
  if (!data || typeof data !== 'object') {
    return;
  }
  if (data.type === 'SKIP_WAITING') {
    self.skipWaiting();
    return;
  }
  if (data.type === 'GET_BUILD') {
    const reply = { type: 'PORTAL_SW_BUILD', build: BUILD };
    const port = event.ports && event.ports[0];
    try {
      if (port) {
        port.postMessage(reply);
      } else if (event.source && typeof event.source.postMessage === 'function') {
        event.source.postMessage(reply);
      }
    } catch (_) {
      /* the asking page may already be gone */
    }
  }
});

async function carryOverOfflinePage(staleKeys) {
  const current = await caches.open(STATIC_CACHE);
  if (await current.match(OFFLINE_URL)) {
    return;
  }
  for (const key of staleKeys) {
    const previous = await caches.open(key);
    const page = await previous.match(OFFLINE_URL);
    if (page) {
      await current.put(OFFLINE_URL, page);
      return;
    }
  }
}

async function purgeOldCaches() {
  const keys = await caches.keys();
  const stale = keys.filter(
    (key) => key.startsWith(CACHE_PREFIX) && key !== STATIC_CACHE && key !== FONT_CACHE
  );
  await safely(() => carryOverOfflinePage(stale));
  await Promise.all(stale.map((key) => caches.delete(key)));
}

async function enableNavigationPreload() {
  if (self.registration && self.registration.navigationPreload) {
    await self.registration.navigationPreload.enable();
  }
}

async function notifyActivated() {
  const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  windows.forEach((client) => {
    try {
      client.postMessage({ type: 'PORTAL_SW_ACTIVATED', build: BUILD });
    } catch (_) {
      /* ignore closed clients */
    }
  });
}

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    await safely(purgeOldCaches);
    await safely(enableNavigationPreload);
    await safely(() => self.clients.claim());
    await safely(notifyActivated);
  })());
});

function parsePushPayload(event) {
  const fallback = {
    id: 'jawish-notification',
    title: 'إشعار جديد',
    body: '',
    url: '/',
    icon: '/icons/brand-icon.php?size=192',
  };
  if (!event || !event.data) {
    return fallback;
  }
  try {
    const parsed = event.data.json();
    return {
      id: String(parsed.id || fallback.id),
      title: String(parsed.title || fallback.title),
      body: String(parsed.body || ''),
      url: String(parsed.url || fallback.url),
      icon: String(parsed.icon || fallback.icon),
    };
  } catch (_) {
    try {
      const text = event.data.text();
      return { ...fallback, body: String(text || '') };
    } catch (_) {
      return fallback;
    }
  }
}

self.addEventListener('push', (event) => {
  const payload = parsePushPayload(event);
  event.waitUntil((async () => {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    let hasFocusedClient = false;
    clients.forEach((client) => {
      try {
        client.postMessage({ type: 'portal-notification', payload });
      } catch (_) {
        /* ignore closed clients */
      }
      if (client.focused || client.visibilityState === 'visible') {
        hasFocusedClient = true;
      }
    });

    // Always show an OS notification when no focused tab — background "visible"
    // clients otherwise swallow the push and the user only sees it after reopen.
    if (hasFocusedClient) {
      return;
    }

    await self.registration.showNotification(payload.title, {
      body: payload.body,
      icon: payload.icon,
      badge: '/icons/brand-icon.php?size=96',
      tag: payload.id,
      renotify: true,
      data: {
        url: payload.url,
        id: payload.id,
      },
    });
  })());
});

// Browser may rotate the push endpoint while the SW is asleep — ask a page to re-save it.
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    clients.forEach((client) => {
      try {
        client.postMessage({ type: 'portal-push-resubscribe' });
      } catch (_) {
        /* ignore */
      }
    });
  })());
});

// www.example.com <-> example.com: same site, different PWA scope.
function isSiblingHost(a, b) {
  a = String(a || '').toLowerCase();
  b = String(b || '').toLowerCase();
  if (!a || !b || a === b) return false;
  return a === 'www.' + b || b === 'www.' + a;
}

function resolveNotificationTargetUrl(rawUrl) {
  const value = String(rawUrl || '').trim();
  if (!value || value === 'null' || value === 'undefined') {
    return new URL('/', self.location.origin).href;
  }
  try {
    const url = new URL(value, self.location.origin);
    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
      return new URL('/', self.location.origin).href;
    }
    if (isSiblingHost(url.hostname, self.location.hostname)) {
      return self.location.origin + url.pathname + url.search + url.hash;
    }
    return url.href;
  } catch (_) {
    return new URL('/', self.location.origin).href;
  }
}

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const notificationId = String(event.notification?.data?.id || '');
  const absoluteUrl = resolveNotificationTargetUrl(event.notification?.data?.url);

  event.waitUntil((async () => {
    // Clicking the OS/mobile notification counts as a view for this device session.
    if (notificationId && notificationId !== 'jawish-notification') {
      try {
        await fetch('/api/notifications.php', {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ action: 'read', id: notificationId, via: 'push' }),
        });
      } catch (_) {
        /* ignore offline failures */
      }
    }

    const clientList = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of clientList) {
      if (!client.url.startsWith(self.location.origin) || !('focus' in client)) {
        continue;
      }
      if ('navigate' in client) {
        const navigated = await client.navigate(absoluteUrl);
        return navigated || client.focus();
      }
      return client.focus();
    }
    if (self.clients.openWindow) {
      return self.clients.openWindow(absoluteUrl);
    }
    return undefined;
  })());
});

async function trimCache(cacheName, maxEntries) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  if (keys.length <= maxEntries) {
    return;
  }
  await Promise.all(keys.slice(0, keys.length - maxEntries).map((key) => cache.delete(key)));
}

function isCacheable(response) {
  return response && response.status === 200 && (response.type === 'basic' || response.type === 'cors');
}

async function putInCache(cacheName, request, response) {
  if (!isCacheable(response)) {
    return;
  }
  const cache = await caches.open(cacheName);
  await cache.put(request, response);
  if (cacheName === STATIC_CACHE) {
    trimCache(STATIC_CACHE, STATIC_MAX_ENTRIES);
  }
}

async function matchIn(cacheName, request) {
  const cache = await caches.open(cacheName);
  return cache.match(request);
}

async function handleNavigation(event) {
  try {
    const preloaded = await event.preloadResponse;
    if (preloaded) {
      return preloaded;
    }
    return await fetch(event.request);
  } catch (_) {
    const offline = (await matchIn(STATIC_CACHE, OFFLINE_URL)) || (await caches.match(OFFLINE_URL));
    return offline || Response.error();
  }
}

async function cacheFirst(event, cacheName) {
  const cached = await matchIn(cacheName, event.request);
  if (cached) {
    return cached;
  }
  const response = await fetch(event.request);
  event.waitUntil(putInCache(cacheName, event.request, response.clone()));
  return response;
}

async function staleWhileRevalidate(event, cacheName) {
  const cached = await matchIn(cacheName, event.request);
  const network = fetch(event.request).then((response) => {
    event.waitUntil(putInCache(cacheName, event.request, response.clone()));
    return response;
  });
  if (cached) {
    event.waitUntil(network.catch(() => undefined));
    return cached;
  }
  return network;
}

// Network first so a fresh deploy is picked up at once; the cache only answers when the
// network fails or is slower than the timeout. A slow answer still refreshes the cache.
async function networkFirst(event, cacheName) {
  const { request } = event;
  const network = fetch(request, { cache: 'no-cache' }).then((response) => {
    event.waitUntil(putInCache(cacheName, request, response.clone()));
    return response;
  });
  event.waitUntil(network.catch(() => undefined));

  let timer;
  const timedOut = new Promise((resolve) => {
    timer = setTimeout(() => resolve(TIMED_OUT), NETWORK_FIRST_TIMEOUT_MS);
  });
  try {
    const first = await Promise.race([network, timedOut]);
    if (first !== TIMED_OUT) {
      return first;
    }
  } catch (_) {
    /* network failed: fall back to the cache below */
  } finally {
    clearTimeout(timer);
  }

  const cached = await matchIn(cacheName, request);
  if (cached) {
    return cached;
  }
  return network;
}

function isNeverCachedPath(pathname) {
  return NO_CACHE_PREFIXES.some((prefix) => pathname.startsWith(prefix));
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') {
    return;
  }
  const url = new URL(request.url);

  if (url.hostname === 'fonts.gstatic.com') {
    event.respondWith(cacheFirst(event, FONT_CACHE));
    return;
  }
  if (url.hostname === 'fonts.googleapis.com') {
    event.respondWith(staleWhileRevalidate(event, FONT_CACHE));
    return;
  }
  if (url.origin !== self.location.origin) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(handleNavigation(event));
    return;
  }

  if (isNeverCachedPath(url.pathname)) {
    return;
  }
  if (url.pathname.endsWith('.php') || !STATIC_EXT.test(url.pathname)) {
    return;
  }

  event.respondWith(
    url.searchParams.has('v')
      ? cacheFirst(event, STATIC_CACHE)
      : networkFirst(event, STATIC_CACHE)
  );
});
