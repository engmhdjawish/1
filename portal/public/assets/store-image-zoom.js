(() => {
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

  const loadImageProgressive = (imgEl, fullUrl, thumbUrl) => {
    if (!imgEl) return;
    const full = String(fullUrl || '').trim();
    const thumb = String(thumbUrl || '').trim();
    const requestId = String(Date.now()) + Math.random().toString(36).slice(2);
    imgEl.dataset.pendingFull = requestId;

    if (!full) {
      imgEl.removeAttribute('src');
      imgEl.classList.remove('is-upgrading');
      delete imgEl.dataset.pendingFull;
      return;
    }

    const previewSrc = thumb && thumb !== full ? thumb : full;
    imgEl.src = previewSrc;
    imgEl.classList.toggle('is-upgrading', previewSrc !== full);

    if (previewSrc === full) {
      delete imgEl.dataset.pendingFull;
      imgEl.classList.remove('is-upgrading');
      return;
    }

    const loader = new Image();
    loader.decoding = 'async';
    loader.onload = () => {
      if (imgEl.dataset.pendingFull !== requestId) return;
      imgEl.src = full;
      imgEl.classList.remove('is-upgrading');
      delete imgEl.dataset.pendingFull;
    };
    loader.onerror = () => {
      if (imgEl.dataset.pendingFull !== requestId) return;
      imgEl.classList.remove('is-upgrading');
      delete imgEl.dataset.pendingFull;
    };
    loader.src = full;
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
        const thumbSrc = btn.querySelector('img')?.getAttribute('src') || '';
        const src = raw || imageZoomUrl(thumbSrc);
        if (!src) return;
        const name = btn.closest('.store-order-line-card, .store-cart-product, .store-order-line-card__media')
          ?.querySelector('.store-order-line-card__title, .font-bold')?.textContent?.trim() || '';
        const previewThumb = thumbSrc || thumbFromZoomUrl(src);
        loadImageProgressive(lightboxImg, src, previewThumb);
        if (lightboxCaption) lightboxCaption.textContent = name;
        lightbox.hidden = false;
        lightbox.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
      });
    });
  };

  const init = () => bindImageZoom();

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
  };
})();
