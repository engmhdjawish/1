(() => {
  const API = '/api/dashboard-order-price-pref.php';

  const setActive = (currency) => {
    document.querySelectorAll('[data-dashboard-order-currency]').forEach((btn) => {
      const isActive = (btn.getAttribute('data-dashboard-order-currency') || '') === currency;
      btn.classList.toggle('is-active', isActive);
      btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
  };

  document.querySelectorAll('[data-dashboard-order-currency]').forEach((btn) => {
    if (btn.dataset.orderCurrencyBound === '1') return;
    btn.dataset.orderCurrencyBound = '1';
    btn.addEventListener('click', async () => {
      const currency = btn.getAttribute('data-dashboard-order-currency') || '';
      if (!currency || btn.classList.contains('is-active')) return;
      btn.disabled = true;
      try {
        const res = await fetch(API, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ currency }),
        });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) return;
        setActive(currency);
        window.location.reload();
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
