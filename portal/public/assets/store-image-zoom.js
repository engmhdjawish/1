(() => {
  const loadedUrls = new Set();
  const fullLoadCache = new Map();

  const normalizeImageUrl = (url) => {
    const text = String(url || '').trim();
    if (!text) return '';
    try {
      const parsed = new URL(text, window.location.origin);
      return `${parsed.pathname}${parsed.search}`;
    } catch {
      return text.split('#')[0];
    }
  };

  const urlsMatch = (a, b) => {
    const left = normalizeImageUrl(a);
    const right = normalizeImageUrl(b);
    return left !== '' && left === right;
  };

  const isElementReady = (img) => img instanceof HTMLImageElement
    && img.complete
    && img.naturalWidth > 0;

  const markLoaded = (url) => {
    const key = normalizeImageUrl(url);
    if (key) loadedUrls.add(key);
  };

  const findLoadedElement = (url, prefer) => {
    if (isElementReady(prefer) && urlsMatch(prefer.currentSrc || prefer.src, url)) {
      markLoaded(url);
      return prefer;
    }

    const key = normalizeImageUrl(url);
    if (!key) return null;

    for (const img of document.querySelectorAll('img')) {
      if (!isElementReady(img)) continue;
      const src = img.currentSrc || img.src;
      if (urlsMatch(src, url)) {
        markLoaded(url);
        return img;
      }
    }

    return null;
  };

  const isImageLoaded = (url) => {
    const key = normalizeImageUrl(url);
    if (!key) return false;
    if (loadedUrls.has(key)) return true;
    return !!findLoadedElement(url);
  };

  const applyImageSrc = (imgEl, url, options = {}) => {
    if (!imgEl) return { ready: false, skipped: true };

    const target = String(url || '').trim();
    if (!target) {
      imgEl.removeAttribute('src');
      return { ready: false, skipped: false };
    }

    if (isElementReady(imgEl) && urlsMatch(imgEl.currentSrc || imgEl.src, target)) {
      markLoaded(target);
      return { ready: true, skipped: true };
    }

    const prefer = findLoadedElement(target, options.preferElement);
    if (prefer) {
      const src = prefer.currentSrc || prefer.src || target;
      if (!isElementReady(imgEl) || !urlsMatch(imgEl.currentSrc || imgEl.src, src)) {
        imgEl.src = src;
      }
      markLoaded(target);
      return { ready: true, skipped: false };
    }

    if (isImageLoaded(target)) {
      if (!isElementReady(imgEl) || !urlsMatch(imgEl.currentSrc || imgEl.src, target)) {
        imgEl.src = target;
      }
      markLoaded(target);
      return { ready: isElementReady(imgEl), skipped: false };
    }

    imgEl.src = target;
    return { ready: false, skipped: false };
  };

  const preloadImage = (url) => {
    const full = String(url || '').trim();
    if (!full) return Promise.resolve(null);
    if (isImageLoaded(full)) return Promise.resolve(null);

    const key = normalizeImageUrl(full);
    const cached = fullLoadCache.get(key);
    if (cached) return cached;

    const promise = new Promise((resolve, reject) => {
      const loader = new Image();
      loader.decoding = 'async';
      loader.onload = () => {
        markLoaded(full);
        resolve(loader);
      };
      loader.onerror = () => reject(new Error('image load failed'));
      loader.src = full;
    }).finally(() => {
      fullLoadCache.delete(key);
    });

    fullLoadCache.set(key, promise);
    return promise;
  };

  const imageZoomUrl = (url) => {
    const text = String(url || '');
    if (!text) return '';
    if (text.includes('thumb=1')) return text.replace('thumb=1', 'thumb=0');
    return text.includes('?') ? `${text}&thumb=0` : `${text}?thumb=0`;
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

  const loadImageProgressive = (imgEl, fullUrl, thumbUrl, options = {}) => {
    if (!imgEl) return Promise.resolve();

    const full = String(fullUrl || '').trim();
    const thumb = String(thumbUrl || '').trim();
    const requestId = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    imgEl.dataset.pendingFull = requestId;

    if (!full) {
      imgEl.removeAttribute('src');
      imgEl.classList.remove('is-upgrading');
      delete imgEl.dataset.pendingFull;
      return Promise.resolve();
    }

    const previewSrc = thumb && thumb !== full ? thumb : full;
    const needsUpgrade = !urlsMatch(previewSrc, full);
    const fullReady = isImageLoaded(full);
    const thumbReady = isImageLoaded(previewSrc) || !!findLoadedElement(previewSrc, options.preferElement);

    applyImageSrc(imgEl, previewSrc, options);
    imgEl.classList.toggle('is-upgrading', needsUpgrade && !fullReady);

    if (!needsUpgrade || fullReady) {
      applyImageSrc(imgEl, full, options);
      imgEl.classList.remove('is-upgrading');
      delete imgEl.dataset.pendingFull;
      return Promise.resolve();
    }

    if (thumbReady) {
      imgEl.classList.add('is-upgrading');
    }

    return preloadImage(full)
      .then(() => {
        if (imgEl.dataset.pendingFull !== requestId) return;
        applyImageSrc(imgEl, full, options);
        imgEl.classList.remove('is-upgrading');
        delete imgEl.dataset.pendingFull;
      })
      .catch(() => {
        if (imgEl.dataset.pendingFull !== requestId) return;
        if (!thumbReady) {
          imgEl.removeAttribute('src');
        }
        imgEl.classList.remove('is-upgrading');
        delete imgEl.dataset.pendingFull;
      });
  };

  const seedLoadedImages = (root = document) => {
    root.querySelectorAll('img').forEach((img) => {
      if (isElementReady(img)) {
        markLoaded(img.currentSrc || img.src);
      }
    });
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
        const name = btn.closest('.store-order-line-card, .store-cart-product, .store-order-line-card__media')
          ?.querySelector('.store-order-line-card__title, .font-bold')?.textContent?.trim() || '';
        const previewThumb = thumbSrc || thumbFromZoomUrl(src);
        loadImageProgressive(lightboxImg, src, previewThumb, { preferElement: thumbImg });
        if (lightboxCaption) lightboxCaption.textContent = name;
        lightbox.hidden = false;
        lightbox.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
      });
    });
  };

  const init = () => {
    seedLoadedImages();
    bindImageZoom();
  };

  document.addEventListener('load', (event) => {
    if (event.target instanceof HTMLImageElement) {
      markLoaded(event.target.currentSrc || event.target.src);
    }
  }, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.StoreImageZoom = {
    bind: bindImageZoom,
    imageZoomUrl,
    thumbFromZoomUrl,
    loadProgressive: loadImageProgressive,
    applySrc: applyImageSrc,
    isImageLoaded,
    isElementReady,
    preload: preloadImage,
    seedLoadedImages,
    normalizeImageUrl,
  };
})();
