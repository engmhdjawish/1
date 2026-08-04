(function () {
  const cfg = window.PRICE_CHECKER || {};
  const API_URL = cfg.apiUrl || '?action=lookup&barcode=';
  const WARMUP_URL = cfg.warmupUrl || '?action=warmup';
  const PROMO_URL = cfg.promoUrl || '?action=slideshow';
  const DISPLAY_SECONDS = Number(cfg.displaySeconds || 5);
  const ERROR_SECONDS = Number(cfg.errorSeconds || 5);
  const PROMO_INTERVAL = Number(cfg.promoInterval || 20000);
  const PROMO_SHOW_PRICE = cfg.promoShowPrice !== false;
  const SLIDESHOW_ENABLED = cfg.slideshowEnabled !== false;

  document.documentElement.style.setProperty('--pc-error-seconds', ERROR_SECONDS + 's');

  const states = ['standby', 'product', 'error'];
  let activeState = 'standby';
  let scanBuffer = '';
  let scanResetTimer = null;
  let timerInterval = null;
  let errorTimeout = null;
  let lastBarcode = '';
  let isLoading = false;
  let promoItems = [];
  let promoIndex = 0;
  let promoTimer = null;
  let promoPaused = false;
  let promoReloading = false;
  let promoReloadAbort = null;
  let ssBgFlip = false;
  let lookupOverlayTimer = null;
  const promoImageReady = new Set();
  const promoImageLoading = new Map();

  function $(id) { return document.getElementById(id); }

  function setLoading(on) {
    isLoading = on;
    $('loading-overlay')?.classList.toggle('is-hidden', !on);
  }

  function updateClock() {
    const el = $('clock');
    if (!el) return;
    const now = new Date();
    el.textContent = now.toLocaleDateString('ar-SY', { weekday: 'short', day: 'numeric', month: 'short' })
      + '  ' + now.toLocaleTimeString('ar-SY', { hour: '2-digit', minute: '2-digit' });
  }
  updateClock();
  setInterval(updateClock, 30000);

  function setScreensaverBg(url) {
    if (promoPaused || activeState !== 'standby' || !url) return;
    const a = $('ss-bg-a');
    const b = $('ss-bg-b');
    if (!a || !b) return;
    const show = ssBgFlip ? b : a;
    const hide = ssBgFlip ? a : b;
    show.style.backgroundImage = 'url("' + String(url).replace(/"/g, '\\"') + '")';
    hide.classList.remove('is-active');
    show.classList.add('is-active');
    ssBgFlip = !ssBgFlip;
  }

  function updatePromoVisibility() {
    const show = SLIDESHOW_ENABLED && activeState === 'standby' && promoItems.length > 0 && !promoPaused;
    $('ss-screensaver')?.classList.toggle('has-promo', show);
  }

  function pausePromo() {
    promoPaused = true;
    if (promoTimer) { clearInterval(promoTimer); promoTimer = null; }
    if (promoReloadAbort) { promoReloadAbort.abort(); promoReloadAbort = null; }
    const img = $('promo-image');
    if (img) { img.onload = null; img.onerror = null; }
    promoImageLoading.clear();
    updatePromoVisibility();
  }

  function resumePromo() {
    if (!SLIDESHOW_ENABLED || activeState !== 'standby') return;
    promoPaused = false;
    updatePromoVisibility();
    if (promoItems.length > 0) {
      showPromoItem(promoIndex);
      startPromoRotation();
    } else {
      initPromo(false);
    }
  }

  function showState(name) {
    if (errorTimeout) { clearTimeout(errorTimeout); errorTimeout = null; }
    if (name !== 'standby') pausePromo();
    activeState = name;
    states.forEach((s) => {
      const el = $('state-' + s);
      if (!el) return;
      const show = s === name;
      el.classList.toggle('hidden-state', !show);
      el.classList.toggle('is-hidden', !show);
    });
    if (name !== 'standby') updateScanPreview('');
    if (name === 'standby') resumePromo();
    else updatePromoVisibility();
  }

  function updateScanPreview(v) {
    const el = $('scan-preview');
    if (!el) return;
    el.textContent = v || '...';
    el.classList.toggle('opacity-0', !v);
    el.classList.toggle('opacity-100', !!v);
  }

  function formatNumber(v, d) {
    return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
  }

  function setOfferChrome(m, perBox) {
    const hasOffer = !!m.hasOffer;
    const productScreen = $('state-product');
    productScreen?.classList.toggle('pc-product--offer', hasOffer);
    $('product-header')?.classList.toggle('pc-product-header--offer', hasOffer);
    $('product-main')?.classList.toggle('pc-product-main--offer', hasOffer);
    $('product-prices-offer')?.classList.toggle('hidden', !hasOffer);
    $('product-prices-normal')?.classList.toggle('hidden', hasOffer);

    if (!hasOffer) {
      $('product-offer-discount')?.classList.add('hidden');
      return;
    }

    const badgeEl = $('product-offer-badge');
    if (badgeEl) badgeEl.textContent = m.offerBadge || 'عرض خاص';
    const titleEl = $('product-offer-title');
    if (titleEl) titleEl.textContent = m.offerTitle || '';

    const unitSp = Number(m.salePrice_SP || 0);
    const unitUsd = Number(m.salePrice_Usd || 0);
    const oldUnitSp = Number(m.originalSalePrice_SP || 0);
    const oldUnitUsd = Number(m.originalSalePrice_Usd || 0);
    const boxSp = unitSp * perBox;
    const boxUsd = unitUsd * perBox;
    const oldBoxSp = Number(m.originalBoxSalePrice_SP || oldUnitSp * perBox);
    const oldBoxUsd = Number(m.originalBoxSalePrice_Usd || oldUnitUsd * perBox);

    let discountValue = Number(m.discountPercent || 0);
    if (discountValue <= 0) {
      const candidates = [];
      if (oldUnitSp > unitSp + 0.01 && oldUnitSp > 0) candidates.push(Math.round((1 - unitSp / oldUnitSp) * 100));
      if (oldUnitUsd > unitUsd + 0.0001 && oldUnitUsd > 0) candidates.push(Math.round((1 - unitUsd / oldUnitUsd) * 100));
      discountValue = candidates.length ? Math.max(...candidates) : 0;
    }

    const discountWrap = $('product-offer-discount');
    if (discountWrap) {
      const showDiscount = discountValue > 0;
      discountWrap.classList.toggle('hidden', !showDiscount);
      const valueNode = discountWrap.querySelector('.pc-offer-board__discount-value');
      if (valueNode) valueNode.textContent = showDiscount ? ('-' + discountValue + '%') : '0%';
    }

    const showOldSp = oldUnitSp > unitSp + 0.01;
    $('offer-sp-unit-old-col')?.classList.toggle('hidden', !showOldSp);
    $('offer-sp-unit-old') && ($('offer-sp-unit-old').textContent = formatNumber(oldUnitSp, 0));
    $('offer-sp-unit-new') && ($('offer-sp-unit-new').textContent = formatNumber(unitSp, 0));

    const savingsEl = $('offer-sp-savings');
    if (savingsEl) {
      if (showOldSp && oldUnitSp > unitSp + 0.01) {
        const saved = oldUnitSp - unitSp;
        savingsEl.textContent = 'وفّر ' + formatNumber(saved, 0) + ' ل.س على كل قطعة';
        savingsEl.classList.remove('hidden');
      } else {
        savingsEl.textContent = '';
        savingsEl.classList.add('hidden');
      }
    }

    const showBoxSp = oldBoxSp > boxSp + 0.01;
    $('offer-sp-box-row')?.classList.toggle('hidden', !showBoxSp);
    $('offer-sp-box-old') && ($('offer-sp-box-old').textContent = formatNumber(oldBoxSp, 0) + ' ل.س');
    $('offer-sp-box-new') && ($('offer-sp-box-new').textContent = formatNumber(boxSp, 0) + ' ل.س');

    const hasUsd = unitUsd > 0 || oldUnitUsd > 0;
    $('offer-usd-block')?.classList.toggle('hidden', !hasUsd);
    if (hasUsd) {
      const showOldUsd = oldUnitUsd > unitUsd + 0.0001;
      $('offer-usd-unit-old-wrap')?.classList.toggle('hidden', !showOldUsd);
      $('offer-usd-unit-old') && ($('offer-usd-unit-old').textContent = '$' + formatNumber(oldUnitUsd, 2));
      $('offer-usd-unit-new') && ($('offer-usd-unit-new').textContent = '$' + formatNumber(unitUsd, 2));

      const showBoxUsd = oldBoxUsd > boxUsd + 0.0001;
      $('offer-usd-box-row')?.classList.toggle('hidden', !showBoxUsd);
      $('offer-usd-box-old') && ($('offer-usd-box-old').textContent = '$' + formatNumber(oldBoxUsd, 2));
      $('offer-usd-box-new') && ($('offer-usd-box-new').textContent = '$' + formatNumber(boxUsd, 2));
    }
  }

  function updateProductUI(m, barcode) {
    const qty = Number(m.availableQuantity ?? m.availableQunatity ?? 0);
    const perBox = Number(m.pcsPerBox || 1);
    const unitSp = Number(m.salePrice_SP || 0);
    const unitUsd = Number(m.salePrice_Usd || 0);
    const name = m.name || 'معلومات المنتج';
    const nameEl = $('product-badge-name');
    if (nameEl) {
      nameEl.textContent = name;
      nameEl.className = 'text-zinc-900 font-extrabold leading-tight break-words line-clamp-2 w-full '
        + (name.length > 40 ? 'text-xl md:text-3xl' : 'text-2xl md:text-4xl lg:text-5xl');
    }
    const barcodeEl = $('product-barcode');
    if (barcodeEl) barcodeEl.textContent = barcode;
    const spUnit = $('price-sp-unit');
    if (spUnit) spUnit.textContent = formatNumber(unitSp, 0);
    const spBox = $('price-sp-box');
    if (spBox) spBox.textContent = formatNumber(unitSp * perBox, 0) + ' ل.س';
    const usdUnit = $('price-usd-unit');
    if (usdUnit) usdUnit.textContent = '$' + formatNumber(unitUsd, 2);
    const usdBox = $('price-usd-box');
    if (usdBox) usdBox.textContent = '$' + formatNumber(unitUsd * perBox, 2);
    setOfferChrome(m, perBox);
    const pcsBox = $('pcs-per-box');
    if (pcsBox) pcsBox.textContent = perBox + ' قطعة';
    const qtyEl = $('available-qty');
    if (qtyEl) {
      if (perBox > 1) {
        const boxes = Math.floor(qty / perBox);
        const pieces = qty % perBox;
        const out = [];
        if (boxes > 0) out.push(boxes + ' طرد');
        if (pieces > 0) out.push(pieces + ' قطعة');
        qtyEl.textContent = out.length ? out.join(' + ') : '0';
      } else {
        qtyEl.textContent = formatNumber(qty, 0) + ' قطعة';
      }
    }
  }

  function startProductCountdown(sec) {
    clearInterval(timerInterval);
    let left = sec;
    const bar = $('product-progress-bar');
    if (bar) {
      bar.style.transition = 'none';
      bar.style.width = '100%';
      requestAnimationFrame(() => requestAnimationFrame(() => {
        bar.style.transition = 'width ' + sec + 's linear';
        bar.style.width = '0%';
      }));
    }
    timerInterval = setInterval(() => {
      if (--left <= 0) {
        clearInterval(timerInterval);
        showState('standby');
      }
    }, 1000);
  }

  function showError(barcode, title, message) {
    const barcodeEl = $('error-barcode');
    if (barcodeEl) barcodeEl.textContent = barcode || lastBarcode;
    const titleEl = $('error-title');
    if (titleEl) titleEl.textContent = title || 'الباركود خاطئ';
    const messageEl = $('error-message');
    if (messageEl) messageEl.textContent = message || 'يرجى المحاولة مرة أخرى';
    const bar = document.querySelector('#state-error .animate-progress');
    if (bar) { bar.style.animation = 'none'; void bar.offsetWidth; bar.style.animation = ''; }
    showState('error');
    errorTimeout = setTimeout(() => showState('standby'), ERROR_SECONDS * 1000);
  }

  function setLookupLoading(on) {
    if (lookupOverlayTimer) {
      clearTimeout(lookupOverlayTimer);
      lookupOverlayTimer = null;
    }
    if (on) {
      lookupOverlayTimer = setTimeout(() => {
        lookupOverlayTimer = null;
        setLoading(true);
      }, 250);
      return;
    }
    setLoading(false);
  }

  function showLookupPending(barcode) {
    showState('product');
    const nameEl = $('product-badge-name');
    if (nameEl) {
      nameEl.textContent = 'جاري التحميل...';
      nameEl.className = 'text-zinc-500 font-extrabold text-2xl md:text-3xl leading-tight break-words line-clamp-2 w-full';
    }
    const barcodeEl = $('product-barcode');
    if (barcodeEl) barcodeEl.textContent = barcode;
    ['price-sp-unit', 'price-sp-box', 'price-usd-unit', 'price-usd-box', 'pcs-per-box', 'available-qty'].forEach((id) => {
      const el = $(id);
      if (el) el.textContent = '…';
    });
    $('product-prices-offer')?.classList.add('hidden');
    $('product-prices-normal')?.classList.remove('hidden');
    $('state-product')?.classList.remove('pc-product--offer');
    startProductCountdown(DISPLAY_SECONDS + 3);
  }

  async function loadByBarcode(barcode) {
    if (isLoading) return;
    pausePromo();
    lastBarcode = barcode;
    isLoading = true;
    showLookupPending(barcode);
    setLookupLoading(true);
    try {
      const res = await fetch(API_URL + encodeURIComponent(barcode), {
        cache: 'no-store',
        priority: 'high',
        headers: { Accept: 'application/json' },
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        if (data.error === 'api_error') return showError(barcode, 'خطأ في النظام', data.message || '');
        if (data.error === 'forbidden') return showError(barcode, 'غير مسموح', 'الوصول مرفوض من هذا الجهاز');
        return showError(barcode, 'الباركود خاطئ', 'لم يُعثر على المنتج');
      }
      updateProductUI(data, barcode);
      showState('product');
      startProductCountdown(DISPLAY_SECONDS);
    } catch {
      showError(barcode, 'خطأ في الاتصال', 'تحقق من الشبكة');
    } finally {
      isLoading = false;
      setLookupLoading(false);
    }
  }

  function renderPromoDots() {
    const dots = $('promo-dots');
    if (!dots) return;
    dots.innerHTML = '';
    const maxDots = Math.min(promoItems.length, 8);
    for (let i = 0; i < maxDots; i++) {
      const d = document.createElement('span');
      d.className = 'promo-dot' + (i === promoIndex % maxDots ? ' is-active' : '');
      dots.appendChild(d);
    }
  }

  function startPromoProgress() {
    const bar = $('promo-progress');
    if (!bar) return;
    bar.style.transition = 'none';
    bar.style.width = '100%';
    requestAnimationFrame(() => requestAnimationFrame(() => {
      bar.style.transition = 'width ' + PROMO_INTERVAL + 'ms linear';
      bar.style.width = '0%';
    }));
  }

  function preloadPromoImage(url) {
    if (!url) return Promise.resolve('');
    if (promoImageReady.has(url)) return Promise.resolve(url);
    if (promoImageLoading.has(url)) return promoImageLoading.get(url);
    const p = new Promise((resolve) => {
      const img = new Image();
      img.decoding = 'async';
      img.onload = () => { promoImageReady.add(url); promoImageLoading.delete(url); resolve(url); };
      img.onerror = () => { promoImageLoading.delete(url); resolve(url); };
      img.src = url;
    });
    promoImageLoading.set(url, p);
    return p;
  }

  function preloadUpcoming(fromIndex) {
    if (promoPaused || activeState !== 'standby') return;
    for (let n = 1; n <= 3; n++) {
      const item = promoItems[fromIndex + n];
      if (item?.image) preloadPromoImage(item.image);
    }
  }

  function fillPromoText(item) {
    const img = $('promo-image');
    const manufacturer = $('promo-manufacturer');
    const name = $('promo-name');
    if (manufacturer) manufacturer.textContent = item.manufacturer || '';
    if (name) name.textContent = item.name || '';
    if (img) img.alt = item.name || '';

    const sp = Number(item.priceSp || 0);
    const usd = Number(item.priceUsd || 0);
    const spBox = $('promo-price-sp-box');
    const usdBox = $('promo-price-usd-box');

    if (PROMO_SHOW_PRICE && sp > 0 && spBox) {
      spBox.classList.remove('hidden');
      const spEl = $('promo-price-sp');
      if (spEl) spEl.textContent = formatNumber(sp, 0);
    } else if (spBox) {
      spBox.classList.add('hidden');
    }

    if (PROMO_SHOW_PRICE && usd > 0 && usdBox) {
      usdBox.classList.remove('hidden');
      const usdEl = $('promo-price-usd');
      if (usdEl) usdEl.textContent = '$' + formatNumber(usd, 2);
    } else if (usdBox) {
      usdBox.classList.add('hidden');
    }
  }

  function showPromoItem(i) {
    if (!SLIDESHOW_ENABLED || promoPaused || activeState !== 'standby') return;
    const item = promoItems[i];
    if (!item) return;
    const card = $('promo-card');
    const img = $('promo-image');
    if (!card || !img) return;

    const url = item.image;

    const revealItem = (readyUrl) => {
      if (promoPaused || activeState !== 'standby' || promoItems[promoIndex] !== item) return;
      const counter = $('promo-counter');
      if (counter) counter.textContent = (i + 1) + ' / ' + promoItems.length;
      fillPromoText(item);
      img.src = readyUrl;
      img.classList.remove('is-loading');
      card.classList.remove('promo-switching');
      setScreensaverBg(readyUrl);
      renderPromoDots();
      startPromoProgress();
      card.classList.remove('promo-fade');
      void card.offsetWidth;
      card.classList.add('promo-fade');
    };

    card.classList.add('promo-switching');
    img.classList.add('is-loading');
    img.removeAttribute('src');

    if (promoImageReady.has(url)) {
      revealItem(url);
    } else {
      preloadPromoImage(url).then(revealItem);
    }

    preloadUpcoming(i);
  }

  function advancePromo() {
    if (!SLIDESHOW_ENABLED || promoPaused || promoReloading || activeState !== 'standby' || promoItems.length === 0) return;
    promoIndex++;
    if (promoIndex >= promoItems.length) {
      reloadPromoBatch();
      return;
    }
    showPromoItem(promoIndex);
  }

  function startPromoRotation() {
    if (promoTimer) clearInterval(promoTimer);
    if (promoItems.length < 2) return;
    promoTimer = setInterval(advancePromo, PROMO_INTERVAL);
  }

  function collectShownGuids() {
    return promoItems.map((it) => it.imageGuid).filter(Boolean);
  }

  async function reloadPromoBatch() {
    if (!SLIDESHOW_ENABLED || promoReloading || promoPaused || activeState !== 'standby') return;
    promoReloading = true;
    if (promoTimer) { clearInterval(promoTimer); promoTimer = null; }

    const exclude = collectShownGuids().join(',');
    const url = PROMO_URL + (PROMO_URL.includes('?') ? '&' : '?') + 'refresh=1'
      + (exclude ? '&exclude=' + encodeURIComponent(exclude) : '');

    promoReloadAbort = new AbortController();
    try {
      const res = await fetch(url, { signal: promoReloadAbort.signal, cache: 'no-store' });
      const data = await res.json();
      const items = Array.isArray(data.items) ? data.items : [];
      if (items.length > 0) {
        promoItems = items;
        promoIndex = 0;
        showPromoItem(0);
        startPromoRotation();
      } else {
        promoIndex = 0;
        await initPromo(true);
      }
    } catch (e) {
      if (e.name !== 'AbortError') {
        promoIndex = 0;
        await initPromo(true);
      }
    } finally {
      promoReloading = false;
      promoReloadAbort = null;
    }
  }

  async function initPromo(forceRefresh) {
    if (!SLIDESHOW_ENABLED || (promoPaused && !forceRefresh)) return;
    const url = PROMO_URL + (forceRefresh ? (PROMO_URL.includes('?') ? '&' : '?') + 'refresh=1' : '');
    try {
      const res = await fetch(url, { cache: forceRefresh ? 'no-store' : 'default' });
      const data = await res.json();
      promoItems = Array.isArray(data.items) ? data.items : [];
      if (!promoItems.length) return;
      promoIndex = 0;
      promoPaused = false;
      updatePromoVisibility();
      preloadPromoImage(promoItems[0].image).then(() => preloadUpcoming(0));
      showPromoItem(0);
      startPromoRotation();
    } catch {
      // slideshow is optional
    }
  }

  window.addEventListener('keydown', (e) => {
    if (e.ctrlKey || e.altKey || e.metaKey) return;
    if (e.key === 'Enter') {
      const code = scanBuffer.trim();
      scanBuffer = '';
      updateScanPreview('');
      if (code && !isLoading) {
        if (activeState === 'product') clearInterval(timerInterval);
        loadByBarcode(code);
      }
      return;
    }
    if (/^[0-9A-Za-z-]$/.test(e.key)) {
      if (!scanBuffer && activeState === 'standby') pausePromo();
      scanBuffer += e.key;
      updateScanPreview(scanBuffer);
      clearTimeout(scanResetTimer);
      scanResetTimer = setTimeout(() => {
        scanBuffer = '';
        updateScanPreview('');
        if (activeState === 'standby' && promoPaused) resumePromo();
      }, 300);
    }
  });

  if (SLIDESHOW_ENABLED) {
    initPromo(false);
  }

  fetch(WARMUP_URL, { cache: 'no-store', priority: 'low' }).catch(() => {});

  const testBarcode = new URLSearchParams(location.search).get('barcode')?.trim();
  if (testBarcode) loadByBarcode(testBarcode);
})();
