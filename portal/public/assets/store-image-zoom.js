(() => {
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
        const raw = btn.getAttribute('data-cart-image-zoom') || '';
        const src = raw || imageZoomUrl(btn.querySelector('img')?.getAttribute('src') || '');
        if (!src) return;
        const name = btn.closest('.store-order-line-card, .store-cart-product, .store-order-line-card__media')
          ?.querySelector('.store-order-line-card__title, .font-bold')?.textContent?.trim() || '';
        lightboxImg.src = src;
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

  window.StoreImageZoom = { bind: bindImageZoom, imageZoomUrl };
})();
