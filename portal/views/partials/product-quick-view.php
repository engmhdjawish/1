<div id="productQuickView" class="fixed inset-0 z-[60] hidden" aria-hidden="true">
  <div id="productQuickViewBackdrop" class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>
  <div class="absolute inset-0 flex items-end sm:items-center justify-center p-0 sm:p-4 pointer-events-none">
    <div
      id="productQuickViewPanel"
      class="pointer-events-auto relative w-full sm:max-w-2xl max-h-[92vh] sm:max-h-[88vh] bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl overflow-hidden flex flex-col touch-pan-y"
      role="dialog"
      aria-modal="true"
      aria-labelledby="productQuickViewTitle"
    >
      <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-gray-100 shrink-0">
        <button type="button" id="productQuickViewPrev" class="w-10 h-10 rounded-full border border-gray-200 inline-flex items-center justify-center hover:border-primary disabled:opacity-30" aria-label="المادة السابقة">
          <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
        </button>
        <div class="text-xs text-gray-500 font-bold" id="productQuickViewCounter"></div>
        <button type="button" id="productQuickViewNext" class="w-10 h-10 rounded-full border border-gray-200 inline-flex items-center justify-center hover:border-primary disabled:opacity-30" aria-label="المادة التالية">
          <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
        </button>
        <button type="button" id="productQuickViewClose" class="absolute left-4 top-3 w-10 h-10 rounded-full bg-gray-100 inline-flex items-center justify-center hover:bg-gray-200" aria-label="إغلاق">
          <span class="material-symbols-outlined" aria-hidden="true">close</span>
        </button>
      </div>
      <div id="productQuickViewBody" class="overflow-y-auto flex-1 p-4 sm:p-6">
        <div class="text-center text-gray-500 py-16">جاري التحميل...</div>
      </div>
      <div class="shrink-0 border-t border-gray-100 px-4 py-3 flex flex-wrap gap-2 justify-between items-center bg-white">
        <a id="productQuickViewFullLink" href="/store.php" class="text-sm font-bold text-primary">فتح صفحة كاملة</a>
        <span class="text-xs text-gray-400 hidden sm:inline">اسحب يميناً/يساراً للتنقل</span>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById('productQuickView');
  const panel = document.getElementById('productQuickViewPanel');
  const body = document.getElementById('productQuickViewBody');
  const backdrop = document.getElementById('productQuickViewBackdrop');
  const btnClose = document.getElementById('productQuickViewClose');
  const btnPrev = document.getElementById('productQuickViewPrev');
  const btnNext = document.getElementById('productQuickViewNext');
  const counter = document.getElementById('productQuickViewCounter');
  const fullLink = document.getElementById('productQuickViewFullLink');
  if (!modal || !panel || !body) return;

  const state = { guids: [], index: 0, offer: '', returnUrl: '', loading: false };
  let touchStartX = 0;
  let touchStartY = 0;

  const esc = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const formatMoney = (amount) => {
    const n = Number(amount) || 0;
    return n.toLocaleString('en-US', { maximumFractionDigits: 0 });
  };

  const renderMaterialFrame = (p) => {
    if (!p.showImages) {
      return '';
    }
    if (p.imageGuid) {
      return `<div class="material-image-frame material-image-frame--detail"><div class="material-image-frame__photo"><img src="/api/image.php?id=${encodeURIComponent(p.imageGuid)}&thumb=0" alt="${esc(p.name)}"></div></div>`;
    }
    return `<div class="material-image-frame material-image-frame--detail"><div class="material-image-frame__photo"><span class="material-symbols-outlined material-image-frame__placeholder" aria-hidden="true">inventory_2</span></div></div>`;
  };

  const renderProduct = (p) => {
    const specs = p.specs || {};
    const specHtml = Object.keys(specs).map((label) => (
      `<div class="rounded-xl border border-gray-100 bg-gray-50 px-4 py-3">
        <div class="text-xs text-gray-500 mb-1">${esc(label)}</div>
        <div class="font-semibold text-sm">${esc(specs[label])}</div>
      </div>`
    )).join('');

    const priceBlocks = [];
    if (p.showPrice && p.showPriceSyp && (p.packageSaleSp > 0 || p.originalPackSp > 0)) {
      const strike = p.hasOffer && p.originalPackSp > p.packageSaleSp
        ? `<div class="text-sm text-gray-400 line-through">${formatMoney(p.originalPackSp)} ل.س</div>` : '';
      priceBlocks.push(`
        <div>
          <div class="text-xs text-gray-500">سعر ${esc(p.primaryUnit)}</div>
          ${p.hasOffer && p.originalUnitSp > p.unitSaleSp ? `<div class="text-xs text-gray-400 line-through">${formatMoney(p.originalUnitSp)} ل.س</div>` : ''}
          <div class="font-bold">${formatMoney(p.unitSaleSp)} ل.س</div>
        </div>
        <div>
          <div class="text-xs text-gray-500">سعر ${esc(p.packageUnit)}</div>
          ${strike}
          <div class="text-primary text-2xl font-extrabold">${formatMoney(p.packageSaleSp)} ل.س</div>
        </div>`);
    }
    if (p.showPrice && p.showPriceUsd && (p.packageSaleUsd > 0 || p.originalPackUsd > 0)) {
      const strikeUsd = p.hasOffer && p.originalPackUsd > p.packageSaleUsd
        ? `<div class="text-sm text-gray-400 line-through">$${Number(p.originalPackUsd).toFixed(2)}</div>` : '';
      priceBlocks.push(`
        <div class="pt-2 border-t border-gray-200">
          <div class="text-xs text-gray-500">سعر ${esc(p.packageUnit)} بالدولار</div>
          ${strikeUsd}
          <div class="text-emerald-700 text-xl font-extrabold">$${Number(p.packageSaleUsd).toFixed(2)}</div>
        </div>`);
    }

    const imageHtml = p.showImages ? renderMaterialFrame(p) : '';

    const badge = p.offerBadge
      ? `<span class="inline-flex mb-2 px-2.5 py-1 rounded-full bg-red-600 text-white text-xs font-extrabold">${esc(p.offerBadge)}</span>` : '';

    const qtyHtml = p.showQuantity
      ? `<div class="text-sm text-gray-700"><span class="font-bold">المتوفر:</span> ${formatMoney(p.packagesAvailable)} ${esc(p.packageUnit)}</div>` : '';

    const limits = (p.offerMin != null || p.offerMax != null)
      ? `<p class="text-xs text-amber-800 pt-2 border-t border-gray-200">حدود العرض: ${p.offerMin != null ? 'الحد الأدنى ' + p.offerMin : ''}${p.offerMin != null && p.offerMax != null ? ' — ' : ''}${p.offerMax != null ? 'الحد الأقصى ' + p.offerMax : ''} ${esc(p.packageUnit)}</p>` : '';

  body.innerHTML = `
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div class="overflow-hidden rounded-2xl">${imageHtml}</div>
      <div class="space-y-3">
        <div>
          <h2 id="productQuickViewTitle" class="text-xl font-extrabold text-slate-900">${esc(p.name)}</h2>
          ${p.manufacturer ? `<p class="text-sm text-gray-600 mt-1">${esc(p.manufacturer)}</p>` : ''}
        </div>
        ${p.showPrice ? `<div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-2">${badge}${priceBlocks.join('')}${limits}</div>` : '<p class="text-sm text-gray-500 border border-dashed rounded-xl px-4 py-3">الأسعار غير متاحة لحسابك.</p>'}
        ${qtyHtml}
      </div>
    </div>
    ${specHtml ? `<div class="mt-5"><h3 class="font-bold mb-3">المواصفات</h3><div class="grid grid-cols-1 sm:grid-cols-2 gap-3">${specHtml}</div></div>` : ''}`;
  };

  const updateNav = () => {
    const total = state.guids.length;
    const hasMany = total > 1;
    btnPrev.disabled = !hasMany;
    btnNext.disabled = !hasMany;
    counter.textContent = hasMany ? `${state.index + 1} / ${total}` : '';
  };

  const buildFullUrl = (guid) => {
    const params = new URLSearchParams({ guid });
    if (state.returnUrl) params.set('return', state.returnUrl);
    if (state.offer) params.set('offer', state.offer);
    return `/product.php?${params.toString()}`;
  };

  const loadAt = async (index) => {
    if (state.loading || state.guids.length === 0) return;
    state.index = ((index % state.guids.length) + state.guids.length) % state.guids.length;
    const guid = state.guids[state.index];
    state.loading = true;
    body.innerHTML = '<div class="text-center text-gray-500 py-16">جاري التحميل...</div>';
    updateNav();
    fullLink.href = buildFullUrl(guid);

    try {
      const params = new URLSearchParams({ guid });
      if (state.offer) params.set('offer', state.offer);
      const response = await fetch(`/api/store-product.php?${params.toString()}`, {
        headers: { Accept: 'application/json' },
      });
      const data = await response.json();
      if (!response.ok || !data.ok || !data.product) {
        throw new Error(data.message || 'تعذر تحميل المادة');
      }
      renderProduct(data.product);
    } catch (error) {
      body.innerHTML = `<div class="text-center text-red-600 py-12">${esc(error.message || 'تعذر التحميل')}</div>`;
    } finally {
      state.loading = false;
    }
  };

  const open = (guid, guids, offer, returnUrl) => {
    const list = Array.isArray(guids) && guids.length ? guids.slice() : [guid];
    state.guids = list.filter(Boolean);
    state.offer = offer || '';
    state.returnUrl = returnUrl || '';
    state.index = Math.max(0, state.guids.indexOf(guid));
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    loadAt(state.index);
  };

  const close = () => {
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  };

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-quick-view]');
    if (!trigger) return;
    event.preventDefault();
    const guid = trigger.getAttribute('data-product-guid') || '';
    if (!guid) return;
    let guids = [];
    const raw = trigger.getAttribute('data-quick-view-guids');
    if (raw) {
      try { guids = JSON.parse(raw); } catch (_) { guids = []; }
    }
    if (!guids.length && window.__productQuickView && Array.isArray(window.__productQuickView.guids)) {
      guids = window.__productQuickView.guids;
    }
    const offer = trigger.getAttribute('data-offer-slug')
      || (window.__productQuickView && window.__productQuickView.offer) || '';
    const returnUrl = trigger.getAttribute('data-return-url')
      || (window.__productQuickView && window.__productQuickView.return) || '';
    open(guid, guids, offer, returnUrl);
  });

  btnClose.addEventListener('click', close);
  backdrop.addEventListener('click', close);
  btnPrev.addEventListener('click', () => loadAt(state.index - 1));
  btnNext.addEventListener('click', () => loadAt(state.index + 1));

  document.addEventListener('keydown', (event) => {
    if (modal.classList.contains('hidden')) return;
    if (event.key === 'Escape') close();
    if (event.key === 'ArrowRight') loadAt(state.index - 1);
    if (event.key === 'ArrowLeft') loadAt(state.index + 1);
  });

  panel.addEventListener('touchstart', (event) => {
    if (event.touches.length !== 1) return;
    touchStartX = event.touches[0].clientX;
    touchStartY = event.touches[0].clientY;
  }, { passive: true });

  panel.addEventListener('touchend', (event) => {
    if (event.changedTouches.length !== 1) return;
    const dx = event.changedTouches[0].clientX - touchStartX;
    const dy = event.changedTouches[0].clientY - touchStartY;
    if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy)) return;
    if (dx > 0) loadAt(state.index - 1);
    else loadAt(state.index + 1);
  }, { passive: true });
})();
</script>
