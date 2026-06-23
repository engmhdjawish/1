(() => {
  if (document.documentElement.dataset.analyticsOptOut === '1') return;

  const script = document.currentScript;
  const endpoint = script?.getAttribute('data-endpoint') || '/api/site-analytics.php';
  const STORAGE_KEY = 'jawish_vid';

  const getSessionId = () => {
    try {
      let id = localStorage.getItem(STORAGE_KEY);
      if (!id) {
        id = window.crypto?.randomUUID?.() || `v-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
        localStorage.setItem(STORAGE_KEY, id);
      }
      return id;
    } catch {
      return `v-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
    }
  };

  const send = (action, meta = {}) => {
    const payload = {
      action,
      session_id: getSessionId(),
      path: `${window.location.pathname}${window.location.search}`,
      title: document.title,
      referer: document.referrer || '',
      meta: meta && typeof meta === 'object' ? meta : {},
    };
    const body = JSON.stringify(payload);

    try {
      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        keepalive: true,
        body,
      }).catch(() => {
        if (navigator.sendBeacon) {
          navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
        }
      });
    } catch {
      /* ignore */
    }
  };

  const trackStoreContext = () => {
    if (!window.location.pathname.endsWith('/store.php')) return;
    const params = new URLSearchParams(window.location.search);
    const q = (params.get('q') || params.get('keyword') || '').trim();
    if (q !== '') {
      send('store_search', { search_q: q, label_ar: `بحث: ${q}` });
      return;
    }

    const filters = [];
    ['material_type', 'manufacturer', 'target_category'].forEach((key) => {
      params.getAll(key).forEach((value) => {
        const text = String(value || '').trim();
        if (text !== '') filters.push(text);
      });
    });
    if (filters.length > 0) {
      send('store_filter', {
        filter_summary: filters.slice(0, 4).join('، '),
        label_ar: `تصفية: ${filters.slice(0, 4).join('، ')}`,
      });
    }
  };

  const trackProductPage = () => {
    const el = document.querySelector('[data-analytics-product]');
    if (!el) return;
    send('product_view', {
      product_guid: el.getAttribute('data-product-guid') || '',
      product_code: el.getAttribute('data-product-code') || '',
      product_name: el.getAttribute('data-product-name') || '',
      label_ar: el.getAttribute('data-analytics-label') || '',
    });
  };

  const trackCartPage = () => {
    const path = window.location.pathname;
    if (path.endsWith('/store-cart.php') || path.endsWith('/cart.php')) {
      send('cart_view', { label_ar: 'عرض السلة' });
    }
  };

  const trackPageView = () => {
    send('page_view');
    trackStoreContext();
    trackProductPage();
    trackCartPage();
  };

  window.SiteAnalytics = { track: send };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', trackPageView, { once: true });
  } else {
    trackPageView();
  }

  window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      trackPageView();
    }
  });
})();
