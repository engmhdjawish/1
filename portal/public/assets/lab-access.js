(function () {
  'use strict';

  const SEARCH_KEY = 'lab-recent-q';
  const VIEWED_KEY = 'lab-recent-viewed';
  const MAX_SEARCHES = 6;
  const MAX_VIEWED = 12;

  const read = (key) => {
    try {
      const value = JSON.parse(window.localStorage.getItem(key) || '[]');
      return Array.isArray(value) ? value : [];
    } catch {
      return [];
    }
  };
  const write = (key, value) => {
    try {
      window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
      /* storage unavailable */
    }
  };

  const rememberSearch = (query) => {
    const q = String(query || '').trim().replace(/\s+/g, ' ');
    if (q.length < 2 || q.length > 80) return;
    const list = read(SEARCH_KEY).filter((item) => typeof item === 'string' && item !== q);
    list.unshift(q);
    write(SEARCH_KEY, list.slice(0, MAX_SEARCHES));
  };

  const rememberViewed = (card) => {
    let guid = card.getAttribute('data-preview-guid') || '';
    if (!guid) {
      const link = card.querySelector('a.store-product-card__link[href]');
      try {
        guid = link ? new URL(link.href, window.location.origin).searchParams.get('guid') || '' : '';
      } catch {
        guid = '';
      }
    }
    const title = (card.querySelector('.store-product-card__title, .home-product-card__name')?.textContent || '').trim();
    if (!guid || !title) return;
    const img = card.querySelector('img');
    const src = img ? (img.currentSrc || img.getAttribute('src') || '') : '';
    const entry = {
      guid,
      title: title.slice(0, 120),
      img: src.startsWith('data:') ? '' : src,
      href: `/product.php?guid=${encodeURIComponent(guid)}`,
    };
    const list = read(VIEWED_KEY).filter((item) => item && item.guid !== guid);
    list.unshift(entry);
    write(VIEWED_KEY, list.slice(0, MAX_VIEWED));
  };

  const icon = (name) => {
    const span = document.createElement('span');
    span.className = 'material-symbols-outlined';
    span.setAttribute('aria-hidden', 'true');
    span.textContent = name;
    return span;
  };

  const renderSearches = () => {
    const list = read(SEARCH_KEY).filter((item) => typeof item === 'string');
    document.querySelectorAll('[data-lab-recent-q]').forEach((box) => {
      const row = box.querySelector('[data-lab-recent-q-row]');
      if (!row) return;
      row.replaceChildren();
      if (list.length === 0) {
        box.hidden = true;
        return;
      }
      const form = box.closest('section')?.querySelector('form[data-hl-search]');
      const action = form?.getAttribute('action') || '/store-lab.php';
      list.forEach((q) => {
        const link = document.createElement('a');
        link.className = 'lab-recent-q__chip';
        link.href = `${action}?q=${encodeURIComponent(q)}`;
        link.append(icon('history'), document.createTextNode(q));
        row.appendChild(link);
      });
      box.hidden = false;
    });
  };

  const renderViewed = () => {
    const list = read(VIEWED_KEY).filter((item) => item && item.guid && item.title);
    document.querySelectorAll('[data-lab-recent-viewed]').forEach((box) => {
      const row = box.querySelector('[data-lab-recent-viewed-row]');
      if (!row) return;
      row.replaceChildren();
      if (list.length === 0) {
        box.hidden = true;
        return;
      }
      list.forEach((item) => {
        const link = document.createElement('a');
        link.className = 'lab-viewed__card';
        link.href = item.href;
        const media = document.createElement('span');
        media.className = 'lab-viewed__media';
        if (item.img) {
          const img = document.createElement('img');
          img.src = item.img;
          img.alt = '';
          img.loading = 'lazy';
          img.decoding = 'async';
          media.appendChild(img);
        } else {
          media.appendChild(icon('image'));
        }
        const name = document.createElement('span');
        name.className = 'lab-viewed__name';
        name.textContent = item.title;
        link.append(media, name);
        row.appendChild(link);
      });
      box.hidden = false;
    });
  };

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form instanceof HTMLFormElement && form.matches('.lab-finder__form')) {
      form.querySelectorAll('select').forEach((select) => {
        select.disabled = select.value === '';
      });
      window.setTimeout(() => form.querySelectorAll('select').forEach((select) => { select.disabled = false; }), 0);
      return;
    }
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-hl-search]')) return;
    const input = form.querySelector('[data-hl-search-input]');
    if (input instanceof HTMLInputElement) rememberSearch(input.value);
  }, true);

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const clear = target.closest('[data-lab-clear]');
    if (clear) {
      write(clear.getAttribute('data-lab-clear') === 'viewed' ? VIEWED_KEY : SEARCH_KEY, []);
      renderSearches();
      renderViewed();
      return;
    }

    const opener = target.closest('[data-store-product-preview], .store-product-card__link');
    const card = opener?.closest('[data-preview-guid], .store-product-card, .home-product-card');
    if (card) rememberViewed(card);

    const dockSearch = target.closest('[data-lab-dock-search]');
    const field = document.querySelector('[data-hl-search-input]');
    if (dockSearch && field instanceof HTMLInputElement) {
      event.preventDefault();
      const top = field.getBoundingClientRect().top + window.scrollY;
      const offset = (document.querySelector('.site-header')?.offsetHeight || 0) + 24;
      window.scrollTo({ top: Math.max(0, top - offset), behavior: 'smooth' });
      field.focus({ preventScroll: true });
    }
  }, true);

  const selectShopTab = (tab, focus) => {
    const shop = tab.closest('[data-lab-shop]');
    if (!shop) return;
    shop.querySelectorAll('[data-lab-shop-tab]').forEach((item) => {
      const active = item === tab;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-selected', active ? 'true' : 'false');
      item.tabIndex = active ? 0 : -1;
      const panel = document.getElementById(item.getAttribute('aria-controls') || '');
      if (panel) panel.hidden = !active;
    });
    tab.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
    if (focus) tab.focus();
  };

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const tab = target.closest('[data-lab-shop-tab]');
    if (tab) {
      selectShopTab(tab, false);
      return;
    }

    const pick = target.closest('[data-lab-shop-pick]');
    if (pick) {
      const combo = pick.closest('[data-lab-shop-combo]');
      const index = pick.getAttribute('data-lab-shop-pick');
      combo?.querySelectorAll('[data-lab-shop-pick]').forEach((item) => {
        const active = item === pick;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      combo?.querySelectorAll('[data-lab-shop-group]').forEach((group) => {
        group.hidden = group.getAttribute('data-lab-shop-group') !== index;
      });
      pick.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
      return;
    }

    const more = target.closest('[data-lab-shop-more]');
    if (more) {
      const list = more.previousElementSibling;
      const expanded = more.getAttribute('aria-expanded') !== 'true';
      list?.classList.toggle('is-expanded', expanded);
      more.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      more.textContent = more.getAttribute(expanded ? 'data-label-less' : 'data-label-more') || '';
    }
  });

  document.addEventListener('keydown', (event) => {
    const tab = event.target instanceof Element ? event.target.closest('[data-lab-shop-tab]') : null;
    if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    const tabs = Array.from(tab.parentElement?.querySelectorAll('[data-lab-shop-tab]') || []);
    const index = tabs.indexOf(tab);
    // RTL: ArrowLeft moves to the next tab.
    let next = index;
    if (event.key === 'ArrowLeft') next = (index + 1) % tabs.length;
    if (event.key === 'ArrowRight') next = (index - 1 + tabs.length) % tabs.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = tabs.length - 1;
    event.preventDefault();
    selectShopTab(tabs[next], true);
  });

  const init = () => {
    if (window.location.pathname === '/store-lab.php') {
      rememberSearch(new URLSearchParams(window.location.search).get('q') || '');
    }
    renderSearches();
    renderViewed();
    if (window.location.hash === '#sl-search-input') {
      document.getElementById('sl-search-input')?.focus();
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
