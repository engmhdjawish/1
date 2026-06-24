/**
 * Deferred image loading — avoids blocking navigation on many concurrent /api/image.php requests.
 */
(function () {
  'use strict';

  const observers = new WeakMap();

  function loadImg(img) {
    if (!(img instanceof HTMLImageElement)) return;
    const src = img.getAttribute('data-deferred-src');
    if (!src || img.getAttribute('data-deferred-loaded') === '1') return;
    img.setAttribute('data-deferred-loaded', '1');
    img.src = src;
    img.removeAttribute('data-deferred-src');
  }

  function observe(root) {
    if (!root) return;
    const imgs = root.querySelectorAll('img[data-deferred-src]:not([data-deferred-loaded])');
    if (!imgs.length) return;

    if (!('IntersectionObserver' in window)) {
      imgs.forEach(loadImg);
      return;
    }

    let observer = observers.get(root);
    if (!observer) {
      observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          loadImg(entry.target);
          observer.unobserve(entry.target);
        });
      }, { rootMargin: '160px' });
      observers.set(root, observer);
    }

    imgs.forEach((img) => observer.observe(img));
  }

  function cancel(root) {
    if (!root) return;
    const observer = observers.get(root);
    if (observer) {
      observer.disconnect();
      observers.delete(root);
    }
    root.querySelectorAll('img[data-deferred-src]').forEach((img) => {
      img.removeAttribute('data-deferred-src');
    });
    root.querySelectorAll('img[data-deferred-loaded]').forEach((img) => {
      img.removeAttribute('src');
      img.removeAttribute('data-deferred-loaded');
    });
  }

  window.portalDeferredImages = { observe, cancel, loadImg };
})();
