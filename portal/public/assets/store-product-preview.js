(() => {
  const modal = document.getElementById('storeProductPreview');
  if (!modal) return;

  const imgEl = document.getElementById('storeProductPreviewImg');
  const imageStage = document.getElementById('storeProductPreviewImageStage');
  const imageLoader = document.getElementById('storeProductPreviewImageLoader');
  const titleEl = document.getElementById('storeProductPreviewTitle');
  const subtitleEl = document.getElementById('storeProductPreviewSubtitle');
  const packagingEl = document.getElementById('storeProductPreviewPackaging');
  const pricesEl = document.getElementById('storeProductPreviewPrices');
  const cartEl = document.getElementById('storeProductPreviewCart');
  const counterEl = document.getElementById('storeProductPreviewCounter');
  const detailEl = document.getElementById('storeProductPreviewDetail');
  const btnPrev = modal.querySelector('[data-preview-prev]');
  const btnNext = modal.querySelector('[data-preview-next]');

  const state = {
    items: [],
    index: 0,
    navigating: false,
    context: 'catalog',
    cartRoot: null,
    orderRoot: null,
    itemScope: null,
    currentGuid: '',
  };
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

  const thumbUrlFor = (item) => {
    if (!item || typeof item !== 'object') return '';
    const thumb = String(item.thumbUrl || '').trim();
    if (thumb) return thumb;
    const zoom = String(item.zoomUrl || '').trim();
    if (zoom.includes('thumb=0')) return zoom.replace('thumb=0', 'thumb=1');
    if (zoom && !zoom.includes('thumb=')) {
      return zoom.includes('?') ? `${zoom}&thumb=1` : `${zoom}?thumb=1`;
    }
    return '';
  };

  const thumbFromZoomUrl = (url) => {
    const text = String(url || '').trim();
    if (!text) return '';
    if (text.includes('thumb=0')) return text.replace('thumb=0', 'thumb=1');
    if (!text.includes('thumb=')) {
      return text.includes('?') ? `${text}&thumb=1` : `${text}?thumb=1`;
    }
    return text;
  };

  const resolveItemScope = (element) => {
    if (!(element instanceof Element)) return null;
    return element.closest('.home-section');
  };

  const findPreviewCard = (guid, scope = null) => {
    if (!guid) return null;
    const root = scope || state.itemScope || document;
    return root.querySelector(`[data-preview-guid="${CSS.escape(guid)}"]`);
  };

  const isScopedCatalog = () => state.context === 'catalog' && state.itemScope instanceof Element;

  const findCardImageForItem = (item) => {
    if (!item?.guid) return null;
    const card = findPreviewCard(item.guid);
    return card?.querySelector('.material-image-frame__photo img')
      || card?.querySelector('.dash-order-item__thumb img')
      || card?.querySelector('.store-order-line-card__thumb img')
      || null;
  };

  const preloadImage = (url) => {
    const src = String(url || '').trim();
    if (!src) return Promise.resolve(null);
    if (window.StoreImageZoom?.preload) {
      return window.StoreImageZoom.preload(src).catch(() => null);
    }

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

  const prepareImageTransition = () => {
    imageRenderToken += 1;
    setImageLoading(true);
    if (!imgEl) return;
    imgEl.removeAttribute('src');
    imgEl.alt = '';
    imgEl.classList.remove('is-preview-thumb', 'is-placeholder');
    imgEl.classList.add('is-loading');
    delete imgEl.dataset.pendingFull;
  };

  const applyPreviewImage = async (itemOrUrl, options = {}) => {
    const isItem = itemOrUrl && typeof itemOrUrl === 'object';
    const src = isItem ? imageUrlFor(itemOrUrl) : String(itemOrUrl || '').trim();
    const thumbSrc = isItem ? thumbUrlFor(itemOrUrl) : thumbFromZoomUrl(src);
    const preferElement = options.preferElement || (isItem ? findCardImageForItem(itemOrUrl) : null);
    const token = ++imageRenderToken;

    if (!imgEl) return;

    if (!src) {
      setImageLoading(false);
      imgEl.removeAttribute('src');
      imgEl.alt = '';
      imgEl.classList.add('is-placeholder');
      imgEl.classList.remove('is-loading', 'is-preview-thumb');
      delete imgEl.dataset.pendingFull;
      return;
    }

    const zoom = window.StoreImageZoom;
    const hasThumb = !!(thumbSrc && thumbSrc !== src);
    const thumbReady = hasThumb && (
      zoom?.isImageLoaded?.(thumbSrc)
      || zoom?.isElementReady?.(preferElement)
    );
    const previewAlreadyFull = imgEl.complete && imgEl.naturalWidth > 0
      && (!zoom?.normalizeImageUrl || zoom.normalizeImageUrl(imgEl.currentSrc || imgEl.src) === zoom.normalizeImageUrl(src));
    const fullReady = zoom?.isImageLoaded?.(src) || previewAlreadyFull;

    if (fullReady) {
      setImageLoading(false);
      if (zoom?.applySrc) {
        zoom.applySrc(imgEl, src, { preferElement });
      } else {
        imgEl.src = src;
      }
      imgEl.classList.remove('is-placeholder', 'is-loading', 'is-preview-thumb');
      delete imgEl.dataset.pendingFull;
      return;
    }

    imgEl.classList.remove('is-placeholder');
    delete imgEl.dataset.pendingFull;

    if (zoom?.loadProgressive) {
      if (hasThumb && thumbReady) {
        zoom.applySrc(imgEl, thumbSrc, { preferElement });
        imgEl.classList.add('is-preview-thumb');
        setImageLoading(true);
      } else if (hasThumb) {
        imgEl.classList.add('is-preview-thumb');
        setImageLoading(true);
      } else {
        imgEl.classList.remove('is-preview-thumb');
        setImageLoading(true);
      }

      try {
        await zoom.loadProgressive(imgEl, src, thumbSrc, { preferElement });
        if (token !== imageRenderToken) return;
        imgEl.classList.remove('is-loading', 'is-preview-thumb');
        setImageLoading(false);
      } catch (_) {
        if (token !== imageRenderToken) return;
        if (!thumbReady) {
          imgEl.removeAttribute('src');
          imgEl.classList.add('is-placeholder');
        }
        imgEl.classList.remove('is-loading', 'is-preview-thumb');
        setImageLoading(false);
      }
      return;
    }

    const cached = imageCache.get(src);
    const alreadyVisible = imgEl.src === src && imgEl.complete && imgEl.naturalWidth > 0;

    if (cached?.status === 'ready' || alreadyVisible) {
      setImageLoading(false);
      imgEl.src = src;
      imgEl.classList.remove('is-placeholder', 'is-loading', 'is-preview-thumb');
      return;
    }

    if (hasThumb && thumbReady) {
      if (zoom?.applySrc) {
        zoom.applySrc(imgEl, thumbSrc, { preferElement });
      } else {
        imgEl.src = thumbSrc;
      }
      imgEl.classList.add('is-preview-thumb');
      imgEl.classList.remove('is-loading');
    } else if (hasThumb) {
      imgEl.src = thumbSrc;
      imgEl.classList.add('is-preview-thumb');
      imgEl.classList.remove('is-loading');
    } else {
      imgEl.classList.remove('is-preview-thumb');
      imgEl.classList.add('is-loading');
    }

    setImageLoading(true);

    try {
      await preloadImage(src);
      if (token !== imageRenderToken) return;
      imgEl.src = src;
      imgEl.classList.remove('is-loading', 'is-preview-thumb');
      setImageLoading(false);
    } catch (_) {
      if (token !== imageRenderToken) return;
      if (!thumbReady) {
        imgEl.removeAttribute('src');
        imgEl.classList.add('is-placeholder');
      }
      imgEl.classList.remove('is-loading', 'is-preview-thumb');
      setImageLoading(false);
    }
  };

  const collectItems = (options = {}) => {
    const cartRoot = options.cartRoot || null;
    const orderRoot = options.orderRoot || null;
    const itemScope = options.itemScope || null;
    const items = [];
    const scope = orderRoot || cartRoot || itemScope || document;
    let selector = '[data-store-preview-card]:not([data-store-cart-preview-line]):not([data-store-order-preview-line])';
    if (orderRoot) {
      selector = '[data-store-order-preview-line]';
    } else if (cartRoot) {
      selector = '[data-store-cart-preview-line]';
    }
    scope.querySelectorAll(selector).forEach((card) => {
      const raw = card.getAttribute('data-preview');
      if (raw) {
        try {
          const data = JSON.parse(raw);
          if (data && data.guid) {
            items.push(data);
            return;
          }
        } catch (_) {
          /* fall through */
        }
      }
      const guid = card.getAttribute('data-preview-guid') || '';
      if (!guid) return;
      const name = card.querySelector('.store-order-line-card__title')?.textContent?.trim() || '';
      const thumbImg = card.querySelector('.store-order-line-card__thumb img, .material-image-frame__photo img');
      const thumbUrl = thumbImg?.getAttribute('src') || '';
      const qtyInput = card.querySelector('[data-qty-input]');
      const cartQty = Math.max(0, parseFloat(qtyInput?.value || '0') || 0);
      items.push({
        guid,
        name,
        thumbUrl,
        zoomUrl: thumbUrl.includes('thumb=1') ? thumbUrl.replace('thumb=1', 'thumb=0') : thumbUrl,
        cartQty,
        previewContext: card.hasAttribute('data-store-cart-preview-line') ? 'cart' : 'catalog',
        showPrice: false,
        allowCart: true,
      });
    });
    return items;
  };

  const formatPackagingLabel = (item) => {
    const label = String(item?.packagingLabel || '').trim();
    if (label) return label;
    const packaging = Number(item?.packaging) || 0;
    if (packaging <= 0) return '';
    const primaryUnit = String(item?.primaryUnit || 'قطعة').trim() || 'قطعة';
    const packageUnit = String(item?.packageUnit || 'طرد').trim() || 'طرد';
    const qty = formatQty(packaging).replace(/\.00$/, '');
    return `${qty} ${primaryUnit} / ${packageUnit}`;
  };

  const renderPackaging = (item) => {
    if (!packagingEl) return;
    const label = formatPackagingLabel(item);
    if (!label) {
      packagingEl.innerHTML = '';
      packagingEl.hidden = true;
      return;
    }
    packagingEl.innerHTML = `<span class="store-product-preview__packaging-label">التعبئة</span><span class="store-product-preview__packaging-value" dir="ltr">${esc(label)}</span>`;
    packagingEl.hidden = false;
  };

  const renderOrderStaffPanel = (item) => {
    const packageUnit = item.packageUnit || 'طرد';
    const primaryUnit = item.primaryUnit || 'زوج';
    const qty = formatPackageCount(item.orderQty ?? 0);
    const qtyNum = Number(item.orderQty ?? 0);

    let packPriceText = '';
    let unitPriceText = '';
    let totalText = '';

    if (item.showPrice) {
      if (item.showPriceSyp && (item.packageSaleSp > 0 || item.originalPackSp > 0)) {
        const amount = item.packageSaleSp > 0 ? item.packageSaleSp : item.originalPackSp;
        packPriceText = `${formatMoney(amount)} ل.س`;
      } else if (item.showPriceUsd && (item.packageSaleUsd > 0 || item.originalPackUsd > 0)) {
        const amount = item.packageSaleUsd > 0 ? item.packageSaleUsd : item.originalPackUsd;
        packPriceText = `$${formatUsd(amount)}`;
      }
      if (item.showPriceSyp && (item.unitSaleSp > 0 || item.originalUnitSp > 0)) {
        const amount = item.unitSaleSp > 0 ? item.unitSaleSp : item.originalUnitSp;
        unitPriceText = `${formatMoney(amount)} ل.س / ${primaryUnit}`;
      } else if (item.showPriceUsd && (item.unitSaleUsd > 0 || item.originalUnitUsd > 0)) {
        const amount = item.unitSaleUsd > 0 ? item.unitSaleUsd : item.originalUnitUsd;
        unitPriceText = `$${formatUsd(amount)} / ${primaryUnit}`;
      }
      if (qtyNum > 1.009) {
        if (item.showPriceSyp && Number(item.lineTotalSp) > 0) {
          totalText = `${formatMoney(item.lineTotalSp)} ل.س`;
        } else if (item.showPriceUsd && Number(item.lineTotalUsd) > 0) {
          totalText = `$${formatUsd(item.lineTotalUsd)}`;
        }
      }
    }

    const badges = [];
    if (item.hasOffer) {
      badges.push(`<span class="store-product-preview__staff-tag store-product-preview__staff-tag--offer">${esc(item.offerBadge || 'عرض')}</span>`);
    }
    if (item.isCancelled) {
      badges.push('<span class="store-product-preview__staff-tag store-product-preview__staff-tag--cancelled">ملغى</span>');
    }

    if (!item.showPrice && !item.isCancelled) {
      return `<div class="store-product-preview__staff-order">
        ${badges.length > 0 ? `<div class="store-product-preview__staff-badges">${badges.join('')}</div>` : ''}
        <div class="store-product-preview__staff-pricing">
          <span class="store-product-preview__staff-qty store-num" dir="ltr">${esc(qty)} ${esc(packageUnit)}</span>
        </div>
        <p class="store-product-preview__staff-note">سعر هذا الصنف يُحدد عند تأكيد الطلب.</p>
      </div>`;
    }

    if (!packPriceText && !unitPriceText && !totalText) {
      return badges.length > 0
        ? `<div class="store-product-preview__staff-badges">${badges.join('')}</div>`
        : '';
    }

    return `
      <div class="store-product-preview__staff-order">
        ${badges.length > 0 ? `<div class="store-product-preview__staff-badges">${badges.join('')}</div>` : ''}
        <div class="store-product-preview__staff-pricing">
          <span class="store-product-preview__staff-qty store-num" dir="ltr">${esc(qty)} ${esc(packageUnit)}</span>
          ${packPriceText ? `<strong class="store-product-preview__staff-pack store-num" dir="ltr">${packPriceText}</strong>` : ''}
          ${unitPriceText ? `<span class="store-product-preview__staff-unit store-num" dir="ltr">${unitPriceText}</span>` : ''}
          ${totalText ? `<span class="store-product-preview__staff-line-total store-num" dir="ltr">${totalText}</span>` : ''}
        </div>
      </div>`;
  };

  const findOrderLineEditSource = (item) => {
    if (!state.orderRoot || !item?.guid) return null;
    const card = state.orderRoot.querySelector(`[data-store-order-preview-line][data-preview-guid="${CSS.escape(item.guid)}"]`);
    return card?.closest('.dashboard-order-line')?.querySelector('.dashboard-order-line__edit-body') || null;
  };

  const bindPreviewOrderForms = (root) => {
    if (!root) return;
    if (typeof window.dashboardApp?.bindForms === 'function') {
      window.dashboardApp.bindForms(root);
    }
  };

  const mountOrderStaffEdit = (item, container) => {
    if (!container) return;
    container.querySelector('.store-product-preview__staff-edit')?.remove();
    if (!item.editable || item.isCancelled) return;

    const source = findOrderLineEditSource(item);
    if (!source) return;

    const wrap = document.createElement('details');
    wrap.className = 'store-product-preview__staff-edit';

    const summary = document.createElement('summary');
    summary.className = 'store-product-preview__staff-edit-toggle';
    summary.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">edit</span> تعديل الصنف';
    wrap.appendChild(summary);

    const body = document.createElement('div');
    body.className = 'store-product-preview__staff-edit-body dashboard-order-line__edit-body';
    body.appendChild(source.cloneNode(true));
    body.querySelectorAll('form').forEach((form) => {
      form.removeAttribute('data-dashboard-bound');
      delete form.dataset.dashboardBound;
    });
    wrap.appendChild(body);
    container.appendChild(wrap);
    bindPreviewOrderForms(wrap);
  };

  const renderPrices = (p) => {
    if (p.previewContext === 'order') {
      return '';
    }
    if (!p.showPrice) {
      if (document.body?.dataset?.priceLockAuth === 'pending') {
        return `<div class="store-price-hidden store-price-hidden--preview" role="note">
        <span class="store-price-hidden__label" aria-hidden="true"><span class="material-symbols-outlined">lock</span><span>سعر مخفي</span></span>
        <span class="store-price-hidden__note">حسابك بانتظار التفعيل — ستظهر الأسعار بعد موافقة الإدارة.</span>
      </div>`;
      }
      const redirect = encodeURIComponent(window.location.pathname + window.location.search);
      return `<div class="store-price-hidden store-price-hidden--preview" role="note">
        <span class="store-price-hidden__label" aria-hidden="true"><span class="material-symbols-outlined">lock</span><span>سعر مخفي</span></span>
        <a href="/customer-login.php?redirect=${redirect}" class="store-price-hidden__link">سجّل الدخول لعرض السعر</a>
      </div>`;
    }

    const badge = p.offerBadge
      ? `<span class="offer-price-block__badge">${esc(p.offerBadge)}</span>` : '';
    const sypRows = [];
    const usdRows = [];

    if (p.unitSaleSp > 0 || p.originalUnitSp > 0) {
      const oldUnit = p.hasOffer && p.originalUnitSp > p.unitSaleSp
        ? `<span class="offer-price-block__old"><span class="store-num" dir="ltr">${formatMoney(p.originalUnitSp)}</span> ل.س</span>` : '';
      sypRows.push(`
        <div class="offer-price-block__row offer-price-block__row--main">
          <span class="offer-price-block__label">سعر ${esc(p.primaryUnit)}</span>
          <div class="offer-price-block__values">
            ${oldUnit}
            <span class="offer-price-block__amount offer-price-block__amount--syp"><span class="store-num" dir="ltr">${formatMoney(p.unitSaleSp > 0 ? p.unitSaleSp : p.originalUnitSp)}</span> <small>ل.س</small></span>
          </div>
        </div>`);
    }
    if (p.packageSaleSp > 0 || p.originalPackSp > 0) {
      const oldPack = p.hasOffer && p.originalPackSp > p.packageSaleSp
        ? `<span class="offer-price-block__old"><span class="store-num" dir="ltr">${formatMoney(p.originalPackSp)}</span> ل.س</span>` : '';
      sypRows.push(`
        <div class="offer-price-block__row">
          <span class="offer-price-block__label">سعر ${esc(p.packageUnit)}</span>
          <div class="offer-price-block__values">
            ${oldPack}
            <span class="offer-price-block__amount offer-price-block__amount--pack offer-price-block__amount--syp"><span class="store-num" dir="ltr">${formatMoney(p.packageSaleSp > 0 ? p.packageSaleSp : p.originalPackSp)}</span> <small>ل.س</small></span>
          </div>
        </div>`);
    }
    if (p.unitSaleUsd > 0 || p.originalUnitUsd > 0) {
      const oldUnit = p.hasOffer && p.originalUnitUsd > p.unitSaleUsd
        ? `<span class="offer-price-block__old">$<span class="store-num" dir="ltr">${formatUsd(p.originalUnitUsd)}</span></span>` : '';
      usdRows.push(`
        <div class="offer-price-block__row offer-price-block__row--main">
          <span class="offer-price-block__label">سعر ${esc(p.primaryUnit)}</span>
          <div class="offer-price-block__values">
            ${oldUnit}
            <span class="offer-price-block__amount offer-price-block__amount--usd">$<span class="store-num" dir="ltr">${formatUsd(p.unitSaleUsd > 0 ? p.unitSaleUsd : p.originalUnitUsd)}</span></span>
          </div>
        </div>`);
    }
    if (p.packageSaleUsd > 0 || p.originalPackUsd > 0) {
      const oldPack = p.hasOffer && p.originalPackUsd > p.packageSaleUsd
        ? `<span class="offer-price-block__old">$<span class="store-num" dir="ltr">${formatUsd(p.originalPackUsd)}</span></span>` : '';
      usdRows.push(`
        <div class="offer-price-block__row">
          <span class="offer-price-block__label">سعر ${esc(p.packageUnit)}</span>
          <div class="offer-price-block__values">
            ${oldPack}
            <span class="offer-price-block__amount offer-price-block__amount--pack offer-price-block__amount--usd">$<span class="store-num" dir="ltr">${formatUsd(p.packageSaleUsd > 0 ? p.packageSaleUsd : p.originalPackUsd)}</span></span>
          </div>
        </div>`);
    }

    if (sypRows.length === 0 && usdRows.length === 0) {
      return '<p class="store-product-preview__no-price">لا تتوفر أسعار لهذه المادة.</p>';
    }

    const blocks = [];
    if (sypRows.length > 0) {
      blocks.push(`<div class="store-price-currency store-price-currency--syp">${sypRows.join('')}</div>`);
    }
    if (usdRows.length > 0) {
      blocks.push(`<div class="store-price-currency store-price-currency--usd">${usdRows.join('')}</div>`);
    }

    return `<div class="offer-price-block">${badge}${blocks.join('')}</div>`;
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
    if (path !== '/store.php' && path !== '/store' && path !== '/share.php' && path !== '/share') {
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

    const card = findPreviewCard(item.guid);
    const sourceForm = card?.querySelector('[data-store-add-cart]');
    if (!sourceForm) {
      container.innerHTML = renderCartFormFallback(item);
      const form = container.querySelector('[data-store-add-cart]');
      if (form && window.StoreCart?.setFormCartMode) {
        window.StoreCart.setFormCartMode(form, Math.max(0, Number(item.cartQty) || 0));
      }
      if (window.StoreCart?.bindAddForms) {
        window.StoreCart.bindAddForms();
      }
      if (window.StoreCart?.bindQtySteppers) {
        window.StoreCart.bindQtySteppers(container);
      }
      return form;
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

  const mountCartLineControls = (item, container) => {
    if (!container || !item?.guid) {
      if (container) container.innerHTML = '';
      return;
    }

    const qty = Math.max(0, Number(item.cartQty) || 0);
    const packageUnit = item.packageUnit || 'طرد';
    const maxAttr = item.maxPackages != null ? `max="${esc(item.maxPackages)}"` : '';
    const qtyLabel = formatPackageCount(qty);

    container.innerHTML = `
      <div class="store-product-preview__cart-controls">
        <div class="store-cart-panel store-cart-panel--in-cart">
          <div class="store-cart-panel__badge">
            <span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
            <span>في السلة</span>
          </div>
          <div class="store-cart-line-card__qty-row store-product-preview__qty-row">
            <div class="store-qty-stepper store-qty-stepper--compact store-qty-stepper--preview" data-cart-qty-control data-guid="${esc(item.guid)}">
              <button type="button" data-bump="-1" aria-label="إنقاص">−</button>
              <input
                type="number"
                class="store-num"
                dir="ltr"
                min="0.01"
                step="0.01"
                ${maxAttr}
                value="${esc(formatQty(qty))}"
                data-qty-input
              >
              <button type="button" data-bump="1" aria-label="زيادة">+</button>
            </div>
            <span class="store-cart-line-card__unit">${esc(packageUnit)}</span>
            <strong class="store-product-preview__qty-label store-num" dir="ltr">${esc(qtyLabel)}</strong>
          </div>
        </div>
        <button type="button" class="store-product-preview__remove-btn" data-remove-item="${esc(item.guid)}">
          <span class="material-symbols-outlined" aria-hidden="true">delete</span>
          إزالة من السلة
        </button>
      </div>`;

    if (window.StoreCart?.bindCartLineControls) {
      window.StoreCart.bindCartLineControls(container, item.maxPackages ?? null);
    }
  };

  const renderCartFormFallback = (p) => {
    const maxAttr = p.maxPackages != null ? `data-max-qty="${esc(p.maxPackages)}" data-max-qty-label="${esc(p.maxLabel || p.maxPackages)}"` : '';
    const effectiveMax = p.effectiveMax != null ? Number(p.effectiveMax) : (p.remaining != null ? Number(p.remaining) : null);
    const remaining = effectiveMax != null ? Math.max(0, effectiveMax) : null;
    const atLimit = remaining !== null && remaining <= 0;
    const inCartQty = Math.max(0, Number(p.cartQty) || 0);
    const inCart = inCartQty > 0;
    const partial = !!p.partialPackage;
    const canAdjust = inCart && !partial;
    const qtyStep = Number(p.qtyStep) > 0 ? Number(p.qtyStep) : 1;
    const qtyMin = Number(p.qtyMin) > 0 ? Number(p.qtyMin) : 1;
    const defaultQty = Number(p.defaultQty) > 0 ? Number(p.defaultQty) : qtyMin;
    const maxInput = remaining !== null && remaining > 0 ? `max="${remaining}"` : (atLimit ? `max="${qtyMin}"` : '');
    const submitDisabled = atLimit && !inCart ? 'disabled' : '';
    const plusDisabled = partial || (remaining !== null && remaining <= 0) ? 'disabled' : '';
    const minusDisabled = partial || defaultQty <= qtyStep ? 'disabled' : '';
    const plusInCartDisabled = !canAdjust || (remaining !== null && remaining <= 0) ? 'disabled' : '';
    const minusInCartDisabled = !canAdjust || inCartQty <= 0 ? 'disabled' : '';
    const effectiveMaxAttr = remaining !== null ? `data-effective-max="${remaining}"` : '';
    const qtyLabel = formatPackageCount(inCartQty);
    const cartMode = inCart
      ? (partial ? 'in-cart-locked' : 'in-cart')
      : (partial ? 'partial-add' : 'add');

    let hint = '';
    if (partial && p.packagesAvailable > 0) {
      hint = `<p class="store-add-cart__note" data-qty-hint>آخر كمية: <span class="store-num" dir="ltr">${formatQty(p.packagesAvailable)}</span> ${esc(p.packageUnit)}</p>`;
    } else if (p.maxLabel != null) {
      hint = `<p class="store-add-cart__note${atLimit && !inCart ? ' is-warning' : ''}" data-qty-hint>${
        atLimit && !inCart
          ? `الحد الأقصى ${esc(p.maxLabel)} ${esc(p.packageUnit)} لكل مادة`
          : `الحد الأقصى ${esc(p.maxLabel)} ${esc(p.packageUnit)} لكل مادة`
      }</p>`;
    }

    const imageField = p.thumbUrl
      ? `<input type="hidden" name="image_url" value="${esc(p.thumbUrl)}">` : '';

    const inCartBlock = `
      <div class="store-add-cart__in-cart"${inCart ? '' : ' hidden'}>
        <div class="store-cart-panel store-cart-panel--in-cart">
          <div class="store-cart-panel__badge">
            <span class="material-symbols-outlined" aria-hidden="true">shopping_cart</span>
            <span>في السلة</span>
          </div>
          <div class="store-cart-panel__controls">
            <div class="store-cart-panel__qty-slot">
              <div class="store-cart-panel__qty-locked" data-cart-qty-locked${canAdjust ? ' hidden' : ''} dir="ltr">
                <strong class="store-num" data-cart-qty-display>${esc(qtyLabel)}</strong>
                <span class="store-qty-stepper__unit">${esc(p.packageUnit || 'طرد')}</span>
              </div>
              <div class="store-qty-stepper store-qty-stepper--card" data-cart-qty-adjust${canAdjust ? '' : ' hidden'}>
                <button type="button" data-cart-bump="-1" aria-label="إنقاص أو حذف من السلة" ${minusInCartDisabled}>−</button>
                <div class="store-qty-stepper__value" dir="ltr">
                  <output class="store-num" data-cart-qty-display>${esc(qtyLabel)}</output>
                  <span class="store-qty-stepper__unit">${esc(p.packageUnit || 'طرد')}</span>
                </div>
                <button type="button" data-cart-bump="1" aria-label="زيادة" ${plusInCartDisabled}>+</button>
              </div>
            </div>
          </div>
        </div>
      </div>`;

    const addBlock = `
      <div class="store-add-cart__add"${inCart ? ' hidden' : ''}>
        ${hint}
        <div class="store-cart-panel store-cart-panel--add">
          <div class="store-cart-panel__controls">
            <div class="store-qty-stepper store-qty-stepper--card${partial ? ' store-qty-stepper--locked' : ''}">
              <button type="button" data-qty-minus aria-label="إنقاص" ${minusDisabled}>−</button>
              <div class="store-qty-stepper__value" dir="ltr">
                <input type="number" class="store-num" name="quantity" min="${qtyMin}" ${maxInput} step="${qtyStep}" value="${defaultQty}" ${partial ? 'readonly' : ''}>
                <span class="store-qty-stepper__unit">${esc(p.packageUnit || 'طرد')}</span>
              </div>
              <button type="button" data-qty-plus aria-label="زيادة" ${plusDisabled}>+</button>
            </div>
          </div>
          <button type="submit" class="store-add-cart__submit" ${submitDisabled}>
            <span class="material-symbols-outlined text-[20px]" aria-hidden="true">add_shopping_cart</span>
            ${atLimit ? 'مكتمل' : (partial ? 'طلب الكمية' : 'إضافة')}
          </button>
        </div>
      </div>`;

    return `
      <form
        method="post"
        class="store-add-cart store-add-cart--preview${inCart ? ' store-add-cart--in-cart' : ''}${partial ? ' store-add-cart--locked' : ''}"
        action="#"
        data-store-add-cart="1"
        data-no-page-loading="1"
        data-cart-mode="${esc(cartMode)}"
        data-partial-package="${partial ? '1' : '0'}"
        data-material-guid="${esc(p.guid)}"
        data-cart-qty="${esc(inCartQty)}"
        data-qty-step="${qtyStep}"
        data-package-unit="${esc(p.packageUnit || 'طرد')}"
        ${maxAttr}
        ${effectiveMaxAttr}
      >
        <input type="hidden" name="action" value="add_to_cart">
        ${p.storeSection ? `<input type="hidden" name="store_section" value="${esc(p.storeSection)}">` : ''}
        ${p.storeOffer ? `<input type="hidden" name="store_offer" value="${esc(p.storeOffer)}">` : ''}
        <input type="hidden" name="material_guid" value="${esc(p.guid)}">
        <input type="hidden" name="material_code" value="${esc(p.code)}">
        <input type="hidden" name="material_name_ar" value="${esc(p.name)}">
        <input type="hidden" name="primary_unit" value="${esc(p.primaryUnit)}">
        <input type="hidden" name="package_unit" value="${esc(p.packageUnit)}">
        <input type="hidden" name="packaging" value="${esc(p.packaging)}">
        <input type="hidden" name="unit_sale_price_sp" value="${esc(p.unitSaleSp)}">
        <input type="hidden" name="unit_sale_price_usd" value="${esc(p.unitSaleUsd)}">
        ${imageField}
        ${inCartBlock}
        ${addBlock}
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

  const applyCartQtyToPayload = (p, inCart) => {
    const next = { ...p, cartQty: inCart };
    if (next.effectiveMax != null || next.maxPackages != null) {
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
      if (effective !== null) {
        next.effectiveMax = effective;
        next.remaining = effective;
        next.atLimit = effective <= 0;
      }
    }
    return next;
  };

  const resolveCartQtyForGuid = (guid) => {
    if (!guid) return null;

    const previewForm = modal.querySelector(
      `[data-store-add-cart][data-material-guid="${CSS.escape(guid)}"]`,
    );
    if (previewForm) {
      return Math.max(0, parseFloat(previewForm.dataset.cartQty || '0') || 0);
    }

    const card = findPreviewCard(guid);
    const cardForm = card?.querySelector('[data-store-add-cart]');
    if (cardForm) {
      return Math.max(0, parseFloat(cardForm.dataset.cartQty || '0') || 0);
    }

    if (card) {
      try {
        const payload = JSON.parse(card.getAttribute('data-preview') || '{}');
        if (payload?.guid === guid) {
          return Math.max(0, Number(payload.cartQty) || 0);
        }
      } catch {
        // ignore invalid preview payload
      }
    }

    return null;
  };

  const syncCartQtyFromDom = (p) => {
    if (!p?.guid) return p;
    const resolved = resolveCartQtyForGuid(p.guid);
    if (resolved === null) return p;
    return applyCartQtyToPayload(p, resolved);
  };

  const updateNav = () => {
    const total = state.items.length;
    const isCart = state.context === 'cart';
    const isOrder = state.context === 'order';
    const scopedCatalog = isScopedCatalog();
    const pageInfo = (isCart || isOrder || scopedCatalog) ? {} : paging();
    const hasPrevPage = !isCart && !isOrder && !!pageInfo.prevPageUrl;
    const hasNextPage = !isCart && !isOrder && !!pageInfo.nextPageUrl;
    const atFirst = state.index <= 0;
    const atLast = state.index >= total - 1;

    if (counterEl) {
      const pageLabel = !isCart && !isOrder && pageInfo.totalPages > 1
        ? ` — صفحة <span class="store-num" dir="ltr">${pageInfo.page}</span>/<span class="store-num" dir="ltr">${pageInfo.totalPages}</span>`
        : '';
      const orderLabel = isOrder ? 'صنف ' : '';
      counterEl.innerHTML = total > 0
        ? `${orderLabel}<span class="store-num" dir="ltr">${state.index + 1}</span> / <span class="store-num" dir="ltr">${total}</span>${pageLabel}`
        : '';
      counterEl.classList.toggle('store-product-preview__counter--order', isOrder);
    }
    if (btnPrev) btnPrev.disabled = state.navigating || (atFirst && !hasPrevPage);
    if (btnNext) btnNext.disabled = state.navigating || (atLast && !hasNextPage);
  };

  const render = (p, imageOptions = {}) => {
    const item = state.context === 'order' ? p : syncCartQtyFromDom(p);
    if (!imageOptions.preferElement) {
      prepareImageTransition();
    }
    const panel = modal.querySelector('.store-product-preview__panel');
    if (panel) {
      panel.classList.toggle('store-product-preview__panel--offer', !!item.hasOffer);
      panel.classList.toggle('store-product-preview__panel--cart', state.context === 'cart');
      panel.classList.toggle('store-product-preview__panel--order', state.context === 'order');
    }

    state.currentGuid = item.guid || state.currentGuid;

    if (imgEl) {
      imgEl.alt = item.name || '';
    }
    applyPreviewImage(item, imageOptions);
    preloadAdjacent(state.index);

    const imageWrap = modal.querySelector('.store-product-preview__image-wrap');
    if (imageWrap) {
      let banner = imageWrap.querySelector('.store-product-preview__offer-banner');
      if (item.hasOffer && state.context !== 'order') {
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
      if (state.context === 'order') {
        if (item.isCancelled) {
          subtitleEl.textContent = 'صنف ملغى من الطلب';
        } else {
          subtitleEl.textContent = item.code ? `#${item.code}` : '';
        }
        subtitleEl.hidden = !subtitleEl.textContent;
      } else {
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
    }

    if (state.context === 'order') {
      if (packagingEl) {
        packagingEl.innerHTML = '';
        packagingEl.hidden = true;
      }
      if (pricesEl) pricesEl.innerHTML = '';
      if (cartEl) {
        cartEl.innerHTML = renderOrderStaffPanel(item);
        mountOrderStaffEdit(item, cartEl);
      }
      if (detailEl) detailEl.classList.add('hidden');
    } else {
      if (packagingEl) packagingEl.hidden = false;
      renderPackaging(item);
      if (pricesEl) pricesEl.innerHTML = renderPrices(item);
      if (state.context === 'cart') {
        mountCartLineControls(item, cartEl);
        updateInCartBanner(item);
        if (detailEl) detailEl.classList.add('hidden');
      } else {
        mountCartForm(item, cartEl);
        updateInCartBanner(item);
        if (detailEl) {
          const detailHref = detailUrlForItem(item);
          detailEl.href = detailHref || '/store.php';
          detailEl.classList.toggle('hidden', !detailHref);
        }
      }
    }

    updateNav();
  };

  const showAt = (index, imageOptions = {}) => {
    if (state.items.length === 0) {
      close();
      return;
    }
    state.index = Math.max(0, Math.min(index, state.items.length - 1));
    const item = state.items[state.index];
    if (item?.guid) state.currentGuid = item.guid;
    const preferElement = imageOptions.preferElement instanceof HTMLImageElement
      ? imageOptions.preferElement
      : null;
    render(item, preferElement ? { preferElement } : {});
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
        state.itemScope = null;
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

    if (newIndex >= 0 && newIndex < state.items.length) {
      showAt(newIndex);
      return;
    }

    if (state.context === 'cart' || state.context === 'order' || isScopedCatalog()) {
      return;
    }

    const pageInfo = paging();

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

  const open = (guid, preferElement = null, options = {}) => {
    const cartRoot = options.cartRoot || null;
    const orderRoot = options.orderRoot || null;
    const itemScope = cartRoot || orderRoot ? null : (options.itemScope || null);
    state.context = orderRoot ? 'order' : cartRoot ? 'cart' : 'catalog';
    state.cartRoot = cartRoot;
    state.orderRoot = orderRoot;
    state.itemScope = itemScope;
    state.items = collectItems({ cartRoot, itemScope, orderRoot });
    if (state.items.length === 0) return;
    const idx = state.items.findIndex((item) => item.guid === guid);
    state.index = idx >= 0 ? idx : 0;
    state.currentGuid = guid;
    openModal();
    const item = state.items[state.index];
    const sourceImg = preferElement instanceof HTMLImageElement
      ? preferElement
      : findCardImageForItem(item);
    showAt(state.index, sourceImg ? { preferElement: sourceImg } : {});
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
    state.cartRoot = null;
    state.orderRoot = null;
    state.itemScope = null;
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
    const cartRoot = trigger.closest('[data-store-cart-preview-root]');
    const orderRoot = trigger.closest('[data-store-order-preview-root]');
    const itemScope = cartRoot || orderRoot ? null : resolveItemScope(trigger);
    const sourceImg = trigger.querySelector('img')
      || trigger.querySelector('.material-image-frame__photo img')
      || card?.querySelector('.material-image-frame__photo img')
      || card?.querySelector('.dash-order-item__thumb img')
      || card?.querySelector('.store-order-line-card__thumb img');
    open(guid, sourceImg, { cartRoot, orderRoot, itemScope });
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

  document.addEventListener('store-cart-updated', (event) => {
    if (modal.hidden) return;

    const detail = event.detail || {};
    const qtyMap = detail.cart_qty_by_guid;
    const apiItems = Array.isArray(detail.items) ? detail.items : null;
    const cartIsEmpty = Number(detail.cart_count) === 0
      || (apiItems && apiItems.length === 0)
      || (qtyMap && typeof qtyMap === 'object' && Object.keys(qtyMap).length === 0);

    if (state.context === 'cart' && state.cartRoot) {
      if (cartIsEmpty) {
        close();
        return;
      }

      const prevIndex = state.index;
      const prevGuid = state.currentGuid;
      state.items = collectItems({ cartRoot: state.cartRoot });

      if (qtyMap && typeof qtyMap === 'object') {
        state.items = state.items
          .map((item) => {
            if (!item?.guid) return item;
            const raw = qtyMap[item.guid]
              ?? qtyMap[item.guid.toLowerCase()]
              ?? qtyMap[item.guid.toUpperCase()];
            if (raw !== undefined) {
              return applyCartQtyToPayload(item, Math.max(0, Number(raw) || 0));
            }
            return syncCartQtyFromDom(item);
          })
          .filter((item) => {
            if (!item?.guid) return false;
            const raw = qtyMap[item.guid]
              ?? qtyMap[item.guid.toLowerCase()]
              ?? qtyMap[item.guid.toUpperCase()];
            return raw !== undefined && Number(raw) > 0;
          });
      }

      if (state.items.length === 0) {
        close();
        return;
      }

      let idx = state.items.findIndex((item) => item.guid === prevGuid);
      if (idx < 0) {
        idx = Math.min(prevIndex, state.items.length - 1);
      }
      state.index = idx;
      state.currentGuid = state.items[idx]?.guid || '';
      showAt(state.index);
      return;
    }

    if (state.items.length === 0) return;
    const current = state.items[state.index];
    if (!current?.guid) return;

    if (qtyMap && typeof qtyMap === 'object') {
      state.items = state.items.map((item) => {
        if (!item?.guid) return item;
        const raw = qtyMap[item.guid]
          ?? qtyMap[item.guid.toLowerCase()]
          ?? qtyMap[item.guid.toUpperCase()];
        if (raw !== undefined) {
          return applyCartQtyToPayload(item, Math.max(0, Number(raw) || 0));
        }
        return syncCartQtyFromDom(item);
      });
    } else {
      state.items[state.index] = syncCartQtyFromDom(current);
    }

    render(state.items[state.index]);
  });

  window.StoreProductPreview = { close };
})();
