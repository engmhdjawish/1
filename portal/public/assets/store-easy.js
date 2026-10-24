(function () {
  'use strict';

  const DENSITY_KEY = 'se-density';
  const live = () => document.querySelector('[data-se-live]');
  const pills = () => Array.from(document.querySelectorAll('[data-se-pill]'));
  const phone = () => window.matchMedia('(max-width: 639px)').matches;

  let navAbort = null;
  let navGeneration = 0;
  let applyTimer = 0;
  let pendingHref = '';

  const isLabUrl = (href) => {
    try {
      const url = new URL(href, window.location.origin);
      return url.origin === window.location.origin && url.pathname === '/store.php';
    } catch {
      return false;
    }
  };

  const urlFromBar = (form) => {
    const params = new URLSearchParams();
    new FormData(form).forEach((value, key) => {
      const text = String(value).trim();
      if (text !== '') params.append(key, text);
    });
    const query = params.toString();
    return '/store.php' + (query ? `?${query}` : '');
  };

  const syncSheetLock = () => {
    document.documentElement.classList.toggle('se-sheet-open', phone() && pills().some((pill) => pill.open));
  };

  const closePills = (except = null) => {
    pills().forEach((pill) => {
      if (pill !== except) pill.open = false;
    });
    syncSheetLock();
  };

  const syncSearchCarry = (href) => {
    const form = document.querySelector('form[data-store-search]');
    if (!(form instanceof HTMLFormElement)) return;
    form.querySelectorAll('input[type="hidden"]').forEach((input) => input.remove());
    const params = new URL(href, window.location.origin).searchParams;
    params.forEach((value, key) => {
      if (key === 'q' || key === 'page') return;
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = key;
      input.value = value;
      form.prepend(input);
    });
  };

  const bindCards = (root) => {
    window.StoreCart?.bindAddForms?.();
    window.StoreCart?.bindQtySteppers?.(root);
    window.StoreImageZoom?.seedLoadedImages?.(root);
  };

  const applyDensity = () => {
    const density = window.localStorage?.getItem(DENSITY_KEY) === '2' ? '2' : '1';
    document.documentElement.classList.toggle('se-dense', density === '2');
    document.querySelectorAll('[data-se-density]').forEach((group) => {
      group.hidden = false;
      group.querySelectorAll('[data-se-density-set]').forEach((btn) => {
        btn.setAttribute('aria-pressed', btn.getAttribute('data-se-density-set') === density ? 'true' : 'false');
      });
    });
  };

  // Load the next batch well before the end of the list, so a fast scroll keeps meeting
  // materials instead of the footer.
  let moreObserver = null;
  const watchMore = (root) => {
    moreObserver?.disconnect();
    const more = root?.querySelector('[data-se-more]');
    if (!more || !('IntersectionObserver' in window)) return;
    moreObserver = new IntersectionObserver((entries) => {
      if (!entries.some((entry) => entry.isIntersecting)) return;
      const button = live()?.querySelector('[data-se-more-btn]');
      if (button instanceof HTMLButtonElement && !more.hidden) loadMore(button);
    }, { rootMargin: '0px 0px 1600px 0px' });
    moreObserver.observe(more);
  };

  const prepareLive = (root) => {
    if (!root) return;
    root.querySelector('.store-pagination')?.setAttribute('hidden', '');
    const more = root.querySelector('[data-se-more]');
    if (more) {
      more.hidden = false;
      more.classList.add('is-auto');
    }
    applyDensity();
    watchMore(root);
  };

  const navigate = async (href, options = {}) => {
    const current = live();
    if (!current || !isLabUrl(href)) {
      window.location.href = href;
      return;
    }
    const keepParam = options.keepParam || '';
    const keepPill = keepParam ? document.querySelector(`[data-se-pill][data-se-param="${keepParam}"]`) : null;
    const listScroll = keepPill?.querySelector('.se-pop__list')?.scrollTop || 0;
    const findValue = keepPill?.querySelector('[data-se-find]')?.value || '';

    navAbort?.abort();
    navAbort = new AbortController();
    const generation = ++navGeneration;
    pendingHref = href;
    current.classList.add('is-loading');
    current.setAttribute('aria-busy', 'true');

    try {
      const response = await fetch(href, {
        credentials: 'same-origin',
        headers: { Accept: 'text/html' },
        signal: navAbort.signal,
      });
      if (!response.ok) throw new Error(String(response.status));
      const html = await response.text();
      if (generation !== navGeneration) return;
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const next = doc.querySelector('[data-se-live]');
      if (!next) throw new Error('missing');

      current.replaceWith(next);
      if (doc.title) document.title = doc.title;
      if (options.push !== false) history.pushState({ seUrl: href }, '', href);
      syncSearchCarry(href);
      prepareLive(next);
      bindCards(next);

      if (keepParam) {
        const pill = next.querySelector(`[data-se-pill][data-se-param="${keepParam}"]`);
        if (pill instanceof HTMLDetailsElement) {
          pill.open = true;
          const list = pill.querySelector('.se-pop__list');
          if (list) list.scrollTop = listScroll;
          const find = pill.querySelector('[data-se-find]');
          if (find instanceof HTMLInputElement && findValue) {
            find.value = findValue;
            find.dispatchEvent(new Event('input', { bubbles: true }));
          }
          const apply = pill.querySelector('[data-se-apply]');
          const total = Number(next.getAttribute('data-se-total') || '0');
          if (apply) apply.textContent = total > 0 ? `عرض النتائج (${total})` : 'لا توجد مواد مطابقة';
        }
      } else {
        closePills();
        const top = next.getBoundingClientRect().top + window.scrollY;
        const offset = (document.querySelector('.site-header')?.offsetHeight || 0) + 12;
        if (window.scrollY > top - offset) {
          window.scrollTo({ top: Math.max(0, top - offset), behavior: 'smooth' });
        }
      }
      syncSheetLock();
    } catch (error) {
      if (error?.name === 'AbortError') return;
      window.location.href = href;
    } finally {
      if (generation === navGeneration) {
        pendingHref = '';
        live()?.classList.remove('is-loading');
        live()?.removeAttribute('aria-busy');
      }
    }
  };

  // Chips are removed on the spot and their URL is rebuilt from the latest requested state,
  // so several filters can be cleared in a row without waiting for each response.
  const removeChip = (chip) => {
    const param = chip.getAttribute('data-se-chip') || '';
    const value = chip.getAttribute('data-se-chip-value');
    const url = new URL(pendingHref || window.location.href, window.location.origin);
    url.searchParams.delete('page');
    const matches = (key) => key === param || key === `${param}[]` || new RegExp(`^${param}\\[\\d+\\]$`).test(key);
    const kept = Array.from(url.searchParams.entries()).filter(([key, item]) => !matches(key) || (value !== null && item !== value));
    url.search = '';
    kept.forEach(([key, item]) => url.searchParams.append(key.replace(/\[\d+\]$/, '[]'), item));

    const bar = document.querySelector('[data-se-bar]');
    bar?.querySelectorAll('input').forEach((input) => {
      if (!(input instanceof HTMLInputElement)) return;
      const name = input.name.replace(/\[\]$/, '');
      if (name !== param || (value !== null && input.value !== value)) return;
      if (input.type === 'checkbox') {
        input.checked = false;
        input.closest('.se-opt')?.classList.remove('is-checked');
        input.closest('.se-toggle')?.classList.remove('is-on');
      } else if (input.type === 'hidden') {
        input.remove();
      }
    });

    const group = chip.closest('.se-chips');
    chip.remove();
    if (group && !group.querySelector('[data-se-chip]')) group.querySelector('.se-chip--clear')?.remove();
    navigate(`${url.pathname}${url.search}`);
  };

  const loadMore = async (button) => {
    const root = live();
    const nextUrl = root?.getAttribute('data-se-next') || '';
    if (!root || !nextUrl || button.disabled) return;
    button.disabled = true;
    button.classList.add('is-loading');
    try {
      const response = await fetch(nextUrl, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
      if (!response.ok) throw new Error(String(response.status));
      const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
      const nextLive = doc.querySelector('[data-se-live]');
      const grid = root.querySelector('.se-grid');
      const cards = nextLive ? Array.from(nextLive.querySelectorAll('.se-grid > *')) : [];
      if (!grid || !nextLive || cards.length === 0) throw new Error('empty');
      const fragment = document.createDocumentFragment();
      cards.forEach((card) => fragment.appendChild(document.importNode(card, true)));
      grid.appendChild(fragment);
      bindCards(grid);

      const following = nextLive.getAttribute('data-se-next') || '';
      const shown = Number(nextLive.getAttribute('data-se-shown') || '0');
      const total = Number(root.getAttribute('data-se-total') || '0');
      root.setAttribute('data-se-next', following);
      root.setAttribute('data-se-shown', String(shown));
      const shownLabel = root.querySelector('[data-se-more-shown]');
      if (shownLabel) shownLabel.textContent = String(shown);
      const fill = root.querySelector('[data-se-more-fill]');
      if (fill instanceof HTMLElement && total > 0) fill.style.width = `${Math.min(100, Math.round((shown * 100) / total))}%`;
      const paging = nextLive.querySelector('[data-store-preview-paging]');
      const currentPaging = root.querySelector('[data-store-preview-paging]');
      if (paging && currentPaging) currentPaging.textContent = paging.textContent;
      if (!following) {
        root.querySelector('[data-se-more]')?.setAttribute('hidden', '');
        moreObserver?.disconnect();
      } else {
        window.requestAnimationFrame(() => {
          const more = root.querySelector('[data-se-more]');
          if (more && more.getBoundingClientRect().top < window.innerHeight + 1600) loadMore(button);
        });
      }
    } catch {
      window.location.href = nextUrl;
      return;
    } finally {
      button.disabled = false;
      button.classList.remove('is-loading');
    }
  };

  document.addEventListener('toggle', (event) => {
    const pill = event.target;
    if (!(pill instanceof HTMLDetailsElement) || !pill.matches('[data-se-pill]')) return;
    if (pill.open) {
      closePills(pill);
      const find = pill.querySelector('[data-se-find]');
      if (find instanceof HTMLInputElement && !phone()) find.focus();
    }
    syncSheetLock();
  }, true);

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    if (target.closest('[data-se-close]')) {
      const pill = target.closest('[data-se-pill]');
      if (pill instanceof HTMLDetailsElement) pill.open = false;
      syncSheetLock();
      return;
    }

    const densityBtn = target.closest('[data-se-density-set]');
    if (densityBtn) {
      window.localStorage?.setItem(DENSITY_KEY, densityBtn.getAttribute('data-se-density-set') || '1');
      applyDensity();
      return;
    }

    const moreBtn = target.closest('[data-se-more-btn]');
    if (moreBtn instanceof HTMLButtonElement) {
      loadMore(moreBtn);
      return;
    }

    pills().forEach((pill) => {
      if (pill.open && !pill.contains(target)) pill.open = false;
    });
    syncSheetLock();

    const link = target.closest('a[href]');
    if (!(link instanceof HTMLAnchorElement) || !link.closest('[data-se-live]')) return;
    if (link.closest('.store-product-card')) return;
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (!isLabUrl(link.href)) return;
    event.preventDefault();
    if (link.matches('[data-se-chip]')) {
      removeChip(link);
      return;
    }
    navigate(link.href);
  });

  document.addEventListener('change', (event) => {
    const control = event.target;
    if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement)) return;
    const form = control.closest('[data-se-bar]');
    if (!(form instanceof HTMLFormElement)) return;

    if (control.matches('[data-se-autosubmit]')) {
      control.closest('.se-toggle')?.classList.toggle('is-on', control instanceof HTMLInputElement && control.checked);
      navigate(urlFromBar(form));
      return;
    }

    const pill = control.closest('[data-se-pill]');
    if (control instanceof HTMLInputElement && control.type === 'checkbox' && pill) {
      control.closest('.se-opt')?.classList.toggle('is-checked', control.checked);
      window.clearTimeout(applyTimer);
      applyTimer = window.setTimeout(() => {
        navigate(urlFromBar(form), { keepParam: pill.getAttribute('data-se-param') || '' });
      }, 280);
    }
  });

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-se-bar]')) return;
    event.preventDefault();
    window.clearTimeout(applyTimer);
    const href = urlFromBar(form);
    const current = new URL(window.location.href);
    if (`${current.pathname}${current.search}` === href) {
      closePills();
      return;
    }
    navigate(href);
  });

  document.addEventListener('input', (event) => {
    const find = event.target;
    if (!(find instanceof HTMLInputElement) || !find.matches('[data-se-find]')) return;
    const needle = find.value.trim().toLowerCase();
    find.closest('[data-se-pill]')?.querySelectorAll('[data-se-opt]').forEach((option) => {
      const text = (option.getAttribute('data-se-opt') || '').toLowerCase();
      option.hidden = needle !== '' && !text.includes(needle);
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closePills();
      return;
    }
    const target = event.target;
    if (event.key === 'Enter' && target instanceof HTMLInputElement && target.matches('[data-se-find]')) {
      event.preventDefault();
    }
  });

  window.addEventListener('popstate', () => {
    if (live() && isLabUrl(window.location.href)) navigate(window.location.href, { push: false });
  });

  const init = () => {
    if (!live()) return;
    history.replaceState({ seUrl: window.location.href }, '', window.location.href);
    prepareLive(live());
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
