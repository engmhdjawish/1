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

  const showToast = (message, level = 'success') => {
    if (!message) return;
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
        const name = btn.closest('.store-cart-product')?.querySelector('.font-bold')?.textContent?.trim() || '';
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
          html += '<div class="store-cart-table-wrap overflow-x-auto"><table class="store-cart-table"><thead><tr>';
          html += '<th>المنتج</th><th>سعر الطرد</th><th>الكمية</th><th>الإجمالي</th><th></th></tr></thead><tbody>';
          items.forEach((line) => {
            const guid = line.material_guid || '';
            const qty = Math.max(1, Math.round(Number(line.quantity) || 1));
            const packageUnit = line.package_unit || 'طرد';
            const priceSp = Number(line.sale_price_sp) || 0;
            const priceUsd = Number(line.sale_price_usd) || 0;
            const lineTotalSp = qty * priceSp;
            const lineTotalUsd = qty * priceUsd;
            const unitPriceCell = showPriceSyp && priceSp > 0
              ? `<span class="font-bold text-primary">${formatMoney(priceSp)} ل.س</span>`
              : showPriceUsd && priceUsd > 0
                ? `<span class="font-bold text-emerald-700">$${formatUsd(priceUsd)}</span>`
                : '—';
            const lineTotalCell = showPriceSyp
              ? `${formatMoney(lineTotalSp)} ل.س`
              : showPriceUsd
                ? `$${formatUsd(lineTotalUsd)}`
                : '—';
            const img = line.image_url
              ? (() => {
                  const thumb = escapeHtml(line.image_url);
                  const zoom = escapeHtml(imageZoomUrl(line.image_url));
                  return `<button type="button" class="store-cart-product__thumb" data-cart-image-zoom="${zoom}" title="تكبير الصورة للتدقيق"><img src="${thumb}" alt=""><span class="store-cart-product__zoom-icon material-symbols-outlined" aria-hidden="true">zoom_in</span></button>`;
                })()
              : '<div class="store-cart-product__placeholder"><span class="material-symbols-outlined">inventory_2</span></div>';
            html += `<tr data-cart-line="${escapeHtml(guid)}">
              <td><div class="store-cart-product">${img}<div>
                <div class="font-bold text-sm">${escapeHtml(line.material_name_ar || '')}</div>
                ${line.material_code ? `<div class="text-xs text-gray-500 font-mono" dir="ltr">${escapeHtml(line.material_code)}</div>` : ''}
              </div></div></td>
              <td class="text-sm whitespace-nowrap">${unitPriceCell}</td>
              <td>
                <div class="store-qty-stepper" data-cart-qty-control data-guid="${escapeHtml(guid)}">
                  <button type="button" data-bump="-1" aria-label="إنقاص">−</button>
                  <input type="number" min="1" ${max ? `max="${Math.floor(max)}"` : ''} value="${qty}" data-qty-input>
                  <button type="button" data-bump="1" aria-label="زيادة">+</button>
                </div>
                <div class="text-xs text-gray-500 mt-1">${escapeHtml(packageUnit)}</div>
              </td>
              <td class="font-bold text-sm">${lineTotalCell}</td>
              <td class="text-center">
                <button type="button" class="p-2 rounded-full text-red-600 hover:bg-red-50" data-remove-item="${escapeHtml(guid)}" aria-label="حذف">
                  <span class="material-symbols-outlined">delete</span>
                </button>
              </td>
            </tr>`;
          });
          html += '</tbody></table></div>';
        }
        if (unavailable.length > 0) {
          html += '<section class="rounded-2xl border border-amber-200 bg-amber-50 p-4"><h3 class="font-bold text-amber-900 mb-2">غير متوفرة حالياً</h3><ul class="text-sm text-amber-900 space-y-1">';
          unavailable.forEach((line) => {
            html += `<li>${escapeHtml(line.material_name_ar || '')}</li>`;
          });
          html += '</ul></section>';
        }
        html += '</div>';
        bodyEl.innerHTML = html;
        bindCartLineControls(bodyEl, max);
        bindImageZoom(bodyEl);
      }
    }

    if (summaryEl) {
      const totals = data.totals || {};
      const allowOrder = !!data.allow_order;
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
            <label class="block text-sm font-bold">الاسم الكامل *
              <input name="guest_name_ar" required class="store-input mt-1" value="${escapeHtml(root.dataset.defaultName || '')}">
            </label>
            <label class="block text-sm font-bold">رقم الهاتف *
              <input name="guest_phone" required dir="ltr" class="store-input mt-1 text-left" value="${escapeHtml(root.dataset.defaultPhone || '')}">
            </label>
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
      stockEl.classList.remove('hidden');
      stockEl.innerHTML = `<div class="rounded-xl border bg-amber-50 border-amber-200 text-amber-900 px-4 py-3 text-sm"><p class="font-bold mb-1">تنبيه المخزون</p>${data.stock_notices.map((n) => `<p>${escapeHtml(n)}</p>`).join('')}</div>`;
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

  const bindCartLineControls = (root, max) => {
    root.querySelectorAll('[data-bump]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const wrap = btn.closest('[data-cart-qty-control]');
        const guid = wrap?.dataset.guid || '';
        const delta = parseInt(btn.dataset.bump || '0', 10);
        if (!guid || !delta) return;
        const data = await apiRequest({ action: 'bump', material_guid: guid, delta });
        applyCartResponse(data);
      });
    });
    root.querySelectorAll('[data-qty-input]').forEach((input) => {
      let timer;
      input.addEventListener('change', () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
          const wrap = input.closest('[data-cart-qty-control]');
          const guid = wrap?.dataset.guid || '';
          const qty = Math.max(0, parseInt(input.value, 10) || 0);
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
