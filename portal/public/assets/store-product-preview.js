(() => {
  const modal = document.getElementById('storeProductPreview');
  if (!modal) return;

  const imgEl = document.getElementById('storeProductPreviewImg');
  const imageStage = document.getElementById('storeProductPreviewImageStage');
  const imageLoader = document.getElementById('storeProductPreviewImageLoader');
  const titleEl = document.getElementById('storeProductPreviewTitle');
  const subtitleEl = document.getElementById('storeProductPreviewSubtitle');
  const pricesEl = document.getElementById('storeProductPreviewPrices');
  const cartEl = document.getElementById('storeProductPreviewCart');
  const counterEl = document.getElementById('storeProductPreviewCounter');
  const detailEl = document.getElementById('storeProductPreviewDetail');
  const btnPrev = modal.querySelector('[data-preview-prev]');
  const btnNext = modal.querySelector('[data-preview-next]');

  const state = { items: [], index: 0, navigating: false };
  const imageCache = new Map();
  let imageRenderToken = 0;
  let touchStartX = 0;
  let touchStartY = 0;

  const paging = () => window.__storePreviewPaging || {};

  const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const formatMoney = (amount) => {
    const n = Number(amount) || 0;
    return n.toLocaleString('en-US', { maximumFractionDigits: 0 });
  };

  const formatQty = (amount) => {
    const n = Number(amount) || 0;
    return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  };

  const formatUsd = (amount) => {
    const n = Number(amount) || 0;
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };

  const imageUrlFor = (item) => item?.zoomUrl || item?.thumbUrl || '';

  const preloadImage = (url) => {
    const src = String(url || '').trim();
    if (!src) return Promise.resolve(null);

    const cached = imageCache.get(src);
    if (cached?.status === 'ready') return Promise.resolve(cached.img);
    if (cached?.status === 'loading') return cached.promise;

    const promise = new Promise((resolve, reject) => {
      const img = new Image();
      img.decoding = 'async';
      img.onload = () => {
        imageCache.set(src, { status: 'ready', img });
        resolve(img);
      };
      img.onerror = () => {
        imageCache.set(src, { status: 'error' });
        reject(new Error('image load failed'));
      };
      img.src = src;
    });

    imageCache.set(src, { status: 'loading', promise });
    return promise;
  };

  const preloadAdjacent = (index) => {
    [-1, 1, 2, -2].forEach((offset) => {
      const item = state.items[index + offset];
      const url = imageUrlFor(item);
      if (url) preloadImage(url).catch(() => {});
    });
  };

  const setImageLoading = (loading) => {
    if (imageLoader) imageLoader.hidden = !loading;
    if (imageStage) imageStage.classList.toggle('is-image-loading', loading);
    if (imgEl) imgEl.classList.toggle('is-loading', loading);
  };

  const setPageLoading = (loading) => {
    if (imageStage) imageStage.classList.toggle('is-page-loading', loading);
    if (loading) setImageLoading(true);
  };

  const applyPreviewImage = async (url) => {
    const src = String(url || '').trim();
    const token = ++imageRenderToken;

    if (!imgEl) return;

    if (!src) {
      setImageLoading(false);
      imgEl.removeAttribute('src');
      imgEl.alt = '';
      imgEl.classList.add('is-placeholder');
      imgEl.classList.remove('is-loading');
      return;
    }

    const cached = imageCache.get(src);
    const alreadyVisible = imgEl.src === src && imgEl.complete && imgEl.naturalWidth > 0;

    if (cached?.status === 'ready' || alreadyVisible) {
      setImageLoading(false);
      imgEl.src = src;
      imgEl.classList.remove('is-placeholder', 'is-loading');
      return;
    }

    setImageLoading(true);
    imgEl.classList.remove('is-placeholder');
    imgEl.classList.add('is-loading');

    try {
      await preloadImage(src);
      if (token !== imageRenderToken) return;
      imgEl.src = src;
      imgEl.classList.remove('is-loading');
      setImageLoading(false);
    } catch (_) {
      if (token !== imageRenderToken) return;
      imgEl.removeAttribute('src');
      imgEl.classList.add('is-placeholder');
      imgEl.classList.remove('is-loading');
      setImageLoading(false);
    }
  };

  const collectItems = () => {
    const items = [];
    document.querySelectorAll('[data-store-preview-card]').forEach((card) => {
      const raw = card.getAttribute('data-preview');
      if (!raw) return;
      try {
        const data = JSON.parse(raw);
        if (data && data.guid) items.push(data);
      } catch (_) {
        /* ignore */
      }
    });
    return items;
  };

  const renderPrices = (p) => {
    if (!p.showPrice) {
      return '<p class="store-product-preview__no-price">الأسعار غير متاحة لحسابك.</p>';
    }

    const rows = [];
    const badge = p.offerBadge
      ? `<span class="offer-price-block__badge">${esc(p.offerBadge)}</span>` : '';

    if (p.showPriceSyp && (p.packageSaleSp > 0 || p.originalPackSp > 0)) {
      const oldPack = p.hasOffer && p.originalPackSp > p.packageSaleSp
        ? `<span class="offer-price-block__old"><span class="store-num" dir="ltr">${formatMoney(p.originalPackSp)}</span> ل.س</span>` : '';
      rows.push(`
        <div class="offer-price-block__row offer-price-block__row--main">
          <span class="offer-price-block__label">سعر ${esc(p.packageUnit)}</span>
          <div class="offer-price-block__values">
            ${oldPack}
            <span class="offer-price-block__amount offer-price-block__amount--syp"><span class="store-num" dir="ltr">${formatMoney(p.packageSaleSp > 0 ? p.packageSaleSp : p.originalPackSp)}</span> <small>ل.س</small></span>
          </div>
        </div>`);
    }
    if (p.showPriceSyp && (p.unitSaleSp > 0 || p.originalUnitSp > 0)) {
      const oldUnit = p.hasOffer && p.originalUnitSp > p.unitSaleSp
        ? `<span class="offer-price-block__old"><span class="store-num" dir="ltr">${formatMoney(p.originalUnitSp)}</span> ل.س</span>` : '';
      rows.push(`
        <div class="offer-price-block__row">
          <span class="offer-price-block__label">سعر ${esc(p.primaryUnit)}</span>
          <div class="offer-price-block__values">
            ${oldUnit}
            <span class="offer-price-block__amount offer-price-block__amount--unit"><span class="store-num" dir="ltr">${formatMoney(p.unitSaleSp > 0 ? p.unitSaleSp : p.originalUnitSp)}</span> <small>ل.س</small></span>
          </div>
        </div>`);
    }
    if (p.showPriceUsd && (p.packageSaleUsd > 0 || p.originalPackUsd > 0)) {
      const oldPack = p.hasOffer && p.originalPackUsd > p.packageSaleUsd
        ? `<span class="offer-price-block__old">$<span class="store-num" dir="ltr">${formatUsd(p.originalPackUsd)}</span></span>` : '';
      rows.push(`
        <div class="offer-price-block__row offer-price-block__row--main">
          <span class="offer-price-block__label">سعر ${esc(p.packageUnit)}</span>
          <div class="offer-price-block__values">
            ${oldPack}
            <span class="offer-price-block__amount offer-price-block__amount--usd">$<span class="store-num" dir="ltr">${formatUsd(p.packageSaleUsd > 0 ? p.packageSaleUsd : p.originalPackUsd)}</span></span>
          </div>
        </div>`);
    }
    if (p.showPriceUsd && (p.unitSaleUsd > 0 || p.originalUnitUsd > 0)) {
      const oldUnit = p.hasOffer && p.originalUnitUsd > p.unitSaleUsd
        ? `<span class="offer-price-block__old">$<span class="store-num" dir="ltr">${formatUsd(p.originalUnitUsd)}</span></span>` : '';
      rows.push(`
        <div class="offer-price-block__row">
          <span class="offer-price-block__label">سعر ${esc(p.primaryUnit)}</span>
          <div class="offer-price-block__values">
            ${oldUnit}
            <span class="offer-price-block__amount offer-price-block__amount--unit">$<span class="store-num" dir="ltr">${formatUsd(p.unitSaleUsd > 0 ? p.unitSaleUsd : p.originalUnitUsd)}</span></span>
          </div>
        </div>`);
    }

    if (rows.length === 0) {
      return '<p class="store-product-preview__no-price">لا تتوفر أسعار لهذه المادة.</p>';
    }

    return `<div class="offer-price-block">${badge}${rows.join('')}</div>`;
  };

  const formatPackageCount = (amount) => {
    const n = Number(amount) || 0;
    if (Math.abs(n - Math.round(n)) < 0.0001) {
      return String(Math.round(n));
    }
    return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  };

  const currentCatalogReturnUrl = () => {
    const path = window.location.pathname;
    if (path !== '/store.php' && path !== '/store') {
      return null;
    }
    const params = new URLSearchParams(window.location.search);
    params.delete('preview');
    const query = params.toString();
    return query ? `${path}?${query}` : path;
  };

  const detailUrlForItem = (item) => {
    const base = String(item.detailUrl || '').trim();
    if (!base) return '';
    try {
      const url = new URL(base, window.location.origin);
      const currentReturn = currentCatalogReturnUrl();
      if (currentReturn) {
        url.searchParams.set('return', currentReturn);
      }
      return `${url.pathname}?${url.searchParams.toString()}`;
    } catch {
      return base;
    }
  };

  const mountCartForm = (item, container) => {
    if (!container) return null;
    if (!item.allowCart) {
      container.innerHTML = '';
      return null;
    }

    const card = document.querySelector(`[data-preview-guid="${CSS.escape(item.guid)}"]`);
    const sourceForm = card?.querySelector('[data-store-add-cart]');
    if (!sourceForm) {
      container.innerHTML = renderCartFormFallback(item);
      if (window.StoreCart?.bindAddForms) {
        window.StoreCart.bindAddForms();
      }
      if (window.StoreCart?.bindQtySteppers) {
        window.StoreCart.bindQtySteppers(container);
      }
      return container.querySelector('[data-store-add-cart]');
    }

    const clone = sourceForm.cloneNode(true);
    clone.classList.add('store-add-cart--preview');
    delete clone.dataset.stepperBound;
    clone.querySelectorAll('[data-bound]').forEach((el) => {
      delete el.dataset.bound;
    });

    container.innerHTML = '';
    container.appendChild(clone);

    const inCartQty = Math.max(0, parseFloat(sourceForm.dataset.cartQty || '0') || 0);
    if (window.StoreCart?.setFormCartMode) {
      window.StoreCart.setFormCartMode(clone, inCartQty);
    }
    if (window.StoreCart?.bindQtySteppers) {
      window.StoreCart.bindQtySteppers(container);
    }

    return clone;
  };

  const renderCartFormFallback = (p) => {
    const maxAttr = p.maxPackages != null ? `data-max-qty="${esc(p.maxPackages)}" data-max-qty-label="${esc(p.maxLabel || p.maxPackages)}"` : '';
    const effectiveMax = p.effectiveMax != null ? Number(p.effectiveMax) : (p.remaining != null ? Number(p.remaining) : null);
    const remaining = effectiveMax != null ? Math.max(0, effectiveMax) : null;
    const atLimit = !!p.atLimit;
    const qtyStep = Number(p.qtyStep) > 0 ? Number(p.qtyStep) : 1;
    const qtyMin = Number(p.qtyMin) > 0 ? Number(p.qtyMin) : 1;
    const defaultQty = Number(p.defaultQty) > 0 ? Number(p.defaultQty) : qtyMin;
    const partial = !!p.partialPackage;
    const maxInput = remaining !== null && remaining > 0 ? `max="${remaining}"` : (atLimit ? `max="${qtyMin}"` : '');
    const disabled = atLimit ? 'disabled' : '';
    const plusDisabled = (atLimit || (remaining !== null && remaining <= 0)) ? 'disabled' : '';
    const effectiveMaxAttr = remaining !== null ? `data-effective-max="${remaining}"` : '';

    let hint = '';
    if (partial && p.packagesAvailable > 0) {
      hint = `<p class="store-add-cart__limit" data-qty-hint>متوفر أقل من طرد كامل: <span class="store-num" dir="ltr">${formatQty(p.packagesAvailable)}</span> ${esc(p.packageUnit)} — يمكن طلب الكمية المتبقية.</p>`;
    } else if (p.maxLabel != null) {
      if (atLimit) {
        hint = `<p class="store-add-cart__limit is-warning" data-qty-hint>وصلت للحد الأقصى (${esc(p.maxLabel)} ${esc(p.packageUnit)})</p>`;
      } else if (p.cartQty > 0) {
        hint = `<p class="store-add-cart__limit" data-qty-hint>الحد الأقصى ${esc(p.maxLabel)} ${esc(p.packageUnit)} — متبقي <span class="store-num" dir="ltr">${formatQty(remaining)}</span></p>`;
      } else {
        hint = `<p class="store-add-cart__limit" data-qty-hint>الحد الأقصى ${esc(p.maxLabel)} ${esc(p.packageUnit)} لكل مادة</p>`;
      }
    }

    const imageField = p.thumbUrl
      ? `<input type="hidden" name="image_url" value="${esc(p.thumbUrl)}">` : '';

    return `
      <form
        method="post"
        class="store-add-cart store-add-cart--preview"
        action="${esc(p.returnUrl || '/store.php')}"
        data-store-add-cart="1"
        data-partial-package="${partial ? '1' : '0'}"
        data-material-guid="${esc(p.guid)}"
        data-cart-qty="${esc(p.cartQty)}"
        data-qty-step="${qtyStep}"
        ${maxAttr}
        ${effectiveMaxAttr}
      >
        <input type="hidden" name="material_guid" value="${esc(p.guid)}">
        <input type="hidden" name="material_code" value="${esc(p.code)}">
        <input type="hidden" name="material_name_ar" value="${esc(p.name)}">
        <input type="hidden" name="primary_unit" value="${esc(p.primaryUnit)}">
        <input type="hidden" name="package_unit" value="${esc(p.packageUnit)}">
        <input type="hidden" name="packaging" value="${esc(p.packaging)}">
        <input type="hidden" name="unit_sale_price_sp" value="${esc(p.unitSaleSp)}">
        <input type="hidden" name="unit_sale_price_usd" value="${esc(p.unitSaleUsd)}">
        ${imageField}
        ${hint}
        <div class="store-add-cart__row">
          <span class="text-xs font-bold text-gray-600 shrink-0">${esc(p.packageUnit)}</span>
          <div class="store-qty-stepper">
            <button type="button" data-qty-minus aria-label="إنقاص">−</button>
            <input type="number" class="store-num" dir="ltr" name="quantity" min="${qtyMin}" ${maxInput} step="${qtyStep}" value="${defaultQty}" ${disabled}>
            <button type="button" data-qty-plus aria-label="زيادة" ${plusDisabled}>+</button>
          </div>
        </div>
        <button type="submit" class="store-add-cart__submit" ${disabled}>
          <span class="material-symbols-outlined text-[20px]" aria-hidden="true">add_shopping_cart</span>
          ${atLimit ? 'الحد الأقصى مكتمل' : (partial ? 'طلب الكمية المتاحة' : 'إضافة للسلة')}
        </button>
      </form>`;
  };

  const updateInCartBanner = (item) => {
    const imageWrap = modal.querySelector('.store-product-preview__image-wrap');
    if (!imageWrap) return;

    let banner = imageWrap.querySelector('[data-preview-cart-banner]');
    const inCart = Math.max(0, Number(item.cartQty) || 0);
    if (inCart <= 0) {
      banner?.remove();
      return;
    }

    if (!banner) {
      banner = document.createElement('div');
      banner.className = 'store-product-preview__cart-banner';
      banner.setAttribute('data-preview-cart-banner', '');
      imageWrap.appendChild(banner);
    }

    const qtyLabel = formatPackageCount(inCart);
    banner.innerHTML = `<span class="material-symbols-outlined text-base" aria-hidden="true">shopping_cart_checkout</span><span>في السلة</span><span class="store-num" dir="ltr">${esc(qtyLabel)}</span><span>${esc(item.packageUnit || 'طرد')}</span>`;
  };

  const syncCartQtyFromDom = (p) => {
    const card = document.querySelector(`[data-preview-guid="${CSS.escape(p.guid)}"]`);
    const form = card?.querySelector('[data-store-add-cart]');
    if (!form) return p;
    const inCart = Math.max(0, parseFloat(form.dataset.cartQty || '0') || 0);
    const next = { ...p, cartQty: inCart };
    if (next.effectiveMax != null) {
      const policyRemaining = next.maxPackages != null
        ? Math.max(0, Number(next.maxPackages) - inCart)
        : null;
      const stockRemaining = next.packagesAvailable != null
        ? Math.max(0, Number(next.packagesAvailable))
        : null;
      let effective = policyRemaining;
      if (stockRemaining !== null) {
        effective = effective !== null ? Math.min(effective, stockRemaining) : stockRemaining;
      }
      next.effectiveMax = effective;
      next.remaining = effective;
      next.atLimit = effective !== null && effective <= 0;
    }
    return next;
  };

  const updateNav = () => {
    const total = state.items.length;
    const pageInfo = paging();
    const hasPrevPage = !!pageInfo.prevPageUrl;
    const hasNextPage = !!pageInfo.nextPageUrl;
    const atFirst = state.index <= 0;
    const atLast = state.index >= total - 1;

    if (counterEl) {
      const pageLabel = pageInfo.totalPages > 1
        ? ` — صفحة <span class="store-num" dir="ltr">${pageInfo.page}</span>/<span class="store-num" dir="ltr">${pageInfo.totalPages}</span>`
        : '';
      counterEl.innerHTML = total > 0
        ? `<span class="store-num" dir="ltr">${state.index + 1}</span> / <span class="store-num" dir="ltr">${total}</span>${pageLabel}`
        : '';
    }
    if (btnPrev) btnPrev.disabled = state.navigating || (atFirst && !hasPrevPage);
    if (btnNext) btnNext.disabled = state.navigating || (atLast && !hasNextPage);
  };

  const render = (p) => {
    const item = syncCartQtyFromDom(p);
    const panel = modal.querySelector('.store-product-preview__panel');
    if (panel) panel.classList.toggle('store-product-preview__panel--offer', !!item.hasOffer);

    if (imgEl) {
      imgEl.alt = item.name || '';
    }
    applyPreviewImage(imageUrlFor(item));
    preloadAdjacent(state.index);

    const imageWrap = modal.querySelector('.store-product-preview__image-wrap');
    if (imageWrap) {
      let banner = imageWrap.querySelector('.store-product-preview__offer-banner');
      if (item.hasOffer) {
        if (!banner) {
          banner = document.createElement('div');
          banner.className = 'store-product-preview__offer-banner';
          imageWrap.insertBefore(banner, imageWrap.firstChild);
        }
        banner.innerHTML = `<span class="material-symbols-outlined text-base" aria-hidden="true">local_offer</span>${esc(item.offerBadge || 'عرض خاص')}`;
      } else if (banner) {
        banner.remove();
      }
    }

    if (titleEl) titleEl.textContent = item.name || '—';

    if (subtitleEl) {
      const parts = [
        item.manufacturer,
        item.code ? `#${item.code}` : '',
        item.materialType,
        item.showQuantity && item.packagesAvailable > 0
          ? `متوفر ${item.packagesAvailableLabel || formatQty(item.packagesAvailable)} ${item.packageUnit}`
          : '',
      ].filter(Boolean);
      subtitleEl.textContent = parts.join(' · ');
      subtitleEl.hidden = parts.length === 0;
    }

    if (pricesEl) pricesEl.innerHTML = renderPrices(item);
    mountCartForm(item, cartEl);
    updateInCartBanner(item);
    if (detailEl) {
      const detailHref = detailUrlForItem(item);
      detailEl.href = detailHref || '/store.php';
      detailEl.classList.toggle('hidden', !detailHref);
    }

    updateNav();
  };

  const showAt = (index) => {
    if (state.items.length === 0) return;
    state.index = Math.max(0, Math.min(index, state.items.length - 1));
    render(state.items[state.index]);
  };

  const cleanPreviewUrl = (url) => {
    try {
      const parsed = new URL(url, window.location.origin);
      parsed.searchParams.delete('preview');
      return parsed.toString();
    } catch {
      return url;
    }
  };

  const goToPreviewPage = async (pageUrl, edge) => {
    const targetUrl = cleanPreviewUrl(pageUrl);
    const wasOpen = !modal.hidden;

    if (typeof window.portalStoreCatalogNavigate === 'function') {
      state.navigating = true;
      setPageLoading(true);
      updateNav();
      try {
        await window.portalStoreCatalogNavigate(targetUrl);
        state.items = collectItems();
        if (state.items.length === 0) {
          close();
          return;
        }
        state.index = edge === 'last' ? state.items.length - 1 : 0;
        if (wasOpen) openModal();
        setPageLoading(false);
        showAt(state.index);
      } catch (_) {
        setPageLoading(false);
        window.location.href = pageUrl;
      } finally {
        state.navigating = false;
        updateNav();
      }
      return;
    }

    window.location.href = pageUrl;
  };

  const navigate = async (delta) => {
    if (state.navigating || state.items.length === 0) return;

    const newIndex = state.index + delta;
    const pageInfo = paging();

    if (newIndex >= 0 && newIndex < state.items.length) {
      showAt(newIndex);
      return;
    }
    if (delta > 0 && newIndex >= state.items.length && pageInfo.nextPageUrl) {
      await goToPreviewPage(pageInfo.nextPageUrl, 'first');
      return;
    }
    if (delta < 0 && newIndex < 0 && pageInfo.prevPageUrl) {
      await goToPreviewPage(pageInfo.prevPageUrl, 'last');
    }
  };

  const openModal = () => {
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  };

  const open = (guid) => {
    state.items = collectItems();
    if (state.items.length === 0) return;
    const idx = state.items.findIndex((item) => item.guid === guid);
    state.index = idx >= 0 ? idx : 0;
    openModal();
    showAt(state.index);
  };

  const close = () => {
    if (document.activeElement instanceof HTMLElement && modal.contains(document.activeElement)) {
      document.activeElement.blur();
    }
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    imageRenderToken += 1;
    setImageLoading(false);
    setPageLoading(false);
    if (imgEl) {
      imgEl.removeAttribute('src');
      imgEl.classList.remove('is-loading', 'is-placeholder');
    }
    document.body.style.overflow = '';
  };

  const initFromUrl = () => {
    const params = new URLSearchParams(window.location.search);
    const edge = params.get('preview');
    if (edge !== 'first' && edge !== 'last') return;

    state.items = collectItems();
    if (state.items.length === 0) return;

    state.index = edge === 'last' ? state.items.length - 1 : 0;
    openModal();
    showAt(state.index);

    params.delete('preview');
    const query = params.toString();
    const nextUrl = query ? `${window.location.pathname}?${query}` : window.location.pathname;
    window.history.replaceState({}, '', nextUrl);
  };

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-store-product-preview]');
    if (!trigger) return;
    event.preventDefault();
    event.stopPropagation();
    const card = trigger.closest('[data-store-preview-card]');
    const guid = card?.getAttribute('data-preview-guid') || '';
    if (!guid) return;
    open(guid);
  });

  modal.querySelectorAll('[data-preview-close]').forEach((el) => {
    el.addEventListener('click', close);
  });

  btnPrev?.addEventListener('click', () => { navigate(-1); });
  btnNext?.addEventListener('click', () => { navigate(1); });

  document.addEventListener('keydown', (event) => {
    if (modal.hidden) return;
    if (event.key === 'Escape') close();
    if (event.key === 'ArrowRight') navigate(-1);
    if (event.key === 'ArrowLeft') navigate(1);
  });

  const panel = modal.querySelector('.store-product-preview__panel');
  panel?.addEventListener('touchstart', (event) => {
    if (event.touches.length !== 1) return;
    touchStartX = event.touches[0].clientX;
    touchStartY = event.touches[0].clientY;
  }, { passive: true });

  panel?.addEventListener('touchend', (event) => {
    if (event.changedTouches.length !== 1) return;
    const dx = event.changedTouches[0].clientX - touchStartX;
    const dy = event.changedTouches[0].clientY - touchStartY;
    if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy)) return;
    if (dx > 0) navigate(-1);
    else navigate(1);
  }, { passive: true });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFromUrl);
  } else {
    initFromUrl();
  }

  document.addEventListener('store-cart-updated', () => {
    if (modal.hidden || state.items.length === 0) return;
    const current = state.items[state.index];
    if (!current?.guid) return;
    render(syncCartQtyFromDom(current));
  });
})();
