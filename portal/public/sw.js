/**
 * Jawish store PWA.
 * Pages: network-first with navigation preload, offline page when the network is gone.
 * Versioned static assets (?v=mtime): cache-first. Unversioned: stale-while-revalidate.
 * Prices and stock are never served from cache.
 */
const CACHE_VERSION = 'jawish-v24';
const STATIC_CACHE = CACHE_VERSION + '-static';
const FONT_CACHE = 'jawish-fonts-v1';
const OFFLINE_URL = '/offline.html';
const STATIC_MAX_ENTRIES = 120;

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
];

const STATIC_EXT = /\.(?:css|js|mjs|png|jpe?g|webp|gif|svg|ico|woff2?|ttf)$/i;

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(STATIC_CACHE);
    await Promise.all(
      PRECACHE_URLS.map((url) => cache.add(new Request(url, { cache: 'reload' })).catch(() => undefined))
    );
    // First install: activate immediately. Later updates wait for the in-app
    // "تحديث" prompt (SKIP_WAITING) so the user chooses when to reload.
    if (!self.registration.active) {
      await self.skipWaiting();
    }
  })());
});

self.addEventListener('message', (event) => {
  const data = event && event.data;
  if (!data || data.type !== 'SKIP_WAITING') {
    return;
  }
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter((key) => key.startsWith('jawish-') && key !== STATIC_CACHE && key !== FONT_CACHE)
        .map((key) => caches.delete(key))
    );
    if (self.registration.navigationPreload) {
      try {
        await self.registration.navigationPreload.enable();
      } catch (_) {}
    }
    await self.clients.claim();
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

async function handleNavigation(event) {
  try {
    const preloaded = await event.preloadResponse;
    if (preloaded) {
      return preloaded;
    }
    return await fetch(event.request);
  } catch (_) {
    const offline = await caches.match(OFFLINE_URL);
    return offline || Response.error();
  }
}

async function cacheFirst(event, cacheName) {
  const cached = await caches.match(event.request);
  if (cached) {
    return cached;
  }
  const response = await fetch(event.request);
  event.waitUntil(putInCache(cacheName, event.request, response.clone()));
  return response;
}

async function staleWhileRevalidate(event, cacheName) {
  const cached = await caches.match(event.request);
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

  if (NO_CACHE_PREFIXES.some((prefix) => url.pathname.startsWith(prefix))) {
    return;
  }
  if (url.pathname.endsWith('.php') || !STATIC_EXT.test(url.pathname)) {
    return;
  }

  event.respondWith(
    url.searchParams.has('v')
      ? cacheFirst(event, STATIC_CACHE)
      : staleWhileRevalidate(event, STATIC_CACHE)
  );
});
