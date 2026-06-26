/**
 * Public site — page transition loading bar and duplicate-click guard.
 */
(function () {
  'use strict';

  const BAR_ID = 'site-page-loading-bar';
  const OVERLAY_ID = 'site-page-loading-overlay';
  let activeNavigations = 0;

  function isDashboardUrl(url) {
    try {
      const parsed = new URL(url, window.location.origin);
      return parsed.origin === window.location.origin && parsed.pathname.startsWith('/dashboard/');
    } catch {
      return false;
    }
  }

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

  function shouldHandleLink(link) {
    if (!link || link.target === '_blank' || link.hasAttribute('download')) return false;
    const href = link.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) return false;
    if (link.dataset.noPageLoading === '1' || link.hasAttribute('data-store-cart-open')) return false;
    try {
      const parsed = new URL(href, window.location.origin);
      if (parsed.origin !== window.location.origin) return false;
      if (isDashboardUrl(parsed.href)) return false;
      if (isStoreCatalogUrl(parsed.href) && isStoreCatalogUrl(window.location.href) && typeof window.portalStoreCatalogNavigate === 'function') {
        return false;
      }
      if (parsed.pathname.includes('/api/')) return false;
    } catch {
      return false;
    }
    return true;
  }

  function ensureBar() {
    let bar = document.getElementById(BAR_ID);
    if (bar) return bar;
    bar = document.createElement('div');
    bar.id = BAR_ID;
    bar.className = 'site-page-loading-bar';
    bar.setAttribute('role', 'progressbar');
    bar.setAttribute('aria-hidden', 'true');
    bar.innerHTML = '<span class="site-page-loading-bar__fill"></span>';
    document.body.appendChild(bar);
    return bar;
  }

  function ensureOverlay() {
    let overlay = document.getElementById(OVERLAY_ID);
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.id = OVERLAY_ID;
    overlay.className = 'site-page-loading-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML = '<div class="site-page-loading-card" role="status" aria-live="polite">'
      + '<span class="site-page-loading-spinner" aria-hidden="true"></span>'
      + '<span class="site-page-loading-label">جاري تحميل الصفحة...</span>'
      + '</div>';
    document.body.appendChild(overlay);
    return overlay;
  }

  function setLoading(active) {
    activeNavigations = Math.max(0, activeNavigations + (active ? 1 : -1));
    const busy = activeNavigations > 0;
    document.body.classList.toggle('site-page-loading', busy);
    const bar = ensureBar();
    bar.classList.toggle('is-active', busy);
    bar.setAttribute('aria-hidden', busy ? 'false' : 'true');
    const overlay = ensureOverlay();
    overlay.classList.toggle('is-active', busy);
    overlay.setAttribute('aria-hidden', busy ? 'false' : 'true');
  }

  function markButtonBusy(button, busy) {
    if (!button) return;
    button.classList.toggle('is-loading', busy);
    button.setAttribute('aria-busy', busy ? 'true' : 'false');
    if (busy) {
      button.dataset.siteWasDisabled = button.disabled ? '1' : '0';
      button.disabled = true;
      return;
    }
    if (button.dataset.siteWasDisabled === '0') {
      button.disabled = false;
    }
    delete button.dataset.siteWasDisabled;
  }

  function isBusy() {
    return document.body.classList.contains('site-page-loading')
      || document.body.classList.contains('store-catalog-nav-busy');
  }

  document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');
    if (!link || !shouldHandleLink(link)) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (isBusy()) {
      event.preventDefault();
      return;
    }
    setLoading(true);
  }, true);

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.hasAttribute('data-store-add-cart')) return;
    if (form.method && form.method.toLowerCase() !== 'get') {
      const submitter = event.submitter;
      if (submitter instanceof HTMLElement) {
        markButtonBusy(submitter, true);
      }
      if (!form.dataset.siteAllowResubmit) {
        if (form.dataset.siteSubmitting === '1') {
          event.preventDefault();
          return;
        }
        form.dataset.siteSubmitting = '1';
      }
      return;
    }
    if (isDashboardUrl(formActionUrl(form))) return;
    if (isStoreCatalogUrl(formActionUrl(form)) && typeof window.portalStoreCatalogNavigate === 'function') {
      return;
    }
    if (form.dataset.siteSubmitting === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.siteSubmitting = '1';
    setLoading(true);
  }, true);

  function formActionUrl(form) {
    const raw = form.getAttribute('action');
    if (!raw) return window.location.href;
    return new URL(raw, window.location.href).href;
  }

  window.addEventListener('pageshow', () => {
    activeNavigations = 0;
    document.body.classList.remove('site-page-loading');
    const bar = document.getElementById(BAR_ID);
    if (bar) {
      bar.classList.remove('is-active');
      bar.setAttribute('aria-hidden', 'true');
    }
    const overlay = document.getElementById(OVERLAY_ID);
    if (overlay) {
      overlay.classList.remove('is-active');
      overlay.setAttribute('aria-hidden', 'true');
    }
    document.querySelectorAll('form[data-site-submitting]').forEach((form) => {
      delete form.dataset.siteSubmitting;
    });
    document.querySelectorAll('button.is-loading, [type="submit"].is-loading').forEach((btn) => {
      markButtonBusy(btn, false);
    });
  });

  const scrollTopBtn = document.getElementById('siteScrollTopBtn');
  if (scrollTopBtn) {
    const toggleScrollTop = () => {
      const show = window.scrollY > 420;
      scrollTopBtn.hidden = !show;
      scrollTopBtn.classList.toggle('is-visible', show);
    };
    toggleScrollTop();
    window.addEventListener('scroll', toggleScrollTop, { passive: true });
    scrollTopBtn.addEventListener('click', () => {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }
})();
