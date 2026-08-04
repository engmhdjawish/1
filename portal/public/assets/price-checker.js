(function () {
  const cfg = window.PRICE_CHECKER || {};
  const API_URL = cfg.apiUrl || '?action=lookup&barcode=';
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

  async function loadByBarcode(barcode) {
    if (isLoading) return;
    pausePromo();
    lastBarcode = barcode;
    setLoading(true);
    try {
      const res = await fetch(API_URL + encodeURIComponent(barcode), { cache: 'no-store', priority: 'high' });
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
      setLoading(false);
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

  const testBarcode = new URLSearchParams(location.search).get('barcode')?.trim();
  if (testBarcode) loadByBarcode(testBarcode);
})();
