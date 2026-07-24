(() => {
  if (document.documentElement.dataset.analyticsOptOut === '1') return;

  const script = document.currentScript;
  const endpoint = script?.getAttribute('data-endpoint') || '/api/site-analytics.php';
  const STORAGE_KEY = 'jawish_vid';
  const GPS_STORAGE_KEY = 'jawish_gps';
  const GPS_MAX_AGE_MS = 300000;
  const GPS_WAIT_MS = 2500;

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

  const readCachedGps = () => {
    try {
      const raw = sessionStorage.getItem(GPS_STORAGE_KEY);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object') return null;
      const age = Date.now() - Number(parsed.ts || 0);
      if (!Number.isFinite(age) || age > GPS_MAX_AGE_MS) return null;
      const latitude = Number(parsed.latitude);
      const longitude = Number(parsed.longitude);
      if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return null;
      return {
        latitude,
        longitude,
        accuracy: Number.isFinite(Number(parsed.accuracy)) ? Number(parsed.accuracy) : null,
      };
    } catch {
      return null;
    }
  };

  const writeCachedGps = (coords) => {
    try {
      sessionStorage.setItem(GPS_STORAGE_KEY, JSON.stringify({
        latitude: coords.latitude,
        longitude: coords.longitude,
        accuracy: coords.accuracy,
        ts: Date.now(),
      }));
    } catch {
      /* ignore */
    }
  };

  const requestGps = () => new Promise((resolve) => {
    if (!window.isSecureContext || !navigator.geolocation) {
      resolve(null);
      return;
    }

    navigator.geolocation.getCurrentPosition(
      (position) => {
        resolve({
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy,
        });
      },
      () => resolve(null),
      {
        enableHighAccuracy: false,
        timeout: 8000,
        maximumAge: GPS_MAX_AGE_MS,
      },
    );
  });

  let gpsPromise = null;
  const resolveGps = () => {
    const cached = readCachedGps();
    if (cached) return Promise.resolve(cached);
    if (!gpsPromise) {
      gpsPromise = requestGps().then((coords) => {
        if (coords) writeCachedGps(coords);
        return coords;
      });
    }
    return gpsPromise;
  };

  const waitForGps = () => Promise.race([
    resolveGps(),
    new Promise((resolve) => {
      window.setTimeout(() => resolve(null), GPS_WAIT_MS);
    }),
  ]);

  if (window.isSecureContext && navigator.geolocation) {
    void resolveGps();
  }

  const send = (action, meta = {}) => {
    void (async () => {
      const payloadMeta = meta && typeof meta === 'object' ? { ...meta } : {};
      const gps = await waitForGps();
      if (gps) {
        payloadMeta.gps_latitude = gps.latitude;
        payloadMeta.gps_longitude = gps.longitude;
        if (gps.accuracy != null) {
          payloadMeta.gps_accuracy = gps.accuracy;
        }
      }

      const payload = {
        action,
        session_id: getSessionId(),
        path: `${window.location.pathname}${window.location.search}`,
        title: document.title,
        referer: document.referrer || '',
        meta: payloadMeta,
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
    })();
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
