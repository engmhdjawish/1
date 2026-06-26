/**
 * Store catalog — AJAX navigation with immediate loading state.
 */
(function () {
  'use strict';

  const NAV_HEADER = 'X-Store-Nav';
  let navAbort = null;
  let navGeneration = 0;
  let navBusy = false;

  function isStoreCatalogUrl(url) {
    try {
      const parsed = new URL(url, window.location.origin);
      if (parsed.origin !== window.location.origin) return false;
      const path = parsed.pathname.replace(/\/+$/, '') || '/';
      return path === '/store.php' || path === '/store';
    } catch {
      return false;
    }
  }

  function catalogRoot() {
    return document.querySelector('[data-store-catalog-root]');
  }

  function skeletonCardHtml(includeFooter = false) {
    const footer = includeFooter
      ? '<div class="store-product-card__footer store-product-card__footer--skeleton">'
        + '<div class="store-skeleton-block store-skeleton-block--footer"></div>'
        + '</div>'
      : '';

    return '<article class="store-product-card store-product-card--skeleton" aria-hidden="true">'
      + '<div class="store-product-card__media store-product-card__media--skeleton">'
      + '<div class="store-skeleton-block store-skeleton-block--image"></div>'
      + '</div>'
      + '<div class="store-product-card__body store-product-card__body--skeleton">'
      + '<div class="store-skeleton-block store-skeleton-block--title"></div>'
      + '<div class="store-skeleton-block store-skeleton-block--line"></div>'
      + '<div class="store-skeleton-block store-skeleton-block--line store-skeleton-block--short"></div>'
      + '<div class="store-skeleton-block store-skeleton-block--line store-skeleton-block--price"></div>'
      + '</div>'
      + footer
      + '</article>';
  }

  function inferSkeletonCardCount(results) {
    const grid = results.querySelector('.store-product-grid');
    const currentCards = grid ? grid.querySelectorAll('.store-product-card:not(.store-product-card--skeleton)').length : 0;
    if (currentCards > 0) {
      return currentCards;
    }

    const meta = results.querySelector('.store-results-meta')?.textContent || '';
    const rangeMatch = meta.match(/عرض\s+(\d+)\s*[–-]\s*(\d+)/u);
    if (rangeMatch) {
      const start = Number.parseInt(rangeMatch[1], 10);
      const end = Number.parseInt(rangeMatch[2], 10);
      if (Number.isFinite(start) && Number.isFinite(end) && end >= start) {
        return Math.max(1, end - start + 1);
      }
    }

    const columns = window.matchMedia('(min-width: 1280px)').matches ? 4
      : window.matchMedia('(min-width: 1024px)').matches ? 3
        : window.matchMedia('(min-width: 640px)').matches ? 2
          : 1;

    return Math.max(columns * 3, 8);
  }

  function showCatalogLoading(root) {
    if (!root) return;
    root.setAttribute('aria-busy', 'true');
    root.classList.add('is-catalog-loading');

    const results = root.querySelector('.store-results');
    if (!results) return;

    results.classList.add('is-loading');

    const grid = results.querySelector('.store-product-grid');
    const hadFooter = Boolean(grid?.querySelector('.store-product-card__footer'));
    const cardCount = inferSkeletonCardCount(results);

    results.querySelector('.store-empty-state')?.remove();

    let targetGrid = grid;
    if (!targetGrid) {
      targetGrid = document.createElement('div');
      targetGrid.className = 'store-product-grid';
      const toolbar = results.querySelector('.store-results-toolbar');
      if (toolbar?.nextElementSibling) {
        results.insertBefore(targetGrid, toolbar.nextElementSibling);
      } else {
        results.appendChild(targetGrid);
      }
    }

    targetGrid.classList.add('store-product-grid--loading');
    targetGrid.setAttribute('aria-busy', 'true');
    targetGrid.innerHTML = Array.from({ length: cardCount }, () => skeletonCardHtml(hadFooter)).join('');

    const meta = results.querySelector('.store-results-meta');
    if (meta) {
      meta.textContent = 'جاري تحميل المواد...';
      meta.classList.add('is-loading');
    }

    results.querySelectorAll('.store-pagination a, .store-pagination button').forEach((el) => {
      el.setAttribute('aria-disabled', 'true');
      el.classList.add('is-disabled');
      el.tabIndex = -1;
    });
  }

  function syncPreviewPaging(root) {
    const script = root?.querySelector('[data-store-preview-paging]');
    if (!script) return;
    try {
      window.__storePreviewPaging = JSON.parse(script.textContent || '{}');
    } catch {
      window.__storePreviewPaging = {};
    }
  }

  function closeFilterDrawer() {
    document.body.classList.remove('store-filters-drawer-open');
    const backdrop = document.getElementById('store-filters-backdrop');
    if (backdrop) {
      backdrop.classList.remove('is-open');
      backdrop.setAttribute('aria-hidden', 'true');
    }
  }

  function bindCatalogForms(root) {
    root.querySelectorAll('form').forEach((form) => {
      if (form.dataset.storeNavBound === '1') return;
      if (form.method && form.method.toLowerCase() !== 'get') return;
      form.dataset.storeNavBound = '1';
      form.addEventListener('submit', (event) => {
        if (form.dataset.storeSubmitting === '1') {
          event.preventDefault();
          return;
        }
        event.preventDefault();
        form.dataset.storeSubmitting = '1';
        const submitter = event.submitter;
        const formData = new FormData(form);
        if (submitter instanceof HTMLElement && submitter.name) {
          formData.set(submitter.name, submitter.value);
        }
        const action = form.getAttribute('action') || window.location.pathname;
        const url = new URL(action, window.location.origin);
        formData.forEach((value, key) => {
          if (key.endsWith('[]')) {
            url.searchParams.append(key, value);
          } else {
            url.searchParams.set(key, value);
          }
        });
        closeFilterDrawer();
        navigateStore(url.toString());
      });
    });
  }

  function bindCatalogLinks(root) {
    root.querySelectorAll('a[href]').forEach((link) => {
      if (link.dataset.storeNavBound === '1') return;
      const href = link.getAttribute('href');
      if (!href || !isStoreCatalogUrl(href)) return;
      if (link.target === '_blank' || link.hasAttribute('download')) return;
      link.dataset.storeNavBound = '1';
      link.addEventListener('click', (event) => {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        closeFilterDrawer();
        navigateStore(href);
      });
    });
  }

  function bindCatalog(root = catalogRoot()) {
    if (!root) return;
    bindCatalogForms(root);
    bindCatalogLinks(root);
    syncPreviewPaging(root);
    if (typeof window.portalStoreFiltersInit === 'function') {
      window.portalStoreFiltersInit(root);
    }
  }

  async function navigateStore(url, push = true) {
    if (!isStoreCatalogUrl(url)) {
      window.location.href = url;
      return;
    }

    if (navBusy) {
      return;
    }

    if (navAbort) {
      navAbort.abort();
    }
    const generation = ++navGeneration;
    navAbort = new AbortController();
    const signal = navAbort.signal;
    navBusy = true;
    document.body.classList.add('store-catalog-nav-busy');

    const root = catalogRoot();
    showCatalogLoading(root);
    window.scrollTo({ top: root ? root.offsetTop - 80 : 0, behavior: 'smooth' });

    try {
      const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { [NAV_HEADER]: '1', Accept: 'text/html' },
        signal,
      });
      if (generation !== navGeneration) return;
      if (!response.ok) throw new Error('تعذر تحميل المتجر.');

      const html = await response.text();
      if (generation !== navGeneration) return;

      const doc = new DOMParser().parseFromString(html, 'text/html');
      const newRoot = doc.querySelector('[data-store-catalog-root]');
      const currentRoot = catalogRoot();
      if (!newRoot || !currentRoot) {
        window.location.href = url;
        return;
      }

      currentRoot.replaceWith(newRoot);
      newRoot.classList.remove('is-catalog-loading');
      newRoot.removeAttribute('aria-busy');
      newRoot.querySelector('.store-results')?.classList.remove('is-loading');
      newRoot.querySelector('.store-results-meta')?.classList.remove('is-loading');

      if (doc.title) document.title = doc.title;
      if (push) history.pushState({ storeCatalogUrl: url }, '', url);
      bindCatalog(newRoot);
    } catch (error) {
      if (error?.name === 'AbortError') return;
      const currentRoot = catalogRoot();
      if (currentRoot) {
        currentRoot.classList.remove('is-catalog-loading');
        currentRoot.removeAttribute('aria-busy');
        const results = currentRoot.querySelector('.store-results');
        if (results) {
          results.classList.remove('is-loading');
          const grid = results.querySelector('.store-product-grid');
          if (grid) {
            grid.classList.remove('store-product-grid--loading');
            grid.removeAttribute('aria-busy');
            grid.innerHTML = '<div class="store-empty-state">'
              + (error instanceof Error ? error.message : 'تعذر تحميل المتجر.')
              + '</div>';
          }
        }
      }
    } finally {
      if (generation === navGeneration) {
        navBusy = false;
        document.body.classList.remove('store-catalog-nav-busy');
        catalogRoot()?.querySelectorAll('form[data-store-submitting]').forEach((form) => {
          delete form.dataset.storeSubmitting;
        });
      }
    }
  }

  function bindHistory() {
    window.addEventListener('popstate', (event) => {
      const url = event.state?.storeCatalogUrl || window.location.href;
      if (isStoreCatalogUrl(url)) {
        navigateStore(url, false);
      }
    });
  }

  window.portalStoreCatalogInit = function portalStoreCatalogInit(root = catalogRoot()) {
    bindCatalog(root);
  };

  window.portalStoreCatalogNavigate = navigateStore;

  if (catalogRoot()) {
    bindCatalog();
    bindHistory();
    history.replaceState({ storeCatalogUrl: window.location.href }, '', window.location.href);
  }
})();
