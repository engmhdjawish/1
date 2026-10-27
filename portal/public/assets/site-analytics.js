(() => {
  if (document.documentElement.dataset.analyticsOptOut === '1') return;

  const script = document.currentScript;
  const endpoint = script?.getAttribute('data-endpoint') || '/api/site-analytics.php';
  const STORAGE_KEY = 'jawish_vid';
  const GPS_STORAGE_KEY = 'jawish_gps';
  const GPS_MAX_AGE_MS = 300000;
  const GPS_WAIT_MS = 600;

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

  // Mobile browsers ignore getCurrentPosition during page load and do not
  // show the permission prompt. Ask from the first tap, or immediately when
  // permission is already granted / the device is a desktop pointer.
  const requestGps = () => new Promise((resolve) => {
    if (!window.isSecureContext || !navigator.geolocation) {
      resolve(null);
      return;
    }

    let settled = false;
    const finish = (coords) => {
      if (settled) return;
      settled = true;
      resolve(coords);
    };

    const readPosition = (highAccuracy) => {
      navigator.geolocation.getCurrentPosition(
        (position) => {
          const latitude = position.coords.latitude;
          const longitude = position.coords.longitude;
          if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            finish(null);
            return;
          }
          finish({
            latitude,
            longitude,
            accuracy: position.coords.accuracy,
          });
        },
        (error) => {
          const denied = error && error.code === 1;
          if (denied || !highAccuracy) {
            finish(null);
            return;
          }
          readPosition(false);
        },
        {
          enableHighAccuracy: highAccuracy,
          timeout: highAccuracy ? 20000 : 10000,
          maximumAge: highAccuracy ? 0 : 60000,
        },
      );
    };

    let started = false;
    const gestureEvents = ['pointerup', 'click', 'touchend', 'keydown'];
    const stopGesture = () => {
      gestureEvents.forEach((name) => window.removeEventListener(name, onGesture, true));
    };
    const start = () => {
      if (started || settled) return;
      started = true;
      stopGesture();
      readPosition(true);
    };
    const onGesture = () => start();

    const needsGesture = window.matchMedia('(pointer: coarse)').matches
      || /iphone|ipad|ipod|android/i.test(navigator.userAgent || '');

    const queryPermission = () => {
      if (!navigator.permissions || typeof navigator.permissions.query !== 'function') {
        return Promise.resolve('unknown');
      }
      return navigator.permissions.query({ name: 'geolocation' })
        .then((status) => (status && status.state) || 'unknown')
        .catch(() => 'unknown');
    };

    if (!needsGesture) {
      start();
      return;
    }

    gestureEvents.forEach((name) => window.addEventListener(name, onGesture, true));
    void queryPermission().then((state) => {
      if (settled) return;
      if (state === 'denied') {
        stopGesture();
        finish(null);
        return;
      }
      if (state === 'granted') {
        start();
      }
    });
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
      window.setTimeout(() => resolve(readCachedGps()), GPS_WAIT_MS);
    }),
  ]);

  if (window.isSecureContext && navigator.geolocation) {
    void resolveGps();
  }

  let pageViewPosted = false;
  let pageViewHadGps = false;

  const send = (action, meta = {}) => {
    void (async () => {
      const payloadMeta = meta && typeof meta === 'object' ? { ...meta } : {};
      const gps = action === 'gps_fix' ? readCachedGps() : await waitForGps();
      if (gps) {
        payloadMeta.gps_latitude = gps.latitude;
        payloadMeta.gps_longitude = gps.longitude;
        if (gps.accuracy != null) {
          payloadMeta.gps_accuracy = gps.accuracy;
        }
        if (action === 'page_view') pageViewHadGps = true;
      }
      if (action === 'page_view') pageViewPosted = true;

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
    ['materialTypes', 'material_type', 'manufacturers', 'manufacturer', 'ageCategories', 'target_category'].forEach((key) => {
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

  // The first page view must not wait on the permission prompt. When a fix
  // arrives afterwards, record it so the visitor map uses GPS instead of IP.
  void resolveGps().then((coords) => {
    if (!coords) return;
    window.setTimeout(() => {
      if (!pageViewPosted || pageViewHadGps) return;
      pageViewHadGps = true;
      send('gps_fix', { label_ar: 'موقع GPS' });
    }, 50);
  });

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
