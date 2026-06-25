/**
 * Jawish store PWA — cache static assets, network-first for pages.
 */
const CACHE_VERSION = 'jawish-v8';
const STATIC_CACHE = CACHE_VERSION + '-static';

const PRECACHE_URLS = [
  '/favicon.ico',
  '/icons/app-icon.svg',
  '/css/site-brand.css',
  '/css/site-header.css',
  '/css/site-footer.css',
  '/css/material-image-frame.css',
  '/css/store-ui.css',
  '/css/store-cart.css',
  '/css/home-page.css',
  '/assets/store-pref.js',
  '/assets/site-analytics.js',
  '/css/pwa-install.css',
  '/assets/pwa.js',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
];

const NO_CACHE_PREFIXES = [
  '/dashboard/',
  '/api/',
  '/login.php',
  '/logout.php',
  '/register.php',
  '/cart.php',
  '/manifest.php',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) =>
      Promise.all(
        PRECACHE_URLS.map((url) => cache.add(url).catch(() => undefined))
      )
    ).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key.startsWith('jawish-') && key !== STATIC_CACHE)
          .map((key) => caches.delete(key))
      )
    ).then(() => self.clients.claim())
  );
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
  event.waitUntil(
    self.registration.showNotification(payload.title, {
      body: payload.body,
      icon: payload.icon,
      badge: '/icons/brand-icon.php?size=96',
      tag: payload.id,
      data: {
        url: payload.url,
        id: payload.id,
      },
    })
  );
});

function resolveNotificationTargetUrl(rawUrl) {
  const value = String(rawUrl || '').trim();
  if (!value || value === 'null' || value === 'undefined') {
    return new URL('/', self.location.origin).href;
  }
  return new URL(value, self.location.origin).href;
}

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const absoluteUrl = resolveNotificationTargetUrl(event.notification?.data?.url);

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (!client.url.startsWith(self.location.origin) || !('focus' in client)) {
          continue;
        }
        if ('navigate' in client) {
          return client.navigate(absoluteUrl).then((navigated) => navigated || client.focus());
        }
        return client.focus();
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(absoluteUrl);
      }
      return undefined;
    })
  );
});

function shouldBypassCache(url) {
  if (url.origin !== self.location.origin) {
    return true;
  }
  const path = url.pathname;
  if (NO_CACHE_PREFIXES.some((prefix) => path.startsWith(prefix))) {
    return true;
  }
  if (path.includes('image.php') || path.includes('/media/')) {
    return true;
  }
  return false;
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (shouldBypassCache(url)) {
    return;
  }

  if (request.mode === 'navigate' || url.pathname.endsWith('.php')) {
    event.respondWith(
      fetch(request)
        .then((response) => response)
        .catch(() => caches.match('/index.php').then((cached) => cached || Response.error()))
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) {
        return cached;
      }
      return fetch(request).then((response) => {
        if (!response || response.status !== 200 || response.type === 'opaque') {
          return response;
        }
        const copy = response.clone();
        caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy));
        return response;
      });
    })
  );
});
