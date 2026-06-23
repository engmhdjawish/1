(() => {
  const API = '/api/store-price-pref.php';

  const setActive = (currency) => {
    document.querySelectorAll('[data-store-currency]').forEach((btn) => {
      const isActive = (btn.getAttribute('data-store-currency') || '') === currency;
      btn.classList.toggle('is-active', isActive);
      btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
  };

  document.querySelectorAll('[data-store-currency]').forEach((btn) => {
    if (btn.dataset.currencyBound === '1') return;
    btn.dataset.currencyBound = '1';
    btn.setAttribute('aria-pressed', btn.classList.contains('is-active') ? 'true' : 'false');
    btn.addEventListener('click', async () => {
      const currency = btn.getAttribute('data-store-currency') || '';
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
      } catch {
        btn.disabled = false;
      }
    });
  });
})();
