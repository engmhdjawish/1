/**
 * Deferred image loading with concurrency cap — keeps navigation responsive.
 */
(function () {
  'use strict';

  const MAX_CONCURRENT = 3;
  const observers = new WeakMap();
  let activeLoads = 0;
  const loadQueue = [];

  function loadImg(img) {
    if (!(img instanceof HTMLImageElement)) return;
    const src = img.getAttribute('data-deferred-src');
    if (!src || img.getAttribute('data-deferred-loaded') === '1') return;
    img.setAttribute('data-deferred-loaded', '1');
    img.removeAttribute('data-deferred-src');
    img.addEventListener('load', onImgDone, { once: true });
    img.addEventListener('error', onImgDone, { once: true });
    img.src = src;
    activeLoads += 1;
  }

  function onImgDone() {
    activeLoads = Math.max(0, activeLoads - 1);
    drainQueue();
  }

  function drainQueue() {
    while (activeLoads < MAX_CONCURRENT && loadQueue.length > 0) {
      const next = loadQueue.shift();
      if (next instanceof HTMLImageElement && next.isConnected) {
        loadImg(next);
      }
    }
  }

  function enqueue(img) {
    if (!(img instanceof HTMLImageElement)) return;
    if (img.getAttribute('data-deferred-loaded') === '1') return;
    if (!img.getAttribute('data-deferred-src')) return;
    if (loadQueue.includes(img)) return;
    loadQueue.push(img);
    drainQueue();
  }

  function flushVisible(root) {
    if (!root) return;
    const margin = 200;
    root.querySelectorAll('img[data-deferred-src]:not([data-deferred-loaded])').forEach((img) => {
      const rect = img.getBoundingClientRect();
      if (rect.bottom >= -margin && rect.top <= window.innerHeight + margin) {
        enqueue(img);
      }
    });
  }

  function observe(root) {
    if (!root) return;
    const imgs = root.querySelectorAll('img[data-deferred-src]:not([data-deferred-loaded])');
    if (!imgs.length) return;

    flushVisible(root);

    if (!('IntersectionObserver' in window)) {
      imgs.forEach(enqueue);
      return;
    }

    let observer = observers.get(root);
    if (observer) {
      observer.disconnect();
    }

    observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        enqueue(entry.target);
        observer.unobserve(entry.target);
      });
    }, { rootMargin: '200px' });
    observers.set(root, observer);
    imgs.forEach((img) => observer.observe(img));

    requestAnimationFrame(() => flushVisible(root));
  }

  function cancel(root) {
    if (root) {
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

    for (let i = loadQueue.length - 1; i >= 0; i -= 1) {
      const img = loadQueue[i];
      if (!root || !img.isConnected || root.contains(img)) {
        loadQueue.splice(i, 1);
      }
    }
  }

  window.portalDeferredImages = { observe, cancel, enqueue, flushVisible };
})();
