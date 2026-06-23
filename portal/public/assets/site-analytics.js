(() => {
  if (document.documentElement.dataset.analyticsOptOut === '1') return;

  const STORAGE_KEY = 'jawish_vid';
  const getSessionId = () => {
    try {
      let id = localStorage.getItem(STORAGE_KEY);
      if (!id) {
        id = (window.crypto?.randomUUID?.() || `v-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`);
        localStorage.setItem(STORAGE_KEY, id);
      }
      return id;
    } catch {
      return `v-${Date.now()}`;
    }
  };

  const endpoint = '/api/site-analytics.php';
  const send = (action) => {
    const payload = {
      action,
      session_id: getSessionId(),
      path: `${window.location.pathname}${window.location.search}`,
      title: document.title,
      referer: document.referrer || '',
    };
    const body = JSON.stringify(payload);
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
        return;
      }
      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        keepalive: true,
        body,
      }).catch(() => {});
    } catch {
      /* ignore */
    }
  };

  if (document.readyState === 'complete') {
    send('page_view');
  } else {
    window.addEventListener('load', () => send('page_view'), { once: true });
  }
})();
