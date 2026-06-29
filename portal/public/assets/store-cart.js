(() => {
  const API = '/api/store-cart.php';
  let lastCartData = null;

  const CART_SYNC_KEY = 'jawish-store-cart-sync';
  const CART_SYNC_CHANNEL = 'jawish-store-cart';
  const DRAWER_CLOSE_MS = 260;
  const tabId = `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
  let lastRemoteSyncAt = 0;
  let tabHiddenAt = 0;
  let refreshInFlight = null;
  let cartSyncChannel = null;
  let drawerCloseGuardUntil = 0;
  let drawerCloseTimer = null;

  const handleCartSyncMessage = (message) => {
    if (!message || typeof message !== 'object' || message.tabId === tabId) return;
    const ts = Number(message.ts) || 0;
    if (ts <= lastRemoteSyncAt) return;
    if (!message.data || typeof message.data !== 'object') return;
    lastRemoteSyncAt = ts;
    applyCartResponse(message.data, { remote: true, silent: true });
  };

  const publishCartSync = (data) => {
    if (!data || typeof data !== 'object' || !data.cart_qty_by_guid) return;
    const message = { tabId, ts: Date.now(), data };
    try {
      cartSyncChannel?.postMessage(message);
    } catch {
      // ignore channel errors
    }
    try {
      localStorage.setItem(CART_SYNC_KEY, JSON.stringify(message));
    } catch {
      // ignore private mode / quota errors
    }
  };

  const refreshCartFromServer = async (options = {}) => {
    if (refreshInFlight) return refreshInFlight;
    refreshInFlight = (async () => {
      try {
        const res = await fetch(`${API}?reconcile=1`, {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        });
        const data = await res.json();
        applyCartResponse(data, { remote: true, silent: options.silent !== false });
      } catch {
        // ignore background refresh errors
      } finally {
        refreshInFlight = null;
      }
    })();
    return refreshInFlight;
  };

  const initCartCrossTabSync = () => {
    if (typeof BroadcastChannel !== 'undefined') {
      try {
        cartSyncChannel = new BroadcastChannel(CART_SYNC_CHANNEL);
        cartSyncChannel.onmessage = (event) => handleCartSyncMessage(event.data);
      } catch {
        cartSyncChannel = null;
      }
    }

    window.addEventListener('storage', (event) => {
      if (event.key !== CART_SYNC_KEY || !event.newValue) return;
      try {
        handleCartSyncMessage(JSON.parse(event.newValue));
      } catch {
        // ignore invalid sync payload
      }
    });

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'hidden') {
        tabHiddenAt = Date.now();
        return;
      }
      if (tabHiddenAt > 0 && Date.now() - tabHiddenAt > 800) {
        refreshCartFromServer({ silent: true });
      }
    });
  };

  const toastHost = () => {
    let host = document.getElementById('storeCartToastHost');
    if (!host) {
      host = document.createElement('div');
      host.id = 'storeCartToastHost';
      host.setAttribute('aria-live', 'polite');
      document.body.appendChild(host);
    }
    return host;
  };

  let lastToastMessage = '';
  let lastToastAt = 0;

  const showToast = (message, level = 'success') => {
    if (!message) return;
    const now = Date.now();
    if (message === lastToastMessage && now - lastToastAt < 2500) return;
    lastToastMessage = message;
    lastToastAt = now;
    const el = document.createElement('div');
    el.className = `store-cart-toast store-cart-toast--${level}`;
    const icon = level === 'error' ? 'error' : level === 'warning' ? 'warning' : 'check_circle';
    el.innerHTML = `<span class="material-symbols-outlined text-[20px]" aria-hidden="true">${icon}</span><span>${escapeHtml(message)}</span>`;
    toastHost().appendChild(el);
    setTimeout(() => {
      el.style.opacity = '0';
      el.style.transition = 'opacity 0.25s ease';
      setTimeout(() => el.remove(), 280);
    }, 4200);
  };

  const escapeHtml = (text) => {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  };

  const imageZoomUrl = (url) => {
    if (window.StoreImageZoom?.imageZoomUrl) {
      return window.StoreImageZoom.imageZoomUrl(url);
    }
    const text = String(url || '');
    if (!text) return '';
    if (text.includes('thumb=1')) return text.replace('thumb=1', 'thumb=0');
    return text.includes('?') ? `${text}&thumb=0` : `${text}?thumb=0`;
  };

  const loadLightboxImage = (imgEl, fullUrl, thumbUrl, preferElement) => {
    if (window.StoreImageZoom?.loadProgressive) {
      window.StoreImageZoom.loadProgressive(imgEl, fullUrl, thumbUrl, { preferElement });
      return;
    }
    imgEl.src = fullUrl;
  };

  const hydrateCartLineImages = (root) => {
    const zoom = window.StoreImageZoom;
    if (!zoom?.applySrc || !root) return;
    root.querySelectorAll('[data-cart-image-zoom] img').forEach((img) => {
      const src = img.getAttribute('src') || '';
      if (!src) return;
      zoom.applySrc(img, src);
    });
    zoom.seedLoadedImages?.(root);
  };

  const bindImageZoom = (root = document) => {
    const lightbox = document.getElementById('storeImageLightbox');
    const lightboxImg = document.getElementById('storeImageLightboxImg');
    const lightboxCaption = document.getElementById('storeImageLightboxCaption');
    if (!lightbox || !lightboxImg) return;

    const close = () => {
      lightbox.hidden = true;
      lightbox.setAttribute('aria-hidden', 'true');
      lightboxImg.removeAttribute('src');
      lightboxImg.classList.remove('is-upgrading');
      delete lightboxImg.dataset.pendingFull;
      document.body.style.overflow = '';
    };

    if (!lightbox.dataset.bound) {
      lightbox.dataset.bound = '1';
      lightbox.querySelectorAll('[data-lightbox-close]').forEach((el) => el.addEventListener('click', close));
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !lightbox.hidden) close();
      });
    }

    root.querySelectorAll('[data-cart-image-zoom]').forEach((btn) => {
      if (btn.dataset.zoomBound === '1') return;
      btn.dataset.zoomBound = '1';
      btn.addEventListener('click', () => {
        const raw = btn.getAttribute('data-cart-image-zoom') || '';
        const thumbImg = btn.querySelector('img');
        const thumbSrc = thumbImg?.currentSrc || thumbImg?.getAttribute('src') || '';
        const src = raw || imageZoomUrl(thumbSrc);
        if (!src) return;
        const name = btn.closest('.store-order-line-card, .store-cart-product, .store-cart-line-card')
          ?.querySelector('.store-order-line-card__title, .font-bold')?.textContent?.trim() || '';
        loadLightboxImage(lightboxImg, src, thumbSrc, thumbImg);
        if (lightboxCaption) lightboxCaption.textContent = name;
        lightbox.hidden = false;
        lightbox.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
      });
    });
  };

  const formatUsd = (amount) => {
    const n = Number(amount) || 0;
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };

  const formatMoney = (amount) => {
    const n = Number(amount) || 0;
    return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  };

  const formatQty = (amount) => {
    const n = Number(amount) || 0;
    return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  };

  const lineHasOffer = (line) => {
    if (!line || typeof line !== 'object') return false;
    if (line.has_offer || line.special_offer_id) return true;
    const origSp = Number(line.original_sale_price_sp) || 0;
    const saleSp = Number(line.sale_price_sp) || 0;
    if (origSp > 0 && saleSp > 0 && origSp > saleSp + 0.009) return true;
    const origUsd = Number(line.original_sale_price_usd) || 0;
    const saleUsd = Number(line.sale_price_usd) || 0;
    return origUsd > 0 && saleUsd > 0 && origUsd > saleUsd + 0.009;
  };

  const lineOfferBadge = (line) => {
    const badge = String(line?.offer_badge || '').trim();
    if (badge) return badge;
    const title = String(line?.offer_title_ar || '').trim();
    return title || 'عرض خاص';
  };

  const offerBadgeHtml = (line) => {
    if (!lineHasOffer(line)) return '';
    return `<span class="store-offer-badge store-offer-badge--sm"><span class="material-symbols-outlined" aria-hidden="true">sell</span>${escapeHtml(lineOfferBadge(line))}</span>`;
  };

  const computeLinePrices = (line) => {
    const packaging = Math.max(1, Number(line.packaging ?? line.pcs_per_box ?? 1) || 1);
    const primaryUnit = String(line.primary_unit || '').trim() || 'زوج';
    const packageUnit = String(line.package_unit || '').trim() || 'طرد';
    const quantity = Math.max(0, Number(line.quantity) || 0);

    let packSp = Number(line.sale_price_sp) || 0;
    let packUsd = Number(line.sale_price_usd) || 0;
    let unitSp = Number(line.unit_sale_price_sp) || 0;
    let unitUsd = Number(line.unit_sale_price_usd) || 0;

    if (unitSp <= 0 && packSp > 0) unitSp = packSp / packaging;
    if (unitUsd <= 0 && packUsd > 0) unitUsd = packUsd / packaging;
    if (packSp <= 0 && unitSp > 0) packSp = unitSp * packaging;
    if (packUsd <= 0 && unitUsd > 0) packUsd = unitUsd * packaging;

    let origPackSp = Number(line.original_sale_price_sp ?? line.original_package_sale_price_sp) || 0;
    let origPackUsd = Number(line.original_sale_price_usd ?? line.original_package_sale_price_usd) || 0;
    let origUnitSp = Number(line.original_unit_sale_price_sp) || 0;
    let origUnitUsd = Number(line.original_unit_sale_price_usd) || 0;
    if (origUnitSp <= 0 && origPackSp > 0) origUnitSp = origPackSp / packaging;
    if (origUnitUsd <= 0 && origPackUsd > 0) origUnitUsd = origPackUsd / packaging;

    return {
      packaging,
      primaryUnit,
      packageUnit,
      quantity,
      packSp,
      packUsd,
      unitSp,
      unitUsd,
      origPackSp,
      origPackUsd,
      origUnitSp,
      origUnitUsd,
      lineTotalSp: quantity * packSp,
      lineTotalUsd: quantity * packUsd,
    };
  };

  const lineHasDisplayPrice = (line) => {
    if (line?.display_has_price === true) {
      const prices = computeLinePrices(line);
      return prices.packSp > 0 || prices.packUsd > 0;
    }
    if (line?.display_has_price === false) return false;
    if (line?.customer_show_price === false) return false;
    if (line?.customer_show_price === true) {
      const prices = computeLinePrices(line);
      return prices.packSp > 0 || prices.packUsd > 0;
    }
    return false;
  };

  const partitionCartItems = (items) => {
    const priced = [];
    const unpriced = [];
    (Array.isArray(items) ? items : []).forEach((line) => {
      if (lineHasDisplayPrice(line)) priced.push(line);
      else unpriced.push(line);
    });
    return {
      priced,
      unpriced,
      hasMixed: priced.length > 0 && unpriced.length > 0,
    };
  };

  const linePricesHtml = (line) => {
    if (!lineHasDisplayPrice(line)) return '';
    const prices = computeLinePrices(line);
    const hasOffer = lineHasOffer(line);
    let html = '<div class="store-order-line-prices store-order-line-prices--compact">';

    if (prices.packSp > 0 || prices.unitSp > 0) {
      html += '<div class="store-price-currency store-price-currency--syp">';
      if (prices.unitSp > 0) {
        html += `<div class="store-order-line-prices__row store-order-line-prices__row--main">
          <span class="store-order-line-prices__label">${escapeHtml(prices.primaryUnit)}</span>
          <div class="store-order-line-prices__values">
            ${hasOffer && prices.origUnitSp > prices.unitSp ? `<span class="store-order-line-prices__old store-num" dir="ltr">${formatMoney(prices.origUnitSp)}</span>` : ''}
            <span class="store-order-line-prices__amount store-num" dir="ltr">${formatMoney(prices.unitSp)} <small>ل.س</small></span>
          </div>
        </div>`;
      }
      if (prices.packSp > 0) {
        html += `<div class="store-order-line-prices__row">
          <span class="store-order-line-prices__label">${escapeHtml(prices.packageUnit)}</span>
          <div class="store-order-line-prices__values">
            ${hasOffer && prices.origPackSp > prices.packSp ? `<span class="store-order-line-prices__old store-num" dir="ltr">${formatMoney(prices.origPackSp)}</span>` : ''}
            <span class="store-order-line-prices__amount store-order-line-prices__amount--pack store-num" dir="ltr">${formatMoney(prices.packSp)} <small>ل.س</small></span>
          </div>
        </div>`;
      }
      html += '</div>';
    }

    if (prices.packUsd > 0 || prices.unitUsd > 0) {
      html += '<div class="store-price-currency store-price-currency--usd">';
      if (prices.unitUsd > 0) {
        html += `<div class="store-order-line-prices__row store-order-line-prices__row--main">
          <span class="store-order-line-prices__label">${escapeHtml(prices.primaryUnit)}</span>
          <div class="store-order-line-prices__values">
            ${hasOffer && prices.origUnitUsd > prices.unitUsd ? `<span class="store-order-line-prices__old store-num" dir="ltr">$${formatUsd(prices.origUnitUsd)}</span>` : ''}
            <span class="store-order-line-prices__amount store-num" dir="ltr">$${formatUsd(prices.unitUsd)}</span>
          </div>
        </div>`;
      }
      if (prices.packUsd > 0) {
        html += `<div class="store-order-line-prices__row">
          <span class="store-order-line-prices__label">${escapeHtml(prices.packageUnit)}</span>
          <div class="store-order-line-prices__values">
            ${hasOffer && prices.origPackUsd > prices.packUsd ? `<span class="store-order-line-prices__old store-num" dir="ltr">$${formatUsd(prices.origPackUsd)}</span>` : ''}
            <span class="store-order-line-prices__amount store-order-line-prices__amount--pack store-num" dir="ltr">$${formatUsd(prices.packUsd)}</span>
          </div>
        </div>`;
      }
      html += '</div>';
    }

    html += '</div>';
    return html;
  };

  const PRICE_CHANGE_EPSILON = 0.009;

  const priceChangeDiffers = (change, line, prices) => {
    if (!change || typeof change !== 'object') return false;
    const oldSp = Number(line?.price_snapshot_sp) || Number(change.old_sale_price_sp) || 0;
    const oldUsd = Number(line?.price_snapshot_usd) || Number(change.old_sale_price_usd) || 0;
    const newSp = Number(prices?.packSp) || Number(change.new_sale_price_sp) || 0;
    const newUsd = Number(prices?.packUsd) || Number(change.new_sale_price_usd) || 0;
    const spDiffers = (oldSp > 0 || newSp > 0) && Math.abs(oldSp - newSp) > PRICE_CHANGE_EPSILON;
    const usdDiffers = (oldUsd > 0 || newUsd > 0) && Math.abs(oldUsd - newUsd) > PRICE_CHANGE_EPSILON;
    return spDiffers || usdDiffers;
  };

  const priceChangeDirection = (change, line = null, prices = null) => {
    if (!change || typeof change !== 'object') return null;
    if (line && prices && !priceChangeDiffers(change, line, prices)) return null;

    const oldSp = Number(line?.price_snapshot_sp) || Number(change.old_sale_price_sp) || 0;
    const oldUsd = Number(line?.price_snapshot_usd) || Number(change.old_sale_price_usd) || 0;
    const newSp = Number(prices?.packSp) || Number(change.new_sale_price_sp) || 0;
    const newUsd = Number(prices?.packUsd) || Number(change.new_sale_price_usd) || 0;

    if (line && prices) {
      const upSp = oldSp > 0 && newSp > 0 && newSp > oldSp + PRICE_CHANGE_EPSILON;
      const downSp = oldSp > 0 && newSp > 0 && newSp < oldSp - PRICE_CHANGE_EPSILON;
      const upUsd = oldUsd > 0 && newUsd > 0 && newUsd > oldUsd + PRICE_CHANGE_EPSILON;
      const downUsd = oldUsd > 0 && newUsd > 0 && newUsd < oldUsd - PRICE_CHANGE_EPSILON;
      const up = upSp || upUsd || (oldSp <= 0 && newSp > 0) || (oldUsd <= 0 && newUsd > 0);
      const down = downSp || downUsd || (newSp <= 0 && oldSp > 0) || (newUsd <= 0 && oldUsd > 0);
      if (up && !down) return 'up';
      if (down && !up) return 'down';
      if (up || down) return 'changed';
      return null;
    }

    const up = change.direction_sp === 'up' || change.direction_usd === 'up';
    const down = change.direction_sp === 'down' || change.direction_usd === 'down';
    if (up && !down) return 'up';
    if (down && !up) return 'down';
    if (up || down) return 'changed';
    return null;
  };

  const priceChangeHtml = (line, prices = null) => {
    const change = line?.price_change;
    const resolvedPrices = prices || computeLinePrices(line);
    const direction = priceChangeDirection(change, line, resolvedPrices);
    if (!direction) return '';
    const icon = direction === 'up' ? 'arrow_upward' : (direction === 'down' ? 'arrow_downward' : 'swap_vert');
    const label = direction === 'up' ? 'ارتفع السعر' : (direction === 'down' ? 'انخفض السعر' : 'تغيّر السعر');
    return `<span class="store-price-change store-price-change--${direction}">
      <span class="material-symbols-outlined store-price-change__icon" aria-hidden="true">${icon}</span>
      <span>${escapeHtml(label)}</span>
    </span>`;
  };

  const linePriceChangeDetailHtml = (line, prices, showPrice = true) => {
    const change = line?.price_change;
    const direction = priceChangeDirection(change, line, prices);
    if (!direction) return '';
    const icon = direction === 'up' ? 'trending_up' : (direction === 'down' ? 'trending_down' : 'swap_vert');
    const rows = [];

    const oldSp = Number(line?.price_snapshot_sp) || Number(change.old_sale_price_sp) || 0;
    const newSp = Number(prices.packSp) || Number(change.new_sale_price_sp) || 0;
    if (oldSp > 0 && newSp > 0 && Math.abs(oldSp - newSp) > PRICE_CHANGE_EPSILON) {
      const rowDir = newSp > oldSp ? 'up' : 'down';
      rows.push(`<div class="store-price-change-detail__row store-price-change-detail__row--${rowDir}">
        <span class="material-symbols-outlined" aria-hidden="true">${icon}</span>
        <span class="store-price-change-detail__label">ل.س / ${escapeHtml(prices.packageUnit)}</span>
        ${showPrice ? `<span class="store-price-change-detail__old store-num" dir="ltr">${formatMoney(oldSp)}</span>
        <span class="store-price-change-detail__sep" aria-hidden="true">→</span>
        <span class="store-price-change-detail__new store-num" dir="ltr">${formatMoney(newSp)}</span>` : ''}
      </div>`);
    }

    const oldUsd = Number(line?.price_snapshot_usd) || Number(change.old_sale_price_usd) || 0;
    const newUsd = Number(prices.packUsd) || Number(change.new_sale_price_usd) || 0;
    if (oldUsd > 0 && newUsd > 0 && Math.abs(oldUsd - newUsd) > PRICE_CHANGE_EPSILON) {
      const rowDir = newUsd > oldUsd ? 'up' : 'down';
      rows.push(`<div class="store-price-change-detail__row store-price-change-detail__row--${rowDir}">
        <span class="material-symbols-outlined" aria-hidden="true">${icon}</span>
        <span class="store-price-change-detail__label">$ / ${escapeHtml(prices.packageUnit)}</span>
        ${showPrice ? `<span class="store-price-change-detail__old store-num" dir="ltr">$${formatUsd(oldUsd)}</span>
        <span class="store-price-change-detail__sep" aria-hidden="true">→</span>
        <span class="store-price-change-detail__new store-num" dir="ltr">$${formatUsd(newUsd)}</span>` : ''}
      </div>`);
    }

    if (rows.length === 0) return '';

    return `<div class="store-price-change-detail store-price-change-detail--${direction}">${rows.join('')}</div>`;
  };

  const buildPriceChangeConfirmMessage = (changes) => {
    const rows = Array.isArray(changes) ? changes : [];
    if (rows.length === 0) return 'تغيّرت أسعار بعض الأصناف.';
    const lines = rows.slice(0, 6).map((row) => {
      const name = String(row.material_name_ar || 'صنف');
      const up = row.direction_sp === 'up' || row.direction_usd === 'up';
      const down = row.direction_sp === 'down' || row.direction_usd === 'down';
      const dir = up && !down ? 'ارتفع' : (down && !up ? 'انخفض' : 'تغيّر');
      return `• ${name}: ${dir}`;
    });
    if (rows.length > 6) lines.push(`• و${rows.length - 6} أصناف أخرى`);
    return `تغيّرت أسعار الأصناف التالية:\n${lines.join('\n')}\n\nهل تريد المتابعة بالأسعار الحالية من النظام؟`;
  };

  const renderCartLineCard = (line, max) => {
    const guid = line.material_guid || '';
    const prices = computeLinePrices(line);
    const hasOffer = lineHasOffer(line);
    const lineShowPrice = lineHasDisplayPrice(line);
    const priceDirection = priceChangeDirection(line?.price_change, line, prices);
    const priceCardClass = priceDirection ? ` store-cart-line-card--price-${priceDirection}` : '';
    const noPriceClass = !lineShowPrice ? ' store-cart-line-card--no-price' : '';
    const img = line.image_url
      ? (() => {
          const thumb = escapeHtml(line.image_url);
          const zoom = escapeHtml(imageZoomUrl(line.image_url));
          return `<button type="button" class="store-order-line-card__thumb" data-cart-image-zoom="${zoom}" title="تكبير الصورة للتدقيق"><img src="${thumb}" alt="" loading="lazy"><span class="store-order-line-card__zoom-icon material-symbols-outlined" aria-hidden="true">zoom_in</span></button>`;
        })()
      : '<div class="store-order-line-card__placeholder"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span></div>';
    const lineTotalCell = lineShowPrice && (prices.packSp > 0 || prices.packUsd > 0)
      ? `<div class="store-order-line-card__totals">
          ${prices.packSp > 0 ? `<span class="store-price-currency store-price-currency--syp store-num" dir="ltr">${formatMoney(prices.lineTotalSp)} ل.س</span>` : ''}
          ${prices.packUsd > 0 ? `<span class="store-price-currency store-price-currency--usd store-num" dir="ltr">$${formatUsd(prices.lineTotalUsd)}</span>` : ''}
        </div>`
      : '';
    const noPriceHtml = !lineShowPrice
      ? `<div class="store-cart-line-card__no-price">
          <span class="material-symbols-outlined" aria-hidden="true">receipt_long</span>
          <span>السعر عند التأكيد</span>
        </div>`
      : '';
    return `<article class="store-order-line-card store-cart-line-card${hasOffer ? ' store-order-line-card--offer' : ''}${priceCardClass}${noPriceClass}" data-cart-line="${escapeHtml(guid)}">
      <div class="store-order-line-card__media">${img}</div>
      <div class="store-order-line-card__body">
        <div class="store-cart-line-card__head">
          <div class="store-order-line-card__head-main min-w-0">
            ${offerBadgeHtml(line)}
            <h3 class="store-order-line-card__title">${escapeHtml(line.material_name_ar || '')}</h3>
            ${line.material_code ? `<span class="store-order-line-card__code store-num" dir="ltr">${escapeHtml(line.material_code)}</span>` : ''}
          </div>
          <button type="button" class="store-cart-line-card__remove" data-remove-item="${escapeHtml(guid)}" aria-label="حذف من السلة">
            <span class="material-symbols-outlined" aria-hidden="true">delete</span>
          </button>
        </div>
        <div class="store-cart-line-card__foot">
          ${priceChangeHtml(line, prices)}
          ${linePriceChangeDetailHtml(line, prices, lineShowPrice)}
          ${lineShowPrice ? linePricesHtml(line) : noPriceHtml}
          <div class="store-cart-line-card__controls">
            <div class="store-cart-line-card__qty-row">
              <div class="store-qty-stepper store-qty-stepper--compact" data-cart-qty-control data-guid="${escapeHtml(guid)}">
                <button type="button" data-bump="-1" aria-label="إنقاص">−</button>
                <input type="number" class="store-num" dir="ltr" min="0.01" step="0.01" ${max ? `max="${max}"` : ''} value="${formatQty(prices.quantity)}" data-qty-input>
                <button type="button" data-bump="1" aria-label="زيادة">+</button>
              </div>
              <span class="store-cart-line-card__unit">${escapeHtml(prices.packageUnit)}</span>
            </div>
            ${lineTotalCell ? `<div class="store-order-line-card__total store-cart-line-card__total"><span>الإجمالي</span><strong class="store-num" dir="ltr">${lineTotalCell}</strong></div>` : ''}
          </div>
        </div>
      </div>
    </article>`;
  };

  const renderCartSection = (lines, sectionClass, icon, title, subtitle, max, showSectionHeader) => {
    if (!lines.length) return '';
    let html = `<section class="store-cart-section ${sectionClass}">`;
    if (showSectionHeader) {
      html += `<header class="store-cart-section__head">
        <div class="store-cart-section__title-row">
          <span class="material-symbols-outlined store-cart-section__icon" aria-hidden="true">${icon}</span>
          <div>
            <h3 class="store-cart-section__title">${escapeHtml(title)}</h3>
            <p class="store-cart-section__subtitle">${escapeHtml(subtitle)}</p>
          </div>
        </div>
        <span class="store-cart-section__count">${lines.length} صنف</span>
      </header>`;
    }
    html += '<div class="store-cart-lines">';
    lines.forEach((line) => {
      html += renderCartLineCard(line, max);
    });
    html += '</div></section>';
    return html;
  };

  const formatPackageCount = (amount) => {
    const n = Number(amount) || 0;
    if (Math.abs(n - Math.round(n)) < 0.0001) {
      return String(Math.round(n));
    }
    return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  };

  const updateBadge = (data) => {
    const packageCount = typeof data === 'object' && data !== null
      ? Math.max(0, Number(data.cart_package_count) || 0)
      : Math.max(0, Number(data) || 0);

    document.querySelectorAll('[data-store-cart-badge]').forEach((badge) => {
      const label = formatPackageCount(packageCount);
      const packagesEl = badge.querySelector('[data-store-cart-badge-packages]');
      if (packagesEl) {
        packagesEl.textContent = label;
      } else {
        badge.textContent = label;
      }

      badge.classList.toggle('hidden', packageCount <= 0);
      badge.title = packageCount > 0 ? `${label} طرد` : '';
      badge.classList.add('is-updated');
      setTimeout(() => badge.classList.remove('is-updated'), 500);
    });

    document.querySelectorAll('[data-store-cart-open]').forEach((btn) => {
      btn.classList.toggle('is-cart-pulse', packageCount > 0);
    });
  };

  const flyToCart = (fromEl) => {
    const cartBtn = document.querySelector('[data-store-cart-open]');
    if (!fromEl || !cartBtn) return;

    const fromRect = fromEl.getBoundingClientRect();
    const toRect = cartBtn.getBoundingClientRect();
    if (fromRect.width <= 0 || toRect.width <= 0) return;

    const dot = document.createElement('span');
    dot.className = 'store-cart-fly-dot';
    dot.setAttribute('aria-hidden', 'true');
    const startX = fromRect.left + fromRect.width / 2;
    const startY = fromRect.top + fromRect.height / 2;
    const endX = toRect.left + toRect.width / 2;
    const endY = toRect.top + toRect.height / 2;
    dot.style.setProperty('--fly-x', `${endX - startX}px`);
    dot.style.setProperty('--fly-y', `${endY - startY}px`);
    dot.style.left = `${startX}px`;
    dot.style.top = `${startY}px`;
    document.body.appendChild(dot);

    requestAnimationFrame(() => {
      dot.classList.add('is-flying');
    });

    dot.addEventListener('animationend', () => dot.remove(), { once: true });
    cartBtn.classList.add('is-cart-bump');
    setTimeout(() => cartBtn.classList.remove('is-cart-bump'), 650);
  };

  const apiRequest = async (payload) => {
    const res = await fetch(API, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok && !data.message) {
      data.ok = false;
      data.message = 'تعذر تنفيذ العملية.';
      data.level = 'error';
    }
    return data;
  };

  const getMaxQty = (form) => {
    const effective = parseFloat(form.dataset.effectiveMax || '');
    if (Number.isFinite(effective) && effective >= 0) return effective;
    const max = parseFloat(form.dataset.maxQty || '');
    return Number.isFinite(max) && max > 0 ? max : null;
  };

  const getQtyStep = (form) => {
    const step = parseFloat(form.dataset.qtyStep || '1');
    return Number.isFinite(step) && step > 0 ? step : 1;
  };

  const getCurrentInCart = (form) => {
    return Math.max(0, parseFloat(form.dataset.cartQty || '0') || 0);
  };

  const getRequestedQty = (form) => {
    const input = form.querySelector('[name="quantity"]');
    const step = getQtyStep(form);
    const raw = Math.max(0, parseFloat(input?.value || String(step)) || step);
    if (step < 1) return Math.round(raw * 100) / 100;
    return Math.max(step, Math.round(raw));
  };

  const validateQty = (form, addQty, currentQty) => {
    const max = getMaxQty(form);
    if (max === null) return { ok: true, message: '' };
    const target = currentQty + addQty;
    if (target <= max + 0.0001) return { ok: true, message: '' };
    const maxLabel = form.dataset.maxQtyLabel || String(max);
    const fmt = (n) => {
      const step = getQtyStep(form);
      return step < 1
        ? n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
        : String(Math.floor(n));
    };
    if (currentQty > 0) {
      const remaining = Math.max(0, max - currentQty);
      return {
        ok: false,
        message: `الحد الأقصى ${maxLabel} طرد لهذه المادة. لديك ${fmt(currentQty)} في السلة${remaining > 0 ? ` — يمكنك إضافة ${fmt(remaining)} فقط.` : ' — لا يمكن إضافة المزيد.'}`,
      };
    }
    return { ok: false, message: `الحد الأقصى للطلب هو ${maxLabel} طرد لهذه المادة.` };
  };

  const getRemainingAdd = (form, inCartQty) => {
    const maxPackages = parseFloat(form.dataset.maxQty || '');
    if (Number.isFinite(maxPackages) && maxPackages > 0) {
      return Math.max(0, maxPackages - inCartQty);
    }
    const effective = parseFloat(form.dataset.effectiveMax || '');
    if (Number.isFinite(effective) && effective >= 0) {
      return effective;
    }
    return null;
  };

  const isPartialAddForm = (form) => form.dataset.partialPackage === '1';

  const updatePreviewPayload = (guid, inCartQty) => {
    const card = document.querySelector(`[data-preview-guid="${CSS.escape(guid)}"]`);
    if (!card) return;
    try {
      const payload = JSON.parse(card.getAttribute('data-preview') || '{}');
      payload.cartQty = inCartQty;
      const maxPackages = Number(payload.maxPackages);
      if (Number.isFinite(maxPackages) && maxPackages > 0) {
        const remaining = Math.max(0, maxPackages - inCartQty);
        payload.remaining = remaining;
        payload.effectiveMax = remaining;
        payload.atLimit = remaining <= 0;
      }
      card.setAttribute('data-preview', JSON.stringify(payload));
    } catch {
      // ignore invalid preview payload
    }
  };

  const setFormCartMode = (form, inCartQty) => {
    if (!form) return;
    const inCart = inCartQty > 0;
    const partialLocked = isPartialAddForm(form);
    const remaining = getRemainingAdd(form, inCartQty);
    const atLimit = remaining !== null && remaining <= 0;
    const lockedInCart = inCart && partialLocked;
    const canAdjust = inCart && !partialLocked;
    const qtyLabel = formatPackageCount(inCartQty);

    form.dataset.cartQty = String(inCartQty);
    form.dataset.cartMode = inCart
      ? (lockedInCart ? 'in-cart-locked' : 'in-cart')
      : (partialLocked ? 'partial-add' : 'add');
    if (remaining !== null) {
      form.dataset.effectiveMax = String(remaining);
    }

    const inCartEl = form.querySelector('.store-add-cart__in-cart');
    const addEl = form.querySelector('.store-add-cart__add');
    if (inCartEl) inCartEl.hidden = !inCart;
    if (addEl) addEl.hidden = inCart;
    form.classList.toggle('store-add-cart--in-cart', inCart);
    form.classList.toggle('store-add-cart--locked', lockedInCart);

    const qtyRoot = form.closest('.store-product-card') || form;
    qtyRoot.querySelectorAll('[data-cart-qty-display]').forEach((el) => {
      el.textContent = qtyLabel;
    });

    const lockedRow = form.querySelector('[data-cart-qty-locked]') || form.querySelector('.store-cart-panel__qty-locked');
    const adjustRow = form.querySelector('[data-cart-qty-adjust]');
    if (lockedRow) lockedRow.hidden = canAdjust;
    if (adjustRow) adjustRow.hidden = !canAdjust;

    const plus = adjustRow?.querySelector('[data-cart-bump="1"]') || form.querySelector('[data-cart-bump="1"]');
    const minus = adjustRow?.querySelector('[data-cart-bump="-1"]') || form.querySelector('[data-cart-bump="-1"]');
    const step = getQtyStep(form);
    if (plus) plus.disabled = !canAdjust || (remaining !== null && remaining <= 0);
    if (minus) minus.disabled = !canAdjust || inCartQty <= 0;

    const guid = form.dataset.materialGuid || form.querySelector('[name="material_guid"]')?.value || '';
    if (guid) updatePreviewPayload(guid, inCartQty);
  };

  const refreshCartForms = (data) => {
    const qtyMap = data?.cart_qty_by_guid || {};
    document.querySelectorAll('[data-store-add-cart]').forEach((form) => {
      syncFormLimits(form, qtyMap);
    });
    document.dispatchEvent(new CustomEvent('store-cart-updated', { detail: data }));
  };

  const syncFormLimits = (form, cartQtyByGuid) => {
    const guid = form.dataset.materialGuid || form.querySelector('[name="material_guid"]')?.value || '';
    if (!guid) return;
    const map = cartQtyByGuid && typeof cartQtyByGuid === 'object' ? cartQtyByGuid : {};
    const inCartQty = Math.max(0, Number(map[guid] ?? map[guid.toLowerCase()] ?? map[guid.toUpperCase()]) || 0);
    setFormCartMode(form, inCartQty);

    if (inCartQty > 0) {
      return;
    }

    const max = getMaxQty(form);
    const input = form.querySelector('[name="quantity"]');
    const hint = form.querySelector('.store-add-cart__add [data-qty-hint]');
    const minus = form.querySelector('[data-qty-minus]');
    const plus = form.querySelector('[data-qty-plus]');
    const step = getQtyStep(form);
    if (max !== null && input) {
      input.max = String(Math.max(step, max));
      if (plus) plus.disabled = max <= 0 || isPartialAddForm(form);
    }
    if (minus && input) {
      const val = parseFloat(input.value || String(step)) || step;
      minus.disabled = val <= step || isPartialAddForm(form);
    }
    if (hint && !isPartialAddForm(form) && max !== null) {
      hint.textContent = `الحد الأقصى ${form.dataset.maxQtyLabel || max} ${form.dataset.packageUnit || 'طرد'} لكل مادة`;
      hint.classList.toggle('is-warning', max <= 0);
    }
  };

  const bumpCartQty = async (form, delta) => {
    const guid = form.dataset.materialGuid || form.querySelector('[name="material_guid"]')?.value || '';
    if (!guid || form.dataset.ajaxSubmitting === '1') return;
    form.dataset.ajaxSubmitting = '1';
    const buttons = form.querySelectorAll('[data-cart-bump]');
    buttons.forEach((btn) => { btn.disabled = true; });
    try {
      const data = await apiRequest({ action: 'bump', material_guid: guid, delta });
      applyCartResponse(data);
      if (!data.ok && data.message) showToast(data.message, data.level || 'error');
    } catch {
      showToast('تعذر تحديث الكمية.', 'error');
    } finally {
      delete form.dataset.ajaxSubmitting;
      const refreshedQty = lastCartData?.cart_qty_by_guid?.[guid];
      const inCartQty = refreshedQty !== undefined
        ? Math.max(0, Number(refreshedQty) || 0)
        : getCurrentInCart(form);
      setFormCartMode(form, inCartQty);
    }
  };

  const bindQtySteppers = (root = document) => {
    root.querySelectorAll('[data-store-add-cart]').forEach((form) => {
      if (form.dataset.stepperBound === '1') return;
      form.dataset.stepperBound = '1';
      const input = form.querySelector('[name="quantity"]');
      const minus = form.querySelector('[data-qty-minus]');
      const plus = form.querySelector('[data-qty-plus]');
      const refresh = () => {
        if (form.dataset.cartMode?.startsWith('in-cart')) return;
        const max = getMaxQty(form);
        const current = getCurrentInCart(form);
        const step = getQtyStep(form);
        const val = parseFloat(input?.value || String(step)) || step;
        if (minus) minus.disabled = val <= step || isPartialAddForm(form);
        if (plus && max !== null) {
          const remaining = Math.max(0, max - current);
          plus.disabled = val >= remaining - 0.0001 || isPartialAddForm(form);
        }
      };
      minus?.addEventListener('click', () => {
        if (!input || isPartialAddForm(form)) return;
        const step = getQtyStep(form);
        const val = parseFloat(input.value || String(step)) || step;
        const next = Math.max(step, step < 1 ? Math.round((val - step) * 100) / 100 : val - step);
        input.value = String(next);
        refresh();
      });
      plus?.addEventListener('click', () => {
        if (!input || isPartialAddForm(form)) return;
        const step = getQtyStep(form);
        const val = parseFloat(input.value || String(step)) || step;
        const next = step < 1 ? Math.round((val + step) * 100) / 100 : val + step;
        const check = validateQty(form, step, getCurrentInCart(form));
        if (!check.ok && next > val) {
          showToast(check.message, 'error');
          return;
        }
        input.value = String(next);
        refresh();
      });
      input?.addEventListener('input', refresh);
      refresh();

      form.querySelectorAll('[data-cart-bump]').forEach((btn) => {
        if (btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', () => {
          const sign = parseInt(btn.getAttribute('data-cart-bump') || '0', 10);
          if (!sign) return;
          const step = getQtyStep(form);
          const delta = sign > 0 ? step : -step;
          bumpCartQty(form, delta);
        });
      });
    });
  };

  const submitAddCartForm = async (form) => {
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-store-add-cart')) {
      return;
    }
    if (form.dataset.ajaxSubmitting === '1') {
      return;
    }
    form.dataset.ajaxSubmitting = '1';
    const btn = form.querySelector('[type="submit"]');
    const addQty = getRequestedQty(form);
    const currentQty = getCurrentInCart(form);
    const check = validateQty(form, addQty, currentQty);
    if (!check.ok) {
      showToast(check.message, 'error');
      delete form.dataset.ajaxSubmitting;
      return;
    }
    btn?.classList.add('is-loading');
    const formData = new FormData(form);
    const payload = { action: 'add' };
    formData.forEach((value, key) => {
      if (key === 'action') return;
      payload[key] = value;
    });
    try {
      const data = await apiRequest(payload);
      applyCartResponse(data);
      if (!data.ok) return;
      const flySource = btn || form.querySelector('.store-add-cart__submit') || form;
      flyToCart(flySource);
      if (window.SiteAnalytics) {
        window.SiteAnalytics.track('add_to_cart', {
          product_guid: String(payload.material_guid || form.dataset.materialGuid || ''),
          product_code: String(payload.material_code || ''),
          product_name: String(payload.material_name_ar || ''),
          quantity: String(payload.quantity || '1'),
          label_ar: `إضافة للسلة: ${String(payload.material_name_ar || 'صنف')}`,
        });
      }
      if (inputReset(form)) {
        const step = getQtyStep(form);
        form.querySelector('[name="quantity"]').value = String(step);
      }
    } catch {
      showToast('تعذر الاتصال بالخادم.', 'error');
    } finally {
      btn?.classList.remove('is-loading');
      delete form.dataset.ajaxSubmitting;
    }
  };

  const bindAddForms = () => {
    bindQtySteppers();
  };

  if (!window.__storeCartSubmitCaptureBound) {
    window.__storeCartSubmitCaptureBound = true;
    document.addEventListener('submit', (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-store-add-cart')) {
        return;
      }
      event.preventDefault();
      submitAddCartForm(form);
    }, true);
  }

  const inputReset = (form) => form.querySelector('[name="quantity"]');

  const renderUnavailableSection = (lines) => {
    if (!lines.length) return '';
    let html = `<section class="store-cart-unavailable" data-cart-unavailable>
      <div class="store-cart-unavailable__head">
        <div>
          <h3 class="font-bold text-red-800">غير متوفرة للطلب</h3>
          <p class="text-xs text-red-700 mt-0.5">هذه الأصناف لن تُرسل مع الطلب. قد يكون السبب حجز كميتها لطلبات أخرى قيد المعالجة.</p>
        </div>
        <button type="button" class="text-xs font-bold text-red-700 hover:underline" data-clear-unavailable>إزالة الكل</button>
      </div>
      <div class="store-cart-unavailable__list">`;
    lines.forEach((line) => {
      const guid = String(line.material_guid || '');
      const packageUnit = String(line.package_unit || 'طرد');
      const qty = Math.max(1, Math.round(Number(line.quantity) || 1));
      const image = line.image_url
        ? `<img src="${escapeHtml(line.image_url)}" alt="" class="w-14 h-14 rounded-lg object-cover bg-gray-100 shrink-0 opacity-70" loading="lazy">`
        : '';
      html += `<div class="store-cart-unavailable__item" data-unavailable-guid="${escapeHtml(guid)}">
        <div class="flex items-center gap-3 min-w-0">
          ${image}
          <div class="min-w-0">
            <div class="font-bold text-sm text-gray-800">${escapeHtml(line.material_name_ar || '')}</div>
            <div class="text-xs text-red-700 mt-1">${escapeHtml(line.stock_message || 'نفدت الكمية المتاحة.')}</div>
            <div class="text-xs text-gray-500 mt-1">الكمية المطلوبة: ${qty} ${escapeHtml(packageUnit)}</div>
          </div>
        </div>
        <button type="button" class="text-xs font-bold text-gray-600 hover:text-red-600 shrink-0" data-remove-unavailable="${escapeHtml(guid)}">إزالة</button>
      </div>`;
    });
    html += '</div></section>';
    return html;
  };

  const bindUnavailableControls = (root) => {
    root.querySelectorAll('[data-remove-unavailable]').forEach((btn) => {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', async () => {
        const guid = btn.getAttribute('data-remove-unavailable') || '';
        if (!guid) return;
        const data = await apiRequest({ action: 'remove_unavailable', material_guid: guid });
        applyCartResponse(data);
      });
    });
    root.querySelectorAll('[data-clear-unavailable]').forEach((btn) => {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', async () => {
        if (!window.confirm('إزالة جميع الأصناف غير المتوفرة؟')) return;
        const data = await apiRequest({ action: 'clear_unavailable' });
        applyCartResponse(data);
      });
    });
  };

  const renderCartRoot = (root, data) => {
    if (!root) return;

    const isDrawer = root.dataset.storeCartPage === 'drawer';
    const showPrice = !!data.show_price;
    const max = data.max_packages_per_material;
    const maxLabel = data.max_packages_label || max;
    const loadingEl = root.querySelector('[data-cart-drawer-loading]');
    if (loadingEl) {
      loadingEl.hidden = true;
    }
    root.classList.remove('is-loading-cart');

    const noticeEl = root.querySelector('[data-cart-notice]');
    const errorEl = root.querySelector('[data-cart-error]');
    const stockEl = root.querySelector('[data-cart-stock-notices]');
    const bodyEl = root.querySelector('[data-cart-body]');
    const summaryEl = root.querySelector('[data-cart-summary]');

    if (errorEl) {
      errorEl.classList.add('hidden');
      errorEl.textContent = '';
    }
    if (noticeEl) {
      noticeEl.classList.add('hidden');
      noticeEl.textContent = '';
    }
    if (stockEl) {
      stockEl.classList.add('hidden');
      stockEl.innerHTML = '';
    }

    updateBadge(data);

    const items = Array.isArray(data.items) ? data.items : [];
    const unavailable = Array.isArray(data.unavailable) ? data.unavailable : [];
    if (items.length === 0 && unavailable.length === 0 && isDrawer) {
      closeCheckoutSheet(root);
    }

    if (bodyEl) {
      if (items.length === 0 && unavailable.length === 0) {
        bodyEl.innerHTML = `
          <div class="store-cart-empty${isDrawer ? '' : ' lg:col-span-8'}">
            <span class="material-symbols-outlined text-5xl text-gray-300" aria-hidden="true">shopping_cart</span>
            <p class="text-gray-500 mt-3">السلة فارغة.</p>
            ${isDrawer
              ? '<button type="button" class="store-btn store-btn--primary mt-4" data-store-cart-drawer-close>تصفح المتجر</button>'
              : '<a href="/store.php" class="store-btn store-btn--primary mt-4">تصفح المتجر</a>'}
          </div>`;
      } else {
        let html = isDrawer ? '<div class="space-y-4">' : '<div class="lg:col-span-8 space-y-4">';
        if (max) {
          html += `<p class="store-limit-banner">الحد الأقصى للطلب: <strong>${escapeHtml(String(maxLabel))}</strong> طرد لكل مادة.</p>`;
        }
        const priceChanges = Array.isArray(data.price_changes) ? data.price_changes : [];
        if (priceChanges.length > 0) {
          html += `<div class="store-price-change-banner" role="status">
            <span class="material-symbols-outlined store-price-change-banner__icon" aria-hidden="true">price_change</span>
            <div>
              <strong>تغيّرت أسعار ${priceChanges.length} صنف</strong>
              <span>راجع الأسعار المحدّثة قبل إرسال الطلب.</span>
            </div>
          </div>`;
        }
        if (items.length > 0) {
          const partition = partitionCartItems(items);
          const hasMixed = data.has_mixed_pricing === true || partition.hasMixed;
          if (partition.priced.length > 0) {
            html += renderCartSection(
              partition.priced,
              'store-cart-section--priced',
              'sell',
              'أصناف بسعر محدد',
              'الأسعار المعروضة قابلة للتحديث حتى إرسال الطلب.',
              max,
              hasMixed
            );
          }
          if (partition.unpriced.length > 0) {
            html += renderCartSection(
              partition.unpriced,
              'store-cart-section--unpriced',
              'receipt_long',
              'يُسعّر عند التأكيد',
              'سيُحدد سعر هذه الأصناف عند مراجعة الطلب.',
              max,
              hasMixed || partition.unpriced.length > 0
            );
          }
        }
        if (unavailable.length > 0) {
          html += renderUnavailableSection(unavailable);
        }
        html += '</div>';
        bodyEl.innerHTML = html;
        hydrateCartLineImages(bodyEl);
        bindCartLineControls(bodyEl, max);
        bindUnavailableControls(bodyEl);
        bindImageZoom(bodyEl);
      }
    }

    if (summaryEl) {
      const totals = data.display_totals || data.totals || {};
      const allowOrder = !!data.allow_order;
      const isLoggedIn = root.dataset.loggedIn === '1' || !!data.logged_in;
      const totalSp = Number(totals.total_sp) || 0;
      const totalUsd = Number(totals.total_usd) || 0;
      const unpricedCount = Number(data.unpriced_items_count) || partitionCartItems(items).unpriced.length;
      const totalLine = showPrice && (totalSp > 0 || totalUsd > 0)
        ? `<div class="store-cart-summary__totals">
            ${totalSp > 0 ? `<div class="store-cart-summary__total store-price-currency store-price-currency--syp">الإجمالي: ${formatMoney(totalSp)} ل.س</div>` : ''}
            ${totalUsd > 0 ? `<div class="store-cart-summary__total store-price-currency store-price-currency--usd">الإجمالي: $${formatUsd(totalUsd)}</div>` : ''}
          </div>`
        : '';
      const unpricedNote = showPrice && unpricedCount > 0
        ? `<p class="store-cart-summary__unpriced-note">${unpricedCount} ${unpricedCount === 1 ? 'صنف' : 'أصناف'} بدون سعر محدد — يُسعّر عند التأكيد</p>`
        : '';

      if (isDrawer) {
        summaryEl.innerHTML = `<div class="store-panel store-cart-summary store-cart-summary--drawer space-y-3">
          ${totalLine}
          ${unpricedNote}
          ${items.length > 0 ? `
            ${allowOrder ? '<button type="button" class="store-btn store-btn--primary w-full" data-cart-checkout-open>متابعة الطلب</button>' : '<p class="text-sm text-amber-800">سياسة المتجر لا تسمح بإرسال الطلبات حالياً.</p>'}
            <button type="button" class="store-btn store-btn--ghost w-full" data-clear-cart>تفريغ السلة</button>
          ` : ''}
        </div>`;
      } else {
        summaryEl.innerHTML = `<div class="store-panel store-cart-summary space-y-4">
          ${totalLine}
          ${unpricedNote}
          ${items.length > 0 ? '<button type="button" class="store-btn store-btn--ghost" data-clear-cart>تفريغ السلة</button>' : ''}
          ${allowOrder && items.length > 0 ? `
            <form data-checkout-form class="space-y-3 border-t border-gray-100 pt-4">
              ${isLoggedIn ? `
                <p class="text-sm text-gray-600 rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
                  إرسال الطلب بحسابك المسجّل — بياناتك مأخوذة من ملفك ولا يمكن تغييرها هنا.
                </p>
              ` : `
                <label class="block text-sm font-bold">الاسم الكامل *
                  <input name="guest_name_ar" required class="store-input mt-1" value="${escapeHtml(root.dataset.defaultName || '')}">
                </label>
                <label class="block text-sm font-bold">رقم الهاتف *
                  <input name="guest_phone" required dir="ltr" class="store-input mt-1 text-left" value="${escapeHtml(root.dataset.defaultPhone || '')}">
                </label>
              `}
              <label class="block text-sm font-bold">ملاحظات
                <textarea name="notes_ar" rows="3" class="store-input mt-1 h-auto py-2 text-sm"></textarea>
              </label>
              <button type="submit" class="store-btn store-btn--primary w-full">تأكيد وإرسال الطلب</button>
            </form>
          ` : !allowOrder && items.length > 0 ? '<p class="text-sm text-amber-800">سياسة المتجر لا تسمح بإرسال الطلبات حالياً.</p>' : ''}
        </div>`;
        bindCheckout(summaryEl);
      }

      bindClearCart(summaryEl);
      if (isDrawer) {
        bindCheckoutOpen(summaryEl, root, data);
      }
    }

    if (Array.isArray(data.stock_notices) && data.stock_notices.length > 0 && stockEl) {
      const uniqueNotices = [...new Set(data.stock_notices.map((n) => String(n || '').trim()).filter(Boolean))];
      if (uniqueNotices.length > 0) {
        stockEl.classList.remove('hidden');
        stockEl.innerHTML = `<div class="rounded-xl border bg-amber-50 border-amber-200 text-amber-900 px-4 py-3 text-sm"><p class="font-bold mb-1">تنبيه المخزون</p>${uniqueNotices.map((n) => `<p>${escapeHtml(n)}</p>`).join('')}</div>`;
      }
    }
  };

  const renderCartPage = (data) => {
    const root = document.querySelector('[data-store-cart-page="1"]');
    renderCartRoot(root, data);
  };

  const bindClearCart = (root) => {
    root.querySelectorAll('[data-clear-cart]').forEach((btn) => {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', async () => {
        if (!window.confirm('تفريغ السلة بالكامل؟')) return;
        const data = await apiRequest({ action: 'clear' });
        applyCartResponse(data);
      });
    });
  };

  const bindCartLineControls = (root, maxPackages) => {
    root.querySelectorAll('[data-bump]').forEach((btn) => {
      if (btn.dataset.bumpBound === '1') return;
      btn.dataset.bumpBound = '1';
      btn.addEventListener('click', async () => {
        const wrap = btn.closest('[data-cart-qty-control]');
        const guid = wrap?.dataset.guid || '';
        const delta = parseInt(btn.dataset.bump || '0', 10);
        if (!guid || !delta) return;
        const input = wrap?.querySelector('[data-qty-input]');
        const current = parseFloat(input?.value || '1') || 1;
        if (delta > 0 && maxPackages !== null && maxPackages !== undefined) {
          const max = parseFloat(String(maxPackages));
          if (Number.isFinite(max) && current + delta > max + 0.0001) {
            const maxLabel = String(maxPackages);
            showToast(`الحد الأقصى للطلب هو ${maxLabel} طرد لهذه المادة.`, 'error');
            return;
          }
        }
        const data = await apiRequest({ action: 'bump', material_guid: guid, delta });
        applyCartResponse(data);
      });
    });
    root.querySelectorAll('[data-qty-input]').forEach((input) => {
      if (input.dataset.qtyBound === '1') return;
      input.dataset.qtyBound = '1';
      let timer;
      input.addEventListener('change', () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
          const wrap = input.closest('[data-cart-qty-control]');
          const guid = wrap?.dataset.guid || '';
          const qty = Math.max(0.01, parseFloat(input.value) || 0.01);
          if (!guid) return;
          const data = await apiRequest({ action: 'update', material_guid: guid, quantity: qty });
          applyCartResponse(data);
        }, 300);
      });
    });
    root.querySelectorAll('[data-remove-item]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const guid = btn.dataset.removeItem || '';
        if (!guid) return;
        const data = await apiRequest({ action: 'remove', material_guid: guid });
        applyCartResponse(data);
      });
    });
  };

  const buildCheckoutFormHtml = (root, data) => {
    const isLoggedIn = root.dataset.loggedIn === '1' || !!data.logged_in;
    return `
      <p class="store-cart-checkout__lead">أدخل بيانات التواصل ثم أكّد إرسال الطلب.</p>
      <form data-checkout-form class="store-cart-checkout__form space-y-3">
        ${isLoggedIn ? `
          <p class="text-sm text-gray-600 rounded-lg bg-gray-50 border border-gray-100 px-3 py-2">
            إرسال الطلب بحسابك المسجّل — بياناتك مأخوذة من ملفك ولا يمكن تغييرها هنا.
          </p>
        ` : `
          <label class="block text-sm font-bold">الاسم الكامل *
            <input name="guest_name_ar" required class="store-input mt-1" value="${escapeHtml(root.dataset.defaultName || '')}">
          </label>
          <label class="block text-sm font-bold">رقم الهاتف *
            <input name="guest_phone" required dir="ltr" class="store-input mt-1 text-left" value="${escapeHtml(root.dataset.defaultPhone || '')}">
          </label>
        `}
        <label class="block text-sm font-bold">ملاحظات
          <textarea name="notes_ar" rows="3" class="store-input mt-1 h-auto py-2 text-sm" placeholder="اختياري"></textarea>
        </label>
        <button type="submit" class="store-btn store-btn--primary w-full">تأكيد وإرسال الطلب</button>
      </form>
    `;
  };

  const closeCheckoutSheet = (root) => {
    const sheet = root?.querySelector('[data-cart-checkout-sheet]');
    if (!sheet) return;
    if (document.activeElement instanceof HTMLElement && sheet.contains(document.activeElement)) {
      document.activeElement.blur();
    }
    sheet.hidden = true;
    sheet.setAttribute('aria-hidden', 'true');
    root.classList.remove('is-checkout-open');
  };

  const openCheckoutSheet = (root, data) => {
    const sheet = root.querySelector('[data-cart-checkout-sheet]');
    const body = root.querySelector('[data-cart-checkout-body]');
    if (!sheet || !body) return;
    body.innerHTML = buildCheckoutFormHtml(root, data);
    bindCheckout(body);
    sheet.hidden = false;
    sheet.setAttribute('aria-hidden', 'false');
    root.classList.add('is-checkout-open');
    const firstField = body.querySelector('input, textarea, button');
    if (firstField instanceof HTMLElement) {
      firstField.focus();
    }
  };

  const bindCheckoutOpen = (summaryEl, root, data) => {
    summaryEl.querySelectorAll('[data-cart-checkout-open]').forEach((btn) => {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => openCheckoutSheet(root, lastCartData || data));
    });

    const sheet = root.querySelector('[data-cart-checkout-sheet]');
    if (!sheet || sheet.dataset.bound === '1') return;
    sheet.dataset.bound = '1';
    sheet.querySelectorAll('[data-cart-checkout-close]').forEach((btn) => {
      btn.addEventListener('click', () => closeCheckoutSheet(root));
    });
  };

  const bindCheckout = (root) => {
    const form = root.querySelector('[data-checkout-form]');
    if (!form || form.dataset.bound === '1') return;
    form.dataset.bound = '1';
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const btn = form.querySelector('[type="submit"]');
      btn?.classList.add('is-loading');
      const payload = { action: 'submit_order' };
      new FormData(form).forEach((v, k) => { payload[k] = v; });
      const submitOnce = async (confirmPriceChanges = false) => {
        if (confirmPriceChanges) payload.confirm_price_changes = '1';
        else delete payload.confirm_price_changes;
        return apiRequest(payload);
      };
      try {
        let data = await submitOnce(false);
        if (data.requires_price_confirmation) {
          const confirmed = window.confirm(buildPriceChangeConfirmMessage(data.price_changes));
          if (!confirmed) {
            applyCartResponse(data);
            return;
          }
          data = await submitOnce(true);
        }
        if (data.message) showToast(data.message, data.level || (data.ok ? 'success' : 'error'));
        if (data.ok && data.redirect) {
          window.location.href = data.redirect;
          return;
        }
        if (!data.ok) applyCartResponse(data);
      } catch {
        showToast('تعذر إرسال الطلب.', 'error');
      } finally {
        btn?.classList.remove('is-loading');
      }
    });
  };

  const applyCartResponse = (data, options = {}) => {
    const remote = options.remote === true;
    const silent = options.silent === true;
    if (!data || typeof data !== 'object') return;
    lastCartData = data;
    updateBadge(data);
    if (data.cart_qty_by_guid) {
      refreshCartForms(data);
    }

    const pageRoot = document.querySelector('[data-store-cart-page="1"]');
    if (pageRoot) {
      renderCartRoot(pageRoot, data);
    }

    const drawer = cartDrawer();
    const drawerRoot = drawer?.querySelector('[data-store-cart-drawer-root]');
    if (drawerRoot && drawer.classList.contains('is-open')) {
      renderCartRoot(drawerRoot, data);
    } else if (drawerRoot && lastCartData) {
      // Keep drawer content warm for the next open without flashing a reload.
      renderCartRoot(drawerRoot, data);
    }

    if (!silent && data.message) showToast(data.message, data.level || (data.ok ? 'success' : 'error'));
    if (!remote && data.cart_qty_by_guid) {
      publishCartSync(data);
    }
  };

  const cartDrawer = () => document.getElementById('store-cart-drawer');

  const guardDrawerGhostClick = (event) => {
    if (Date.now() >= drawerCloseGuardUntil) return;
    event.preventDefault();
    event.stopPropagation();
    if (typeof event.stopImmediatePropagation === 'function') {
      event.stopImmediatePropagation();
    }
  };

  const closeCartDrawer = (event) => {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    setCartDrawerOpen(false);
  };

  const setCartDrawerOpen = (open) => {
    const drawer = cartDrawer();
    if (!drawer) return;

    if (drawerCloseTimer) {
      window.clearTimeout(drawerCloseTimer);
      drawerCloseTimer = null;
    }

    if (open) {
      drawer.hidden = false;
      drawerCloseGuardUntil = 0;
      requestAnimationFrame(() => {
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('store-cart-drawer-open');
        document.querySelectorAll('[data-store-cart-open]').forEach((btn) => {
          btn.setAttribute('aria-expanded', 'true');
        });
      });
      return;
    }

    const root = drawer.querySelector('[data-store-cart-drawer-root]');
    if (root) closeCheckoutSheet(root);
    if (document.activeElement instanceof HTMLElement && drawer.contains(document.activeElement)) {
      document.activeElement.blur();
    }

    drawer.classList.remove('is-open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('store-cart-drawer-open');
    document.querySelectorAll('[data-store-cart-open]').forEach((btn) => {
      btn.setAttribute('aria-expanded', 'false');
    });
    drawerCloseGuardUntil = Date.now() + DRAWER_CLOSE_MS + 80;
    drawerCloseTimer = window.setTimeout(() => {
      drawerCloseTimer = null;
      if (!drawer.classList.contains('is-open')) {
        drawer.hidden = true;
      }
    }, DRAWER_CLOSE_MS);
  };

  const loadCartDrawer = async ({ force = false } = {}) => {
    const drawer = cartDrawer();
    const root = drawer?.querySelector('[data-store-cart-drawer-root]');
    if (!root) return;

    const loadingEl = root.querySelector('[data-cart-drawer-loading]');
    const bodyEl = root.querySelector('[data-cart-body]');
    const summaryEl = root.querySelector('[data-cart-summary]');
    const hasCached = !force
      && lastCartData
      && typeof lastCartData === 'object'
      && Array.isArray(lastCartData.items);

    if (hasCached) {
      if (loadingEl) loadingEl.hidden = true;
      root.classList.remove('is-loading-cart');
      renderCartRoot(root, lastCartData);
      refreshCartFromServer({ silent: true });
      return;
    }

    if (loadingEl) loadingEl.hidden = false;
    root.classList.add('is-loading-cart');
    if (bodyEl) bodyEl.innerHTML = '';
    if (summaryEl) summaryEl.innerHTML = '';

    try {
      const res = await fetch(`${API}?reconcile=1`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const data = await res.json();
      lastCartData = data;
      renderCartRoot(root, data);
    } catch {
      showToast('تعذر تحميل السلة.', 'error');
      if (loadingEl) loadingEl.hidden = true;
      root.classList.remove('is-loading-cart');
    }
  };

  const bindCartDrawer = () => {
    const drawer = cartDrawer();
    if (!drawer || drawer.dataset.bound === '1') return;
    drawer.dataset.bound = '1';

    if (!window.__storeCartDrawerGhostGuard) {
      window.__storeCartDrawerGhostGuard = true;
      document.addEventListener('click', guardDrawerGhostClick, true);
      document.addEventListener('touchend', guardDrawerGhostClick, true);
    }

    document.querySelectorAll('[data-store-cart-open]').forEach((btn) => {
      if (btn.dataset.cartOpenBound === '1') return;
      btn.dataset.cartOpenBound = '1';
      btn.addEventListener('click', async (event) => {
        event.preventDefault();
        setCartDrawerOpen(true);
        await loadCartDrawer();
      });
    });

    drawer.addEventListener('click', (event) => {
      if (!(event.target instanceof Element)) return;
      if (!event.target.closest('[data-store-cart-drawer-close]')) return;
      closeCartDrawer(event);
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && drawer.classList.contains('is-open')) {
        closeCartDrawer(event);
      }
    });
  };

  const initCartPage = async () => {
    const root = document.querySelector('[data-store-cart-page="1"]');
    if (!root) return;
    try {
      const res = await fetch(`${API}?reconcile=1`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const data = await res.json();
      renderCartPage(data);
    } catch {
      showToast('تعذر تحميل السلة.', 'error');
    }
  };

  const init = async () => {
    initCartCrossTabSync();
    bindAddForms();
    bindCartDrawer();
    const page = document.querySelector('[data-store-cart-page="1"]');
    if (page) {
      bindCartLineControls(page, null);
      bindUnavailableControls(page);
      bindClearCart(page);
      bindCheckout(page);
      bindImageZoom(page);
    }
    initCartPage();
    try {
      const res = await fetch(API, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const data = await res.json();
      if (data?.cart_qty_by_guid) {
        refreshCartForms(data);
        updateBadge(data);
      }
    } catch {
      document.querySelectorAll('[data-store-add-cart]').forEach((form) => {
        setFormCartMode(form, getCurrentInCart(form));
      });
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.StoreCart = {
    showToast,
    updateBadge,
    applyCartResponse,
    apiRequest,
    bindAddForms,
    bindQtySteppers,
    refreshCartForms,
    setFormCartMode,
    openDrawer: () => { setCartDrawerOpen(true); return loadCartDrawer(); },
    closeDrawer: closeCartDrawer,
  };
})();
