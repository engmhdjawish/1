(() => {
  const modal = document.getElementById('storeProductPreview');
  if (!modal) return;

  const imgEl = document.getElementById('storeProductPreviewImg');
  const titleEl = document.getElementById('storeProductPreviewTitle');
  const subtitleEl = document.getElementById('storeProductPreviewSubtitle');
  const pricesEl = document.getElementById('storeProductPreviewPrices');
  const cartEl = document.getElementById('storeProductPreviewCart');
  const counterEl = document.getElementById('storeProductPreviewCounter');
  const detailEl = document.getElementById('storeProductPreviewDetail');
  const btnPrev = modal.querySelector('[data-preview-prev]');
  const btnNext = modal.querySelector('[data-preview-next]');

  const state = { items: [], index: 0 };
  let touchStartX = 0;
  let touchStartY = 0;

  const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const formatMoney = (amount) => {
    const n = Number(amount) || 0;
    return n.toLocaleString('ar-SY', { maximumFractionDigits: 0 });
  };

  const formatUsd = (amount) => {
    const n = Number(amount) || 0;
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
        ? `<span class="offer-price-block__old">${formatMoney(p.originalPackSp)} ل.س</span>` : '';
      rows.push(`
        <div class="offer-price-block__row offer-price-block__row--main">
          <span class="offer-price-block__label">سعر ${esc(p.packageUnit)}</span>
          <div class="offer-price-block__values">
            ${oldPack}
            <span class="offer-price-block__amount offer-price-block__amount--syp">${formatMoney(p.packageSaleSp > 0 ? p.packageSaleSp : p.originalPackSp)} <small>ل.س</small></span>
          </div>
        </div>`);
    }
    if (p.showPriceSyp && (p.unitSaleSp > 0 || p.originalUnitSp > 0)) {
      const oldUnit = p.hasOffer && p.originalUnitSp > p.unitSaleSp
        ? `<span class="offer-price-block__old">${formatMoney(p.originalUnitSp)} ل.س</span>` : '';
      rows.push(`
        <div class="offer-price-block__row">
          <span class="offer-price-block__label">سعر ${esc(p.primaryUnit)}</span>
          <div class="offer-price-block__values">
            ${oldUnit}
            <span class="offer-price-block__amount offer-price-block__amount--unit">${formatMoney(p.unitSaleSp > 0 ? p.unitSaleSp : p.originalUnitSp)} <small>ل.س</small></span>
          </div>
        </div>`);
    }
    if (p.showPriceUsd && (p.packageSaleUsd > 0 || p.originalPackUsd > 0)) {
      const oldPack = p.hasOffer && p.originalPackUsd > p.packageSaleUsd
        ? `<span class="offer-price-block__old">$${formatUsd(p.originalPackUsd)}</span>` : '';
      rows.push(`
        <div class="offer-price-block__row offer-price-block__row--main">
          <span class="offer-price-block__label">سعر ${esc(p.packageUnit)}</span>
          <div class="offer-price-block__values">
            ${oldPack}
            <span class="offer-price-block__amount offer-price-block__amount--usd">$${formatUsd(p.packageSaleUsd > 0 ? p.packageSaleUsd : p.originalPackUsd)}</span>
          </div>
        </div>`);
    }
    if (p.showPriceUsd && (p.unitSaleUsd > 0 || p.originalUnitUsd > 0)) {
      const oldUnit = p.hasOffer && p.originalUnitUsd > p.unitSaleUsd
        ? `<span class="offer-price-block__old">$${formatUsd(p.originalUnitUsd)}</span>` : '';
      rows.push(`
        <div class="offer-price-block__row">
          <span class="offer-price-block__label">سعر ${esc(p.primaryUnit)}</span>
          <div class="offer-price-block__values">
            ${oldUnit}
            <span class="offer-price-block__amount offer-price-block__amount--unit">$${formatUsd(p.unitSaleUsd > 0 ? p.unitSaleUsd : p.originalUnitUsd)}</span>
          </div>
        </div>`);
    }

    if (rows.length === 0) {
      return '<p class="store-product-preview__no-price">لا تتوفر أسعار لهذه المادة.</p>';
    }

    return `<div class="offer-price-block">${badge}${rows.join('')}</div>`;
  };

  const renderCartForm = (p) => {
    if (!p.allowCart) return '';

    const maxAttr = p.maxPackages != null ? `data-max-qty="${esc(p.maxPackages)}" data-max-qty-label="${esc(p.maxLabel || p.maxPackages)}"` : '';
    const remaining = p.remaining != null ? Math.max(0, Number(p.remaining) || 0) : null;
    const atLimit = !!p.atLimit;
    const maxInput = remaining !== null && remaining > 0 ? `max="${remaining}"` : (atLimit ? 'max="1"' : '');
    const disabled = atLimit ? 'disabled' : '';
    const plusDisabled = (atLimit || (remaining !== null && remaining <= 0)) ? 'disabled' : '';

    let hint = '';
    if (p.maxLabel != null) {
      if (atLimit) {
        hint = `<p class="store-add-cart__limit is-warning" data-qty-hint>وصلت للحد الأقصى (${esc(p.maxLabel)} ${esc(p.packageUnit)})</p>`;
      } else if (p.cartQty > 0) {
        hint = `<p class="store-add-cart__limit" data-qty-hint>الحد الأقصى ${esc(p.maxLabel)} ${esc(p.packageUnit)} — متبقي ${remaining}</p>`;
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
        data-material-guid="${esc(p.guid)}"
        data-cart-qty="${esc(p.cartQty)}"
        ${maxAttr}
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
            <input type="number" name="quantity" min="1" ${maxInput} step="1" value="1" ${disabled}>
            <button type="button" data-qty-plus aria-label="زيادة" ${plusDisabled}>+</button>
          </div>
        </div>
        <button type="submit" class="store-add-cart__submit" ${disabled}>
          <span class="material-symbols-outlined text-[20px]" aria-hidden="true">add_shopping_cart</span>
          ${atLimit ? 'الحد الأقصى مكتمل' : 'إضافة للسلة'}
        </button>
      </form>`;
  };

  const syncCartQtyFromDom = (p) => {
    const card = document.querySelector(`[data-preview-guid="${CSS.escape(p.guid)}"]`);
    const form = card?.querySelector('[data-store-add-cart]');
    if (!form) return p;
    const inCart = Math.max(0, parseInt(form.dataset.cartQty || '0', 10) || 0);
    const next = { ...p, cartQty: inCart };
    if (next.maxPackages != null) {
      next.remaining = Math.max(0, Number(next.maxPackages) - inCart);
      next.atLimit = next.remaining <= 0;
    }
    return next;
  };

  const render = (p) => {
    const item = syncCartQtyFromDom(p);
    const imageSrc = item.zoomUrl || item.thumbUrl || '';
    if (imgEl) {
      imgEl.src = imageSrc;
      imgEl.alt = item.name || '';
      imgEl.classList.toggle('is-placeholder', imageSrc === '');
    }

    if (titleEl) titleEl.textContent = item.name || '—';

    if (subtitleEl) {
      const parts = [item.manufacturer, item.code ? `#${item.code}` : '', item.materialType].filter(Boolean);
      subtitleEl.textContent = parts.join(' · ');
      subtitleEl.hidden = parts.length === 0;
    }

    if (pricesEl) pricesEl.innerHTML = renderPrices(item);
    if (cartEl) cartEl.innerHTML = renderCartForm(item);
    if (detailEl) {
      detailEl.href = item.detailUrl || '/store.php';
      detailEl.classList.toggle('hidden', !item.detailUrl);
    }

    const total = state.items.length;
    const hasMany = total > 1;
    if (counterEl) counterEl.textContent = hasMany ? `${state.index + 1} / ${total}` : '';
    if (btnPrev) btnPrev.disabled = !hasMany;
    if (btnNext) btnNext.disabled = !hasMany;

    if (window.StoreCart?.bindAddForms) {
      window.StoreCart.bindAddForms();
    }
    if (window.StoreCart?.bindQtySteppers) {
      window.StoreCart.bindQtySteppers(cartEl || modal);
    }
  };

  const showAt = (index) => {
    if (state.items.length === 0) return;
    state.index = ((index % state.items.length) + state.items.length) % state.items.length;
    render(state.items[state.index]);
  };

  const open = (guid) => {
    state.items = collectItems();
    if (state.items.length === 0) return;
    const idx = state.items.findIndex((item) => item.guid === guid);
    state.index = idx >= 0 ? idx : 0;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    showAt(state.index);
  };

  const close = () => {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    if (imgEl) imgEl.src = '';
    document.body.style.overflow = '';
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

  btnPrev?.addEventListener('click', () => showAt(state.index - 1));
  btnNext?.addEventListener('click', () => showAt(state.index + 1));

  document.addEventListener('keydown', (event) => {
    if (modal.hidden) return;
    if (event.key === 'Escape') close();
    if (event.key === 'ArrowRight') showAt(state.index - 1);
    if (event.key === 'ArrowLeft') showAt(state.index + 1);
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
    if (dx > 0) showAt(state.index - 1);
    else showAt(state.index + 1);
  }, { passive: true });
})();
