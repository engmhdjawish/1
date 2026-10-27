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

  const GPS_LATER_KEY = 'jawish_gps_later';

  // Phones do not show the system location prompt for a call made on page load
  // or on touchend. iOS treats that call as a denial and then ignores the real
  // click. Ask only from the Allow button, or immediately when already granted.
  const requestGps = () => new Promise((resolve) => {
    if (!window.isSecureContext || !navigator.geolocation) {
      resolve(null);
      return;
    }

    let settled = false;
    let asked = false;
    let bar = null;
    const finish = (coords) => {
      if (settled) return;
      settled = true;
      bar?.remove();
      bar = null;
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
          if (!denied && highAccuracy) {
            readPosition(false);
            return;
          }
          if (denied && bar) {
            const text = bar.querySelector('[data-gps-text]');
            const allow = bar.querySelector('[data-gps-allow]');
            if (text) {
              text.textContent = 'الموقع مرفوض. فعّله من إعدادات المتصفح لهذا الموقع ثم أعد المحاولة.';
            }
            if (allow) allow.textContent = 'إعادة المحاولة';
            asked = false;
            return;
          }
          finish(null);
        },
        {
          enableHighAccuracy: highAccuracy,
          timeout: highAccuracy ? 20000 : 10000,
          maximumAge: highAccuracy ? 0 : 60000,
        },
      );
    };

    const needsTap = window.matchMedia('(pointer: coarse), (any-pointer: coarse)').matches
      || /iphone|ipad|ipod|android|mobile/i.test(navigator.userAgent || '');

    const queryPermission = () => {
      if (!navigator.permissions || typeof navigator.permissions.query !== 'function') {
        return Promise.resolve('unknown');
      }
      return navigator.permissions.query({ name: 'geolocation' })
        .then((status) => (status && status.state) || 'unknown')
        .catch(() => 'unknown');
    };

    const showAskBar = () => {
      if (bar || settled) return;
      try {
        if (sessionStorage.getItem(GPS_LATER_KEY) === '1') {
          finish(null);
          return;
        }
      } catch {
        /* ignore */
      }

      const styleId = 'gps-ask-style';
      if (!document.getElementById(styleId)) {
        const style = document.createElement('style');
        style.id = styleId;
        style.textContent = [
          '.gps-ask{position:fixed;z-index:60;left:12px;right:12px;bottom:calc(12px + env(safe-area-inset-bottom));display:flex;align-items:center;gap:8px;padding:12px 14px;border-radius:16px;background:#111827;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,.28);font:600 13px/1.45 Manrope,system-ui,sans-serif}',
          'body.has-app-dock .gps-ask,body:has(.hn-dock) .gps-ask,body:has(.lab-dock) .gps-ask{bottom:calc(76px + env(safe-area-inset-bottom))}',
          '.gps-ask [data-gps-text]{flex:1;margin:0}',
          '.gps-ask button{border:0;border-radius:999px;padding:8px 14px;font:700 13px/1 inherit;cursor:pointer}',
          '.gps-ask [data-gps-allow]{background:#D81921;color:#fff}',
          '.gps-ask [data-gps-later]{background:transparent;color:#e5e7eb}',
        ].join('');
        document.head.appendChild(style);
      }

      bar = document.createElement('div');
      bar.className = 'gps-ask';
      bar.setAttribute('role', 'dialog');
      bar.setAttribute('aria-label', 'السماح بالموقع');
      const text = document.createElement('p');
      text.dataset.gpsText = '';
      text.textContent = 'اسمح بالموقع ليظهر موقعك على الخريطة.';
      const allow = document.createElement('button');
      allow.type = 'button';
      allow.dataset.gpsAllow = '';
      allow.textContent = 'سماح بالموقع';
      const later = document.createElement('button');
      later.type = 'button';
      later.dataset.gpsLater = '';
      later.textContent = 'لاحقاً';
      allow.addEventListener('click', (event) => {
        event.preventDefault();
        if (asked || settled) return;
        asked = true;
        readPosition(true);
      });
      later.addEventListener('click', (event) => {
        event.preventDefault();
        try {
          sessionStorage.setItem(GPS_LATER_KEY, '1');
        } catch {
          /* ignore */
        }
        finish(null);
      });
      bar.append(text, allow, later);
      document.body.appendChild(bar);
    };

    void queryPermission().then((state) => {
      if (settled) return;
      if (state === 'granted' || (state !== 'denied' && !needsTap)) {
        readPosition(true);
        return;
      }
      if (state === 'denied' && !needsTap) {
        finish(null);
        return;
      }
      const mount = () => {
        showAskBar();
        if (state !== 'denied' || !bar) return;
        const text = bar.querySelector('[data-gps-text]');
        const allow = bar.querySelector('[data-gps-allow]');
        if (text) {
          text.textContent = 'الموقع مرفوض. فعّله من إعدادات المتصفح لهذا الموقع ثم أعد المحاولة.';
        }
        if (allow) allow.textContent = 'إعادة المحاولة';
      };
      if (document.body) mount();
      else document.addEventListener('DOMContentLoaded', mount, { once: true });
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
