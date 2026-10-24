(function () {
  'use strict';

  const SEARCH_KEY = 'lab-recent-q';
  const VIEWED_KEY = 'lab-recent-viewed';
  const MAX_SEARCHES = 6;
  const MAX_VIEWED = 12;

  const read = (key) => {
    try {
      const value = JSON.parse(window.localStorage.getItem(key) || '[]');
      return Array.isArray(value) ? value : [];
    } catch {
      return [];
    }
  };
  const write = (key, value) => {
    try {
      window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
      /* storage unavailable */
    }
  };

  const rememberSearch = (query) => {
    const q = String(query || '').trim().replace(/\s+/g, ' ');
    if (q.length < 2 || q.length > 80) return;
    const list = read(SEARCH_KEY).filter((item) => typeof item === 'string' && item !== q);
    list.unshift(q);
    write(SEARCH_KEY, list.slice(0, MAX_SEARCHES));
  };

  const parsePreview = (card) => {
    const raw = card.getAttribute('data-preview');
    if (!raw) return null;
    try {
      const data = JSON.parse(raw);
      return data && data.guid ? data : null;
    } catch {
      return null;
    }
  };

  const currentReturnUrl = () => window.location.pathname + window.location.search;

  const detailUrlFor = (guid, returnUrl) => {
    const params = new URLSearchParams({ guid: String(guid || '') });
    if (returnUrl) params.set('return', returnUrl);
    return `/product.php?${params.toString()}`;
  };

  const rememberViewed = (card) => {
    let guid = card.getAttribute('data-preview-guid') || '';
    if (!guid) {
      const link = card.querySelector('a.store-product-card__link[href]');
      try {
        guid = link ? new URL(link.href, window.location.origin).searchParams.get('guid') || '' : '';
      } catch {
        guid = '';
      }
    }
    const preview = parsePreview(card);
    const title = (
      (preview && preview.name)
      || card.querySelector('.store-product-card__title, .home-product-card__name, .lab-viewed__name')?.textContent
      || ''
    ).trim();
    if (!guid || !title) return;
    const img = card.querySelector('img');
    const src = img ? (img.currentSrc || img.getAttribute('src') || '') : '';
    const thumb = (preview && preview.thumbUrl) || (src.startsWith('data:') ? '' : src);
    const entry = {
      guid,
      title: title.slice(0, 120),
      img: thumb,
      href: detailUrlFor(guid, currentReturnUrl()),
      preview: preview || null,
    };
    const list = read(VIEWED_KEY).filter((item) => item && item.guid !== guid);
    list.unshift(entry);
    write(VIEWED_KEY, list.slice(0, MAX_VIEWED));
  };

  const icon = (name) => {
    const span = document.createElement('span');
    span.className = 'material-symbols-outlined';
    span.setAttribute('aria-hidden', 'true');
    span.textContent = name;
    return span;
  };

  const cartAllowed = () => !!document.getElementById('storeCartBootstrap') || !!window.StoreCart;

  const mapApiProductToPreview = (product, fallback = {}) => {
    const guid = String(product?.guid || fallback.guid || '');
    const imageGuid = String(product?.imageGuid || '').trim();
    const thumbUrl = imageGuid
      ? `/api/image.php?id=${encodeURIComponent(imageGuid)}&thumb=1`
      : String(fallback.img || fallback.thumbUrl || '');
    const zoomUrl = imageGuid
      ? `/api/image.php?id=${encodeURIComponent(imageGuid)}&thumb=0`
      : (thumbUrl.includes('thumb=1') ? thumbUrl.replace('thumb=1', 'thumb=0') : thumbUrl);
    const returnUrl = currentReturnUrl();
    const priceHidden = !!product?.priceHidden;
    const unavailable = !!product?.unavailable;
    const showPrice = !priceHidden && !!product?.showPrice;
    return {
      guid,
      name: String(product?.name || fallback.title || ''),
      code: String(product?.code || ''),
      manufacturer: String(product?.manufacturer || ''),
      materialType: String(product?.materialType || ''),
      thumbUrl,
      zoomUrl,
      detailUrl: detailUrlFor(guid, returnUrl),
      showPrice,
      showPriceSyp: showPrice && !!product?.showPriceSyp,
      showPriceUsd: showPrice && !!product?.showPriceUsd,
      showQuantity: !unavailable && !!product?.showQuantity,
      packagesAvailable: Number(product?.packagesAvailable) || 0,
      packagesAvailableLabel: '',
      packaging: Number(product?.packaging) || 0,
      packagingLabel: '',
      primaryUnit: String(product?.primaryUnit || 'قطعة'),
      packageUnit: String(product?.packageUnit || 'طرد'),
      hasOffer: !!product?.hasOffer,
      offerBadge: String(product?.offerBadge || ''),
      unitSaleSp: showPrice ? (Number(product?.unitSaleSp) || 0) : 0,
      unitSaleUsd: showPrice ? (Number(product?.unitSaleUsd) || 0) : 0,
      packageSaleSp: showPrice ? (Number(product?.packageSaleSp) || 0) : 0,
      packageSaleUsd: showPrice ? (Number(product?.packageSaleUsd) || 0) : 0,
      originalUnitSp: showPrice ? (Number(product?.originalUnitSp) || 0) : 0,
      originalUnitUsd: showPrice ? (Number(product?.originalUnitUsd) || 0) : 0,
      originalPackSp: showPrice ? (Number(product?.originalPackSp) || 0) : 0,
      originalPackUsd: showPrice ? (Number(product?.originalPackUsd) || 0) : 0,
      priceHidden,
      unavailable,
      allowCart: !unavailable && !priceHidden && cartAllowed(),
      cartQty: 0,
      maxPackages: null,
      maxLabel: null,
      remaining: null,
      atLimit: false,
      defaultQty: 1,
      qtyStep: 1,
      qtyMin: 1,
      partialPackage: false,
      effectiveMax: null,
      returnUrl,
      storeOffer: '',
      storeSection: '',
    };
  };

  const fallbackPreview = (item) => {
    const thumbUrl = String(item.img || '');
    const returnUrl = currentReturnUrl();
    return {
      guid: item.guid,
      name: item.title,
      code: '',
      manufacturer: '',
      materialType: '',
      thumbUrl,
      zoomUrl: thumbUrl.includes('thumb=1') ? thumbUrl.replace('thumb=1', 'thumb=0') : thumbUrl,
      detailUrl: item.href || detailUrlFor(item.guid, returnUrl),
      showPrice: false,
      showPriceSyp: false,
      showPriceUsd: false,
      showQuantity: false,
      packagesAvailable: 0,
      packagesAvailableLabel: '',
      packaging: 0,
      packagingLabel: '',
      primaryUnit: 'قطعة',
      packageUnit: 'طرد',
      hasOffer: false,
      offerBadge: '',
      unitSaleSp: 0,
      unitSaleUsd: 0,
      packageSaleSp: 0,
      packageSaleUsd: 0,
      originalUnitSp: 0,
      originalUnitUsd: 0,
      originalPackSp: 0,
      originalPackUsd: 0,
      allowCart: cartAllowed(),
      cartQty: 0,
      maxPackages: null,
      maxLabel: null,
      remaining: null,
      atLimit: false,
      defaultQty: 1,
      qtyStep: 1,
      qtyMin: 1,
      partialPackage: false,
      effectiveMax: null,
      returnUrl,
      storeOffer: '',
      storeSection: '',
    };
  };

  const previewPayloadFor = (item) => {
    if (item.preview && typeof item.preview === 'object' && item.preview.guid) {
      const returnUrl = currentReturnUrl();
      const priceHidden = !!item.preview.priceHidden;
      const unavailable = !!item.preview.unavailable;
      const showPrice = !priceHidden && !!item.preview.showPrice;
      return {
        ...item.preview,
        name: item.preview.name || item.title,
        thumbUrl: item.preview.thumbUrl || item.img || '',
        zoomUrl: item.preview.zoomUrl
          || (item.preview.thumbUrl && String(item.preview.thumbUrl).includes('thumb=1')
            ? String(item.preview.thumbUrl).replace('thumb=1', 'thumb=0')
            : (item.img || '')),
        detailUrl: detailUrlFor(item.guid, returnUrl),
        returnUrl,
        priceHidden,
        unavailable,
        showPrice,
        showPriceSyp: showPrice && !!item.preview.showPriceSyp,
        showPriceUsd: showPrice && !!item.preview.showPriceUsd,
        allowCart: unavailable || priceHidden
          ? false
          : (item.preview.allowCart == null ? cartAllowed() : !!item.preview.allowCart),
      };
    }
    return fallbackPreview(item);
  };

  const persistViewedPreview = (guid, preview) => {
    if (!guid || !preview) return;
    const list = read(VIEWED_KEY).map((item) => {
      if (!item || item.guid !== guid) return item;
      return {
        ...item,
        title: String(preview.name || item.title || '').slice(0, 120) || item.title,
        img: preview.thumbUrl || item.img || '',
        href: preview.detailUrl || item.href,
        preview,
      };
    });
    write(VIEWED_KEY, list);
  };

  const ensureStorePreviewReady = () => {
    if (window.StoreProductPreview?.open && document.getElementById('storeProductPreview')) {
      return Promise.resolve();
    }
    const urlsEl = document.getElementById('deferStoreScriptUrls');
    if (urlsEl) {
      let scriptUrls = [];
      try {
        scriptUrls = JSON.parse(urlsEl.textContent || '[]');
      } catch {
        scriptUrls = [];
      }
      if (Array.isArray(scriptUrls) && scriptUrls.length > 0) {
        return scriptUrls.reduce(
          (chain, src) => chain.then(() => new Promise((resolve, reject) => {
            const existing = document.querySelector(`script[src="${src}"]`);
            if (existing) {
              resolve();
              return;
            }
            const script = document.createElement('script');
            script.src = src;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error(`Failed to load ${src}`));
            document.body.appendChild(script);
          })),
          Promise.resolve(),
        ).then(() => {
          window.__storeScriptsLoaded = true;
        });
      }
    }
    return Promise.reject(new Error('store preview unavailable'));
  };

  const refreshCardPreview = async (card, item) => {
    const guid = card.getAttribute('data-preview-guid') || item.guid;
    if (!guid) return previewPayloadFor(item);
    try {
      const response = await fetch(`/api/store-product.php?guid=${encodeURIComponent(guid)}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      const data = await response.json();
      if (!response.ok || !data?.ok || !data.product) {
        throw new Error(data?.message || 'load failed');
      }
      const payload = mapApiProductToPreview(data.product, item);
      card.setAttribute('data-preview', JSON.stringify(payload));
      persistViewedPreview(guid, payload);
      return payload;
    } catch {
      const payload = previewPayloadFor(item);
      card.setAttribute('data-preview', JSON.stringify(payload));
      return payload;
    }
  };

  const renderSearches = () => {
    const list = read(SEARCH_KEY).filter((item) => typeof item === 'string');
    document.querySelectorAll('[data-lab-recent-q]').forEach((box) => {
      const row = box.querySelector('[data-lab-recent-q-row]');
      if (!row) return;
      row.replaceChildren();
      if (list.length === 0) {
        box.hidden = true;
        return;
      }
      const form = box.closest('section')?.querySelector('form[data-hl-search]');
      const action = form?.getAttribute('action') || '/store.php';
      list.forEach((q) => {
        const link = document.createElement('a');
        link.className = 'lab-recent-q__chip';
        link.href = `${action}?q=${encodeURIComponent(q)}`;
        link.append(icon('history'), document.createTextNode(q));
        row.appendChild(link);
      });
      box.hidden = false;
    });
  };

  const renderViewed = () => {
    const list = read(VIEWED_KEY).filter((item) => item && item.guid && item.title);
    document.querySelectorAll('[data-lab-recent-viewed]').forEach((box) => {
      const row = box.querySelector('[data-lab-recent-viewed-row]');
      if (!row) return;
      row.replaceChildren();
      if (list.length === 0) {
        box.hidden = true;
        return;
      }
      list.forEach((item) => {
        const card = document.createElement('article');
        card.className = 'lab-viewed__card';
        card.setAttribute('data-store-preview-card', '');
        card.setAttribute('data-preview-guid', item.guid);
        card.setAttribute('data-lab-viewed-item', '');
        const payload = previewPayloadFor(item);
        card.setAttribute('data-preview', JSON.stringify(payload));

        const media = document.createElement('button');
        media.type = 'button';
        media.className = 'lab-viewed__media';
        media.setAttribute('data-store-product-preview', '');
        media.title = 'معاينة الصورة والأسعار';
        media.setAttribute('aria-label', `معاينة ${item.title}`);
        if (item.img || payload.thumbUrl) {
          const img = document.createElement('img');
          img.src = item.img || payload.thumbUrl;
          img.alt = '';
          img.loading = 'lazy';
          img.decoding = 'async';
          media.appendChild(img);
        } else {
          media.appendChild(icon('image'));
        }

        const name = document.createElement('span');
        name.className = 'lab-viewed__name';
        name.textContent = item.title;

        card.append(media, name);
        row.appendChild(card);
      });
      box.hidden = false;
    });
  };

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form instanceof HTMLFormElement && form.matches('.lab-finder__form')) {
      form.querySelectorAll('select').forEach((select) => {
        select.disabled = select.value === '';
      });
      window.setTimeout(() => form.querySelectorAll('select').forEach((select) => { select.disabled = false; }), 0);
      return;
    }
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-hl-search]')) return;
    const input = form.querySelector('[data-hl-search-input]');
    if (input instanceof HTMLInputElement) rememberSearch(input.value);
  }, true);

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const clear = target.closest('[data-lab-clear]');
    if (clear) {
      write(clear.getAttribute('data-lab-clear') === 'viewed' ? VIEWED_KEY : SEARCH_KEY, []);
      renderSearches();
      renderViewed();
      return;
    }

    const viewedTrigger = target.closest('[data-lab-recent-viewed] [data-store-product-preview]');
    if (viewedTrigger) {
      const card = viewedTrigger.closest('[data-store-preview-card][data-lab-viewed-item]');
      const scope = viewedTrigger.closest('[data-lab-recent-viewed]');
      const guid = card?.getAttribute('data-preview-guid') || '';
      if (card && guid) {
        event.preventDefault();
        event.stopPropagation();
        if (card.dataset.previewOpening === '1') return;
        card.dataset.previewOpening = '1';
        const stored = read(VIEWED_KEY).find((item) => item && item.guid === guid) || {
          guid,
          title: card.querySelector('.lab-viewed__name')?.textContent || '',
          img: card.querySelector('img')?.getAttribute('src') || '',
        };
        ensureStorePreviewReady()
          .catch(() => null)
          .then(() => refreshCardPreview(card, stored))
          .then(() => {
            const sourceImg = viewedTrigger.querySelector('img');
            if (window.StoreProductPreview?.open) {
              window.StoreProductPreview.open(guid, sourceImg, { itemScope: scope });
              rememberViewed(card);
              return;
            }
            window.location.href = stored.href || detailUrlFor(guid, currentReturnUrl());
          })
          .finally(() => {
            delete card.dataset.previewOpening;
          });
        return;
      }
    }

    const opener = target.closest('[data-store-product-preview], .store-product-card__link');
    const card = opener?.closest('[data-preview-guid], .store-product-card, .home-product-card');
    if (card && !card.hasAttribute('data-lab-viewed-item')) rememberViewed(card);

    const dockSearch = target.closest('[data-lab-dock-search]');
    const field = document.querySelector('[data-hl-search-input]');
    if (dockSearch && field instanceof HTMLInputElement) {
      event.preventDefault();
      const top = field.getBoundingClientRect().top + window.scrollY;
      const offset = (document.querySelector('.site-header')?.offsetHeight || 0) + 24;
      window.scrollTo({ top: Math.max(0, top - offset), behavior: 'smooth' });
      field.focus({ preventScroll: true });
    }
  }, true);

  const selectShopTab = (tab, focus) => {
    const shop = tab.closest('[data-lab-shop]');
    if (!shop) return;
    shop.querySelectorAll('[data-lab-shop-tab]').forEach((item) => {
      const active = item === tab;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-selected', active ? 'true' : 'false');
      item.tabIndex = active ? 0 : -1;
      const panel = document.getElementById(item.getAttribute('aria-controls') || '');
      if (panel) panel.hidden = !active;
    });
    tab.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
    if (focus) tab.focus();
  };

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const tab = target.closest('[data-lab-shop-tab]');
    if (tab) {
      selectShopTab(tab, false);
      return;
    }

    const pick = target.closest('[data-lab-shop-pick]');
    if (pick) {
      const combo = pick.closest('[data-lab-shop-combo]');
      const index = pick.getAttribute('data-lab-shop-pick');
      combo?.querySelectorAll('[data-lab-shop-pick]').forEach((item) => {
        const active = item === pick;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      combo?.querySelectorAll('[data-lab-shop-group]').forEach((group) => {
        group.hidden = group.getAttribute('data-lab-shop-group') !== index;
      });
      pick.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
      return;
    }

    const more = target.closest('[data-lab-shop-more]');
    if (more) {
      const list = more.previousElementSibling;
      const expanded = more.getAttribute('aria-expanded') !== 'true';
      list?.classList.toggle('is-expanded', expanded);
      more.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      more.textContent = more.getAttribute(expanded ? 'data-label-less' : 'data-label-more') || '';
    }
  });

  document.addEventListener('keydown', (event) => {
    const tab = event.target instanceof Element ? event.target.closest('[data-lab-shop-tab]') : null;
    if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    const tabs = Array.from(tab.parentElement?.querySelectorAll('[data-lab-shop-tab]') || []);
    const index = tabs.indexOf(tab);
    // RTL: ArrowLeft moves to the next tab.
    let next = index;
    if (event.key === 'ArrowLeft') next = (index + 1) % tabs.length;
    if (event.key === 'ArrowRight') next = (index - 1 + tabs.length) % tabs.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = tabs.length - 1;
    event.preventDefault();
    selectShopTab(tabs[next], true);
  });

  const init = () => {
    if (window.location.pathname === '/store.php') {
      rememberSearch(new URLSearchParams(window.location.search).get('q') || '');
    }
    renderSearches();
    renderViewed();
    if (window.location.hash === '#sl-search-input') {
      document.getElementById('sl-search-input')?.focus();
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
