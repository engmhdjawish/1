/**
 * Jawish store PWA — cache static assets, network-first for pages.
 */
const CACHE_VERSION = 'jawish-v1';
const STATIC_CACHE = CACHE_VERSION + '-static';

const PRECACHE_URLS = [
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
    caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE_URLS)).then(() => self.skipWaiting())
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
