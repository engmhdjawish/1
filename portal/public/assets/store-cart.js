(() => {
  const API = '/api/store-cart.php';

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
    const text = String(url || '');
    if (!text) return '';
    if (text.includes('thumb=1')) return text.replace('thumb=1', 'thumb=0');
    return text.includes('?') ? `${text}&thumb=0` : `${text}?thumb=0`;
  };

  const bindImageZoom = (root = document) => {
    const lightbox = document.getElementById('storeImageLightbox');
    const lightboxImg = document.getElementById('storeImageLightboxImg');
    const lightboxCaption = document.getElementById('storeImageLightboxCaption');
    if (!lightbox || !lightboxImg) return;

    const close = () => {
      lightbox.hidden = true;
      lightbox.setAttribute('aria-hidden', 'true');
      lightboxImg.src = '';
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
        const src = btn.getAttribute('data-cart-image-zoom') || '';
        if (!src) return;
        const name = btn.closest('.store-order-line-card, .store-cart-product, .store-cart-line-card')
          ?.querySelector('.store-order-line-card__title, .font-bold')?.textContent?.trim() || '';
        lightboxImg.src = src;
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

  const linePricesHtml = (line, showPriceSyp, showPriceUsd) => {
    const prices = computeLinePrices(line);
    const hasOffer = lineHasOffer(line);
    if (showPriceSyp && (prices.packSp > 0 || prices.origPackSp > 0)) {
      let html = '<div class="store-order-line-prices store-order-line-prices--compact">';
      html += `<div class="store-order-line-prices__row store-order-line-prices__row--main">
        <span class="store-order-line-prices__label">${escapeHtml(prices.packageUnit)}</span>
        <div class="store-order-line-prices__values">
          ${hasOffer && prices.origPackSp > prices.packSp ? `<span class="store-order-line-prices__old store-num" dir="ltr">${formatMoney(prices.origPackSp)}</span>` : ''}
          <span class="store-order-line-prices__amount store-num" dir="ltr">${formatMoney(prices.packSp)} <small>ل.س</small></span>
        </div>
      </div>`;
      if (prices.unitSp > 0) {
        html += `<div class="store-order-line-prices__row">
          <span class="store-order-line-prices__label">${escapeHtml(prices.primaryUnit)}</span>
          <div class="store-order-line-prices__values">
            ${hasOffer && prices.origUnitSp > prices.unitSp ? `<span class="store-order-line-prices__old store-num" dir="ltr">${formatMoney(prices.origUnitSp)}</span>` : ''}
            <span class="store-order-line-prices__amount store-order-line-prices__amount--unit store-num" dir="ltr">${formatMoney(prices.unitSp)} <small>ل.س</small></span>
          </div>
        </div>`;
      }
      html += '</div>';
      return html;
    }
    if (showPriceUsd && (prices.packUsd > 0 || prices.origPackUsd > 0)) {
      let html = '<div class="store-order-line-prices store-order-line-prices--compact">';
      html += `<div class="store-order-line-prices__row store-order-line-prices__row--main">
        <span class="store-order-line-prices__label">${escapeHtml(prices.packageUnit)}</span>
        <div class="store-order-line-prices__values">
          ${hasOffer && prices.origPackUsd > prices.packUsd ? `<span class="store-order-line-prices__old store-num" dir="ltr">$${formatUsd(prices.origPackUsd)}</span>` : ''}
          <span class="store-order-line-prices__amount store-num" dir="ltr">$${formatUsd(prices.packUsd)}</span>
        </div>
      </div>`;
      if (prices.unitUsd > 0) {
        html += `<div class="store-order-line-prices__row">
          <span class="store-order-line-prices__label">${escapeHtml(prices.primaryUnit)}</span>
          <div class="store-order-line-prices__values">
            ${hasOffer && prices.origUnitUsd > prices.unitUsd ? `<span class="store-order-line-prices__old store-num" dir="ltr">$${formatUsd(prices.origUnitUsd)}</span>` : ''}
            <span class="store-order-line-prices__amount store-order-line-prices__amount--unit store-num" dir="ltr">$${formatUsd(prices.unitUsd)}</span>
          </div>
        </div>`;
      }
      html += '</div>';
      return html;
    }
    return '';
  };

  const renderCartLineCard = (line, showPriceSyp, showPriceUsd, max) => {
    const guid = line.material_guid || '';
    const prices = computeLinePrices(line);
    const hasOffer = lineHasOffer(line);
    const img = line.image_url
      ? (() => {
          const thumb = escapeHtml(line.image_url);
          const zoom = escapeHtml(imageZoomUrl(line.image_url));
          return `<button type="button" class="store-order-line-card__thumb" data-cart-image-zoom="${zoom}" title="تكبير الصورة للتدقيق"><img src="${thumb}" alt="" loading="lazy"><span class="store-order-line-card__zoom-icon material-symbols-outlined" aria-hidden="true">zoom_in</span></button>`;
        })()
      : '<div class="store-order-line-card__placeholder"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span></div>';
    const lineTotalCell = showPriceSyp
      ? `${formatMoney(prices.lineTotalSp)} ل.س`
      : showPriceUsd
        ? `$${formatUsd(prices.lineTotalUsd)}`
        : '';
    return `<article class="store-order-line-card store-cart-line-card${hasOffer ? ' store-order-line-card--offer' : ''}" data-cart-line="${escapeHtml(guid)}">
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
          ${showPriceSyp || showPriceUsd ? linePricesHtml(line, showPriceSyp, showPriceUsd) : ''}
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

  const updateBadge = (count) => {
    document.querySelectorAll('[data-store-cart-badge]').forEach((badge) => {
      const n = Math.max(0, parseInt(count, 10) || 0);
      badge.textContent = String(n);
      badge.classList.toggle('hidden', n <= 0);
      badge.classList.add('is-updated');
      setTimeout(() => badge.classList.remove('is-updated'), 500);
    });
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

  const syncFormLimits = (form, cartQtyByGuid) => {
    const guid = form.dataset.materialGuid || form.querySelector('[name="material_guid"]')?.value || '';
    if (!guid) return;
    const inCart = cartQtyByGuid[guid] ?? 0;
    form.dataset.cartQty = String(inCart);
    const max = getMaxQty(form);
    const input = form.querySelector('[name="quantity"]');
    const hint = form.querySelector('[data-qty-hint]');
    const minus = form.querySelector('[data-qty-minus]');
    const plus = form.querySelector('[data-qty-plus]');
    const step = getQtyStep(form);
    if (max !== null && input) {
      const remaining = Math.max(0, max - inCart);
      input.max = String(Math.max(step, remaining));
      const currentVal = parseFloat(input.value || String(step)) || step;
      if (currentVal > remaining && remaining > 0) {
        input.value = step < 1 ? String(Math.round(remaining * 100) / 100) : String(Math.max(step, Math.floor(remaining)));
      }
      if (hint && !hint.textContent.includes('أقل من طرد')) {
        if (remaining <= 0) {
          hint.textContent = `وصلت للحد الأقصى (${form.dataset.maxQtyLabel || max} طرد)`;
          hint.classList.add('is-warning');
        } else {
          const remainingLabel = step < 1
            ? remaining.toLocaleString('en-US', { maximumFractionDigits: 2 })
            : String(Math.floor(remaining));
          hint.textContent = `الحد الأقصى ${form.dataset.maxQtyLabel || max} طرد — متبقي ${remainingLabel}`;
          hint.classList.remove('is-warning');
        }
      }
      if (plus) plus.disabled = remaining <= 0;
    }
    if (minus && input) {
      const val = parseFloat(input.value || String(step)) || step;
      minus.disabled = val <= step;
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
        const max = getMaxQty(form);
        const current = getCurrentInCart(form);
        const step = getQtyStep(form);
        const val = parseFloat(input?.value || String(step)) || step;
        if (minus) minus.disabled = val <= step;
        if (plus && max !== null) {
          const remaining = Math.max(0, max - current);
          plus.disabled = val >= remaining - 0.0001;
        }
      };
      minus?.addEventListener('click', () => {
        if (!input) return;
        const step = getQtyStep(form);
        const val = parseFloat(input.value || String(step)) || step;
        const next = Math.max(step, step < 1 ? Math.round((val - step) * 100) / 100 : val - step);
        input.value = String(next);
        refresh();
      });
      plus?.addEventListener('click', () => {
        if (!input) return;
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
    });
  };

  const bindAddForms = () => {
    document.querySelectorAll('[data-store-add-cart]').forEach((form) => {
      if (form.dataset.ajaxBound === '1') return;
      form.dataset.ajaxBound = '1';
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const btn = form.querySelector('[type="submit"]');
        const addQty = getRequestedQty(form);
        const currentQty = getCurrentInCart(form);
        const check = validateQty(form, addQty, currentQty);
        if (!check.ok) {
          showToast(check.message, 'error');
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
          if (data.cart_qty_by_guid) {
            document.querySelectorAll('[data-store-add-cart]').forEach((f) => syncFormLimits(f, data.cart_qty_by_guid));
          }
          updateBadge(data.cart_count);
          if (data.message) showToast(data.message, data.level || (data.ok ? 'success' : 'error'));
          if (!data.ok) return;
          if (inputReset(form)) {
            const step = getQtyStep(form);
            form.querySelector('[name="quantity"]').value = String(step);
          }
        } catch {
          showToast('تعذر الاتصال بالخادم.', 'error');
        } finally {
          btn?.classList.remove('is-loading');
        }
      });
    });
    bindQtySteppers();
  };

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

  const renderCartPage = (data) => {
    const root = document.querySelector('[data-store-cart-page]');
    if (!root) return;

    const showPrice = !!data.show_price;
    const priceMode = (data.price_mode || 'syp').toLowerCase();
    const showPriceSyp = showPrice && (priceMode === 'syp' || priceMode === 'both');
    const showPriceUsd = showPrice && (priceMode === 'usd' || priceMode === 'both');
    const max = data.max_packages_per_material;
    const maxLabel = data.max_packages_label || max;

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

    updateBadge(data.cart_count);

    const items = Array.isArray(data.items) ? data.items : [];
    const unavailable = Array.isArray(data.unavailable) ? data.unavailable : [];

    if (bodyEl) {
      if (items.length === 0 && unavailable.length === 0) {
        bodyEl.innerHTML = `
          <div class="store-cart-empty lg:col-span-8">
            <span class="material-symbols-outlined text-5xl text-gray-300" aria-hidden="true">shopping_cart</span>
            <p class="text-gray-500 mt-3">السلة فارغة.</p>
            <a href="/store.php" class="store-btn store-btn--primary mt-4">تصفح المتجر</a>
          </div>`;
      } else {
        let html = '<div class="lg:col-span-8 space-y-4">';
        if (max) {
          html += `<p class="store-limit-banner">الحد الأقصى للطلب: <strong>${escapeHtml(String(maxLabel))}</strong> طرد لكل مادة.</p>`;
        }
        if (items.length > 0) {
          html += '<div class="store-cart-lines">';
          items.forEach((line) => {
            html += renderCartLineCard(line, showPriceSyp, showPriceUsd, max);
          });
          html += '</div>';
        }
        if (unavailable.length > 0) {
          html += renderUnavailableSection(unavailable);
        }
        html += '</div>';
        bodyEl.innerHTML = html;
        bindCartLineControls(bodyEl, max);
        bindUnavailableControls(bodyEl);
        bindImageZoom(bodyEl);
      }
    }

    if (summaryEl) {
      const totals = data.totals || {};
      const allowOrder = !!data.allow_order;
      const isLoggedIn = root.dataset.loggedIn === '1' || !!data.logged_in;
      const totalSp = Number(totals.total_sp) || 0;
      const totalUsd = Number(totals.total_usd) || 0;
      const totalLine = showPriceSyp
        ? `<div class="store-cart-summary__total">الإجمالي: ${formatMoney(totalSp)} ل.س</div>`
        : showPriceUsd
          ? `<div class="store-cart-summary__total">الإجمالي: $${formatUsd(totalUsd)}</div>`
          : '';
      summaryEl.innerHTML = `<div class="store-panel store-cart-summary space-y-4">
        ${totalLine}
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
      bindClearCart(summaryEl);
      bindCheckout(summaryEl);
    }

    if (Array.isArray(data.stock_notices) && data.stock_notices.length > 0 && stockEl) {
      const uniqueNotices = [...new Set(data.stock_notices.map((n) => String(n || '').trim()).filter(Boolean))];
      if (uniqueNotices.length > 0) {
        stockEl.classList.remove('hidden');
        stockEl.innerHTML = `<div class="rounded-xl border bg-amber-50 border-amber-200 text-amber-900 px-4 py-3 text-sm"><p class="font-bold mb-1">تنبيه المخزون</p>${uniqueNotices.map((n) => `<p>${escapeHtml(n)}</p>`).join('')}</div>`;
      }
    }
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
      try {
        const data = await apiRequest(payload);
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

  const applyCartResponse = (data) => {
    if (!data || typeof data !== 'object') return;
    if (document.querySelector('[data-store-cart-page]')) {
      renderCartPage(data);
    }
    updateBadge(data.cart_count);
    if (data.cart_qty_by_guid) {
      document.querySelectorAll('[data-store-add-cart]').forEach((f) => syncFormLimits(f, data.cart_qty_by_guid));
    }
    if (data.message) showToast(data.message, data.level || (data.ok ? 'success' : 'error'));
  };

  const initCartPage = async () => {
    const root = document.querySelector('[data-store-cart-page]');
    if (!root) return;
    try {
      const res = await fetch(`${API}?reconcile=1`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const data = await res.json();
      renderCartPage(data);
    } catch {
      showToast('تعذر تحميل السلة.', 'error');
    }
  };

  const init = () => {
    bindAddForms();
    const page = document.querySelector('[data-store-cart-page]');
    if (page) {
      bindCartLineControls(page, null);
      bindUnavailableControls(page);
      bindClearCart(page);
      bindCheckout(page);
      bindImageZoom(page);
    }
    initCartPage();
    document.querySelectorAll('[data-store-add-cart]').forEach((form) => {
      if (form.dataset.maxQty) bindQtySteppers(form);
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.StoreCart = { showToast, updateBadge, applyCartResponse, apiRequest, bindAddForms, bindQtySteppers };
})();
