/**
 * Store catalog — AJAX navigation with immediate loading state.
 */
(function () {
  'use strict';

  const NAV_HEADER = 'X-Store-Nav';
  let navAbort = null;
  let navGeneration = 0;

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

  function storeLoadingMarkup() {
    return '<div class="store-results store-results--loading" aria-busy="true">'
      + '<div class="store-catalog-loading">'
      + '<div class="store-catalog-loading__spinner" role="status" aria-label="جاري التحميل"></div>'
      + '<p class="store-catalog-loading__text">جاري تحميل المواد...</p>'
      + '<div class="store-catalog-skeleton" aria-hidden="true">'
      + Array.from({ length: 8 }, () => '<div class="store-catalog-skeleton__card"></div>').join('')
      + '</div>'
      + '</div>'
      + '</div>';
  }

  function showCatalogLoading(root) {
    if (!root) return;
    root.setAttribute('aria-busy', 'true');
    root.classList.add('is-catalog-loading');
    root.innerHTML = storeLoadingMarkup();
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
        event.preventDefault();
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

    if (navAbort) {
      navAbort.abort();
    }
    const generation = ++navGeneration;
    navAbort = new AbortController();
    const signal = navAbort.signal;

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

      if (doc.title) document.title = doc.title;
      if (push) history.pushState({ storeCatalogUrl: url }, '', url);
      bindCatalog(newRoot);
    } catch (error) {
      if (error?.name === 'AbortError') return;
      const currentRoot = catalogRoot();
      if (currentRoot) {
        currentRoot.classList.remove('is-catalog-loading');
        currentRoot.removeAttribute('aria-busy');
        currentRoot.innerHTML = '<div class="store-results"><div class="store-empty-state">'
          + (error instanceof Error ? error.message : 'تعذر تحميل المتجر.')
          + '</div></div>';
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
