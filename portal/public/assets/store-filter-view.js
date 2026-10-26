/**
 * Shorten filtered store/share URLs to ?v=CODE for the address bar / copy-paste.
 */
(function (global) {
  'use strict';

  const API = '/api/store-filter-view.php';
  const PASS = new Set(['v', 'page', 'token', 't', 'c', 'code', 'preview', 'open_cart']);
  const cache = new Map();
  let pending = Promise.resolve();
  /** Full filter query for chip/form mutations while the address bar shows ?v=. */
  let workingHref = `${global.location.pathname}${global.location.search}`;

  function isCatalogPath(pathname) {
    return pathname === '/store.php' || pathname === '/store'
      || pathname === '/share.php' || pathname === '/share'
      || pathname === '/s.php' || pathname.startsWith('/s/');
  }

  function alreadyShort(url) {
    const keys = [...url.searchParams.keys()];
    if (!url.searchParams.has('v')) return false;
    return keys.every((key) => PASS.has(key.replace(/\[\d+\]$/, '').replace(/\[\]$/, '')));
  }

  function worthShortening(url) {
    if (alreadyShort(url)) return false;
    const meaningful = [...url.searchParams.keys()].filter((key) => {
      const base = key.replace(/\[\d+\]$/, '').replace(/\[\]$/, '');
      return !PASS.has(base);
    });
    if (meaningful.length === 0) return false;
    if (meaningful.length >= 2) return true;
    return url.search.length >= 80;
  }

  function paramsFromUrl(url) {
    const params = {};
    url.searchParams.forEach((value, key) => {
      const base = key.replace(/\[\d+\]$/, '').replace(/\[\]$/, '');
      // Keep page for mint URL suffix; drop identity / already-short keys.
      if (PASS.has(base) && base !== 'page') {
        return;
      }
      const text = String(value).trim();
      if (text === '') return;
      if (key.endsWith('[]') || /\[\d+\]$/.test(key) || Array.isArray(params[base])) {
        if (!Array.isArray(params[base])) {
          params[base] = params[base] != null && String(params[base]).trim() !== ''
            ? [String(params[base])]
            : [];
        }
        params[base].push(text);
        return;
      }
      params[base] = text;
    });
    return params;
  }

  async function mint(href) {
    let url;
    try {
      url = new URL(href, global.location.origin);
    } catch {
      return href;
    }
    if (url.origin !== global.location.origin || !isCatalogPath(url.pathname)) {
      return href;
    }
    if (!worthShortening(url)) {
      return `${url.pathname}${url.search}${url.hash}`;
    }

    const cacheKey = `${url.pathname}?${url.searchParams.toString()}`;
    if (cache.has(cacheKey)) {
      return cache.get(cacheKey);
    }

    try {
      const response = await fetch(API, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          path: url.pathname,
          params: paramsFromUrl(url),
          href: `${url.pathname}${url.search}`,
        }),
      });
      if (!response.ok) return `${url.pathname}${url.search}${url.hash}`;
      const data = await response.json();
      if (!data?.ok || !data.url) {
        return `${url.pathname}${url.search}${url.hash}`;
      }
      const shortUrl = String(data.url);
      cache.set(cacheKey, shortUrl);
      return shortUrl;
    } catch {
      return `${url.pathname}${url.search}${url.hash}`;
    }
  }

  function rememberWorkingHref(href) {
    try {
      const url = new URL(href, global.location.origin);
      workingHref = `${url.pathname}${url.search}`;
    } catch {
      workingHref = String(href || workingHref);
    }
    return workingHref;
  }

  function getWorkingHref() {
    return workingHref || `${global.location.pathname}${global.location.search}`;
  }

  /**
   * After a successful SPA navigation that used the full filter query,
   * replace the address bar with a short ?v= link when useful.
   */
  function shortenAddressBar(href, historyState) {
    rememberWorkingHref(href);
    pending = pending.then(async () => {
      const short = await mint(href);
      if (!short || short === href) return short;
      try {
        const current = `${global.location.pathname}${global.location.search}`;
        const full = new URL(href, global.location.origin);
        const fullKey = `${full.pathname}${full.search}`;
        // Only rewrite if the user is still on the navigation we shortened.
        if (current !== fullKey && current !== short) {
          if (!(current.includes('v=') && fullKey.startsWith(full.pathname))) {
            return short;
          }
        }
        const state = Object.assign({}, historyState == null ? global.history.state : historyState, {
          filterViewFull: fullKey,
          filterViewShort: short,
        });
        global.history.replaceState(state, '', short);
      } catch {
        /* ignore */
      }
      return short;
    }).catch(() => href);

    return pending;
  }

  function readWorkingHrefFromDom() {
    const node = document.querySelector('[data-filter-working-href]');
    const raw = node?.getAttribute('data-filter-working-href') || '';
    if (raw) {
      rememberWorkingHref(raw);
      return raw;
    }
    return '';
  }

  /** On first paint of a long filtered URL, compress the bar. */
  function shortenCurrentIfNeeded() {
    try {
      const embedded = readWorkingHrefFromDom();
      const href = embedded || `${global.location.pathname}${global.location.search}`;
      const barHref = `${global.location.pathname}${global.location.search}`;
      const url = new URL(barHref, global.location.origin);
      if (!isCatalogPath(url.pathname)) {
        return;
      }
      if (!worthShortening(url)) {
        if (!embedded) rememberWorkingHref(barHref);
        return;
      }
      // Prefer minting from the expanded working href when the bar is already short.
      shortenAddressBar(worthShortening(new URL(href, global.location.origin)) ? href : barHref, global.history.state);
    } catch {
      /* ignore */
    }
  }

  global.addEventListener('popstate', () => {
    const state = global.history.state;
    if (state && typeof state.filterViewFull === 'string' && state.filterViewFull !== '') {
      rememberWorkingHref(state.filterViewFull);
      return;
    }
    rememberWorkingHref(`${global.location.pathname}${global.location.search}`);
  });

  global.PortalFilterView = {
    mint,
    shortenAddressBar,
    shortenCurrentIfNeeded,
    rememberWorkingHref,
    workingHref: getWorkingHref,
    worthShortening: (href) => {
      try {
        return worthShortening(new URL(href, global.location.origin));
      } catch {
        return false;
      }
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', shortenCurrentIfNeeded, { once: true });
  } else {
    shortenCurrentIfNeeded();
  }
})(window);
