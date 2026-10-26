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

  function absoluteUrl(pathOrUrl) {
    try {
      return new URL(pathOrUrl, global.location.origin).toString();
    } catch {
      return String(pathOrUrl || global.location.href);
    }
  }

  function siteLabel() {
    const scope = document.querySelector('[data-product-share-company], [data-share-filter-view]');
    const fromAttr = scope?.getAttribute('data-product-share-company')
      || document.querySelector('[data-product-share-company]')?.getAttribute('data-product-share-company')
      || '';
    if (fromAttr.trim()) return fromAttr.trim();
    const title = (document.title || '').split('—')[0].trim();
    return title || 'المتجر';
  }

  function shareTitle() {
    const count = document.querySelector('[data-se-total]')?.getAttribute('data-se-total')
      || document.querySelector('.store-results-meta')?.textContent?.match(/\d[\d,]*/)?.[0]
      || '';
    const chips = Array.from(document.querySelectorAll('.se-chip:not(.se-chip--clear) span:not(.material-symbols-outlined), .store-active-chip__text'))
      .map((node) => (node.textContent || '').trim())
      .filter(Boolean)
      .slice(0, 3);
    const site = siteLabel();
    if (chips.length > 0) {
      return `${site} — ${chips.join(' · ')}`;
    }
    if (count) {
      return `${site} — ${count} مادة`;
    }
    return `${site} — نتائج المتجر`;
  }

  function shareOrigin() {
    const host = global.location.hostname;
    if (host === 'jawishco.sy' || host === 'www.jawishco.sy') return 'https://www.jawishco.sy';
    return global.location.origin;
  }

  async function postShort(body) {
    let lastError = null;
    for (let attempt = 0; attempt < 2; attempt++) {
      try {
        const response = await fetch(API, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => null);
        if (response.ok && data?.ok) return data;
        lastError = new Error(data?.message || 'short link failed');
      } catch (error) {
        lastError = error;
      }
    }
    throw lastError || new Error('short link failed');
  }

  /** Short /s/{code} for an exact same-site path such as /product.php?guid=… */
  async function shortenPath(target) {
    const key = `path:${target}`;
    if (cache.has(key)) return cache.get(key);
    const data = await postShort({ kind: 'path', target });
    const url = data.share_url || (data.short_url ? `${shareOrigin()}${data.short_url}` : '');
    if (!url) throw new Error('short link failed');
    cache.set(key, url);
    return url;
  }

  /** Never returns a long filter URL: /s/{code}, or the bare page without query. */
  async function resolveShareUrl() {
    const href = getWorkingHref() || `${global.location.pathname}${global.location.search}`;
    const url = new URL(href, global.location.origin);
    const key = `share:${url.pathname}?${url.searchParams.toString()}`;
    if (cache.has(key)) return cache.get(key);
    const data = await postShort({ path: url.pathname, params: paramsFromUrl(url), href: `${url.pathname}${url.search}` });
    let shareUrl = data.share_url || (data.short_url ? `${shareOrigin()}${data.short_url}` : '');
    if (!shareUrl) {
      const bare = url.pathname.startsWith('/s/') ? url.pathname : (data.url || url.pathname).split('?')[0];
      shareUrl = `${shareOrigin()}${bare}`;
    }
    cache.set(key, shareUrl);
    return shareUrl;
  }

  let shareMenu = null;
  let shareMenuFor = null;

  function closeShareMenu() {
    shareMenu?.remove();
    shareMenu = null;
    shareMenuFor?.setAttribute('aria-expanded', 'false');
    shareMenuFor = null;
  }

  function flashNear(button, text) {
    const tip = document.createElement('span');
    tip.className = 'filter-view-share__toast';
    tip.setAttribute('role', 'status');
    tip.textContent = text;
    const host = button.closest('.filter-view-share') || button.parentElement || document.body;
    host.appendChild(tip);
    global.setTimeout(() => tip.remove(), 1800);
  }

  async function copyUrl(button, url) {
    try {
      await navigator.clipboard.writeText(url);
    } catch {
      const field = document.createElement('textarea');
      field.value = url;
      field.setAttribute('readonly', '');
      field.style.position = 'fixed';
      field.style.opacity = '0';
      document.body.appendChild(field);
      field.select();
      document.execCommand('copy');
      field.remove();
    }
    flashNear(button, 'تم نسخ الرابط');
  }

  function openShareMenu(button, title, url) {
    closeShareMenu();
    const wrap = button.closest('.filter-view-share') || button.parentElement || button;
    shareMenu = document.createElement('div');
    shareMenu.className = 'filter-view-share__menu';
    shareMenu.setAttribute('role', 'menu');
    const items = [
      { label: 'واتساب', icon: 'chat', href: `https://wa.me/?text=${encodeURIComponent(`${title}\n${url}`)}`, tone: 'green' },
      { label: 'تيليغرام', icon: 'send', href: `https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(title)}`, tone: 'blue' },
      { label: 'نسخ الرابط', icon: 'link', copy: true, tone: 'ink' },
    ];
    items.forEach((item) => {
      const node = document.createElement(item.copy ? 'button' : 'a');
      node.className = `filter-view-share__item filter-view-share__item--${item.tone}`;
      node.setAttribute('role', 'menuitem');
      if (item.copy) {
        node.type = 'button';
        node.addEventListener('click', () => {
          copyUrl(button, url);
          closeShareMenu();
        });
      } else {
        node.href = item.href;
        node.target = '_blank';
        node.rel = 'noopener';
        node.addEventListener('click', closeShareMenu);
      }
      const icon = document.createElement('span');
      icon.className = 'material-symbols-outlined';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = item.icon;
      node.append(icon, item.label);
      shareMenu.appendChild(node);
    });
    wrap.appendChild(shareMenu);
    button.setAttribute('aria-expanded', 'true');
    shareMenuFor = button;
    shareMenu.querySelector('.filter-view-share__item')?.focus();
  }

  async function shareCurrentView(button) {
    if (!(button instanceof HTMLElement)) return;
    if (button.dataset.sharing === '1') return;
    button.dataset.sharing = '1';
    button.classList.add('is-loading');
    button.setAttribute('aria-busy', 'true');
    try {
      const url = await resolveShareUrl();
      const title = shareTitle();
      if (typeof navigator.share === 'function') {
        try {
          await navigator.share({ title, url });
          return;
        } catch (error) {
          if (error && error.name === 'AbortError') return;
        }
      }
      openShareMenu(button, title, url);
    } catch {
      flashNear(button, 'تعذر إنشاء الرابط');
    } finally {
      button.dataset.sharing = '0';
      button.classList.remove('is-loading');
      button.removeAttribute('aria-busy');
    }
  }

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-share-filter-view]');
    if (!button) {
      if (shareMenu && !(target && shareMenu.contains(target))) closeShareMenu();
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    if (shareMenuFor === button) {
      closeShareMenu();
      return;
    }
    shareCurrentView(button);
  }, true);

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && shareMenu) {
      const owner = shareMenuFor;
      closeShareMenu();
      owner?.focus();
    }
  });

  global.PortalFilterView = {
    mint,
    shortenAddressBar,
    shortenCurrentIfNeeded,
    rememberWorkingHref,
    workingHref: getWorkingHref,
    shareCurrentView,
    resolveShareUrl,
    shortenPath,
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
