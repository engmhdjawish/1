/**
 * Portal dashboard — AJAX navigation, form actions, mobile nav, button loading.
 */
(function () {
  'use strict';

  const NAV_HEADER = 'X-Dashboard-Nav';
  const AJAX_HEADER = 'X-Dashboard-Ajax';

  const qs = (sel, root = document) => root.querySelector(sel);
  const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  function isDashboardUrl(url) {
    try {
      const u = new URL(url, window.location.origin);
      return u.origin === window.location.origin && u.pathname.startsWith('/dashboard/');
    } catch {
      return false;
    }
  }

  function showPageLoader(active) {
    const el = qs('#dashboard-page-loader');
    if (el) el.classList.toggle('is-active', active);
  }

  function showToast(message, type = 'success', duration = 4200) {
    const root = qs('#dashboard-toast-root');
    if (!root || !message) return;
    const toast = document.createElement('div');
    toast.className = 'dashboard-toast is-' + (type === 'error' ? 'error' : type === 'info' ? 'info' : 'success');
    toast.textContent = message;
    root.appendChild(toast);
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transition = 'opacity 0.2s';
      setTimeout(() => toast.remove(), 220);
    }, duration);
  }

  function setButtonLoading(btn, loading) {
    if (!btn) return;
    btn.disabled = loading;
    btn.classList.toggle('is-loading', loading);
    if (loading) {
      btn.dataset.dashboardPrevDisabled = btn.disabled ? '1' : '0';
    }
  }

  function getSubmitter(form, event) {
    if (event && event.submitter) return event.submitter;
    return form.querySelector('[type="submit"]:not([disabled])');
  }

  /** Resolved POST/GET URL — form.action is shadowed when a control is named "action". */
  function formActionUrl(form) {
    const raw = form.getAttribute('action');
    if (!raw) {
      return window.location.href;
    }
    return new URL(raw, window.location.href).href;
  }

  function normalizeDashboardRoute(route) {
    if (!route) return '';
    try {
      const url = new URL(route, window.location.origin);
      return url.pathname + url.search;
    } catch {
      return route;
    }
  }

  function routesMatch(currentRoute, itemRoute) {
    const current = normalizeDashboardRoute(currentRoute);
    const item = normalizeDashboardRoute(itemRoute);
    if (!current || !item) return false;

    try {
      const currentUrl = new URL(current, window.location.origin);
      const itemUrl = new URL(item, window.location.origin);
      if (currentUrl.pathname !== itemUrl.pathname) return false;
      for (const [key, value] of itemUrl.searchParams.entries()) {
        if (currentUrl.searchParams.get(key) !== value) return false;
      }
      return true;
    } catch {
      return current === item;
    }
  }

  function setSidebarLinkActive(link, active) {
    link.classList.toggle('bg-primary/10', active);
    link.classList.toggle('text-primary', active);
    link.classList.toggle('font-bold', active);
    link.classList.toggle('border-r-4', active);
    link.classList.toggle('border-primary', active);
    link.classList.toggle('text-text-muted', !active);
    link.classList.toggle('hover:bg-surface-low', !active);
    const icon = link.querySelector('.material-symbols-outlined');
    if (icon) icon.classList.toggle('fill', active);
  }

  function updateActiveNav(route) {
    const normalized = normalizeDashboardRoute(route);
    qsa('[data-dashboard-route]').forEach((link) => {
      const itemRoute = link.getAttribute('data-dashboard-route') || '';
      const active = routesMatch(normalized, itemRoute);
      if (link.closest('#dashboard-bottom-nav')) {
        link.classList.toggle('is-active', active);
        return;
      }
      setSidebarLinkActive(link, active);
    });
  }

  function syncDashboardChrome(doc) {
    const srcMeta = doc.querySelector('[data-dashboard-sidebar-meta]');
    if (srcMeta) {
      qsa('[data-dashboard-sidebar-meta]').forEach((node) => {
        node.innerHTML = srcMeta.innerHTML;
      });
    }

    const srcNav = doc.querySelector('[data-dashboard-sidebar-nav]');
    if (srcNav) {
      qsa('[data-dashboard-sidebar-nav]').forEach((node) => {
        node.innerHTML = srcNav.innerHTML;
      });
    }

    const srcFooter = doc.querySelector('[data-dashboard-sidebar-footer]');
    if (srcFooter) {
      qsa('[data-dashboard-sidebar-footer]').forEach((node) => {
        node.innerHTML = srcFooter.innerHTML;
      });
    }

    const srcHeaderArea = doc.querySelector('[data-dashboard-header-area]');
    const headerArea = qs('[data-dashboard-header-area]');
    if (srcHeaderArea && headerArea) {
      headerArea.textContent = srcHeaderArea.textContent;
    }

    const srcAreaTabs = doc.querySelector('[data-dashboard-area-tabs]');
    const areaTabs = qs('[data-dashboard-area-tabs]');
    if (srcAreaTabs && areaTabs) {
      areaTabs.innerHTML = srcAreaTabs.innerHTML;
    }
  }

  function currentDashboardRoute() {
    const mainRoute = qs('[data-dashboard-main]')?.getAttribute('data-current-route');
    if (mainRoute) return mainRoute;
    return window.location.pathname + window.location.search;
  }

  function closeDrawer() {
    qs('#dashboard-drawer')?.classList.remove('is-open');
    qs('#dashboard-drawer-backdrop')?.classList.remove('is-open');
    qs('#dashboard-menu-btn')?.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }

  function openDrawer() {
    qs('#dashboard-drawer')?.classList.add('is-open');
    qs('#dashboard-drawer-backdrop')?.classList.add('is-open');
    qs('#dashboard-menu-btn')?.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }

  function describeInvalidJsonResponse(res, raw) {
    const preview = String(raw || '').replace(/\s+/g, ' ').trim();
    const short = preview.slice(0, 220);
    if (res.redirected && /login\.php/i.test(res.url || '')) {
      return 'انتهت جلسة الدخول — أعد تسجيل الدخول ثم جرّب مجدداً.';
    }
    if (short.startsWith('<') || /<!DOCTYPE/i.test(short)) {
      return 'الخادم أعاد صفحة HTML بدل JSON (غالباً خطأ PHP أو إعادة توجيه). افتح Network في المتصفح واطّلع على Response.';
    }
    if (short) {
      return short;
    }
    return 'رد فارغ من الخادم.';
  }

  async function fetchJson(url, options = {}) {
    const res = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        [AJAX_HEADER]: '1',
        ...(options.headers || {}),
      },
      ...options,
    });
    const raw = await res.text();
    let data = null;
    if (raw) {
      try {
        data = JSON.parse(raw);
      } catch (parseError) {
        const detail = describeInvalidJsonResponse(res, raw);
        console.error('[dashboard] invalid JSON response', {
          url,
          status: res.status,
          redirected: res.redirected,
          finalUrl: res.url,
          contentType: res.headers.get('content-type'),
          bodyPreview: raw.slice(0, 1200),
          parseError,
        });
        throw new Error(`استجابة غير صالحة [HTTP ${res.status}]: ${detail}`);
      }
    }
    if (!data) {
      throw new Error(`استجابة فارغة من الخادم [HTTP ${res.status}]`);
    }
    if (!res.ok || data.ok === false) {
      const err = new Error(data.message || ('خطأ ' + res.status));
      if (data.login) {
        err.loginRequired = true;
      }
      throw err;
    }
    return data;
  }

  async function navigate(url, push = true) {
    if (!isDashboardUrl(url)) {
      window.location.href = url;
      return;
    }
    showPageLoader(true);
    try {
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { [NAV_HEADER]: '1', Accept: 'text/html' },
      });
      if (!res.ok) throw new Error('تعذر تحميل الصفحة.');
      const html = await res.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const newMain = doc.querySelector('[data-dashboard-main]');
      const main = qs('[data-dashboard-main]');
      if (!newMain || !main) {
        window.location.href = url;
        return;
      }
      main.innerHTML = newMain.innerHTML;
      syncDashboardChrome(doc);
      const route = newMain.getAttribute('data-current-route') || normalizeDashboardRoute(url);
      if (route) {
        main.setAttribute('data-current-route', route);
        updateActiveNav(route);
      }
      if (doc.title) document.title = doc.title;
      if (push) history.pushState({ dashboardUrl: url }, '', url);
      bindPage(document);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (err) {
      showToast(err.message || 'تعذر التنقل.', 'error');
    } finally {
      showPageLoader(false);
    }
  }

  async function submitAjaxForm(form, submitter) {
    const reload = form.hasAttribute('data-dashboard-reload');
    const redirect = form.getAttribute('data-dashboard-redirect');
    setButtonLoading(submitter, true);
    try {
      const body = new FormData(form);
      if (submitter && submitter.name) {
        body.set(submitter.name, submitter.value);
      }
      const data = await fetchJson(formActionUrl(form), {
        method: (form.method || 'POST').toUpperCase(),
        body,
      });
      showToast(data.message || 'تم التنفيذ.', data.ok === false ? 'error' : 'success');
      const target = redirect || data.redirect;
      if (target) {
        await navigate(target);
      } else if (reload || data.reload) {
        await navigate(window.location.href, false);
      }
    } catch (err) {
      showToast(err.message || 'تعذر تنفيذ العملية.', 'error');
      if (err.loginRequired) {
        setTimeout(() => {
          window.location.href = '/login.php?type=staff';
        }, 900);
      }
    } finally {
      setButtonLoading(submitter, false);
    }
  }

  function bindNavigation(root) {
    qsa('a[href^="/dashboard/"]', root).forEach((link) => {
      if (link.hasAttribute('data-dashboard-no-nav')) return;
      if (link.target === '_blank') return;
      if (link.hasAttribute('download')) return;
      link.addEventListener('click', (event) => {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const href = link.getAttribute('href');
        if (!href || !isDashboardUrl(href)) return;
        event.preventDefault();
        closeDrawer();
        navigate(href);
      });
    });
  }

  function bindAjaxForms(root) {
    qsa('form[data-dashboard-ajax]', root).forEach((form) => {
      if (form.dataset.dashboardBound === '1') return;
      form.dataset.dashboardBound = '1';
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        const submitter = getSubmitter(form, event);
        submitAjaxForm(form, submitter);
      });
    });
  }

  function bindExplicitSave(root) {
    qsa('form[data-dashboard-explicit-save]', root).forEach((form) => {
      if (form.dataset.explicitBound === '1') return;
      form.dataset.explicitBound = '1';
      let explicit = false;
      const saveBtn = form.querySelector('[data-dashboard-save-btn]');
      saveBtn?.addEventListener('click', () => { explicit = true; });
      form.addEventListener('submit', (event) => {
        if (!explicit) {
          event.preventDefault();
          event.stopImmediatePropagation();
          return;
        }
        explicit = false;
        if (form.hasAttribute('data-dashboard-ajax')) {
          return;
        }
        setButtonLoading(getSubmitter(form, event), true);
      }, true);
      form.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        const target = event.target;
        if (!target || target.tagName === 'TEXTAREA') return;
        if (target === saveBtn) return;
        if (target.closest('.token-picker')) return;
        if (target.getAttribute('data-role') === 'search') return;
        if (target.type === 'search') return;
        event.preventDefault();
      }, true);
    });
  }

  function bindConfirm(root) {
    qsa('form[data-dashboard-confirm]', root).forEach((form) => {
      if (form.dataset.confirmBound === '1') return;
      form.dataset.confirmBound = '1';
      const msg = form.getAttribute('data-dashboard-confirm') || 'هل أنت متأكد؟';
      form.addEventListener('submit', (event) => {
        if (!confirm(msg)) {
          event.preventDefault();
        }
      });
    });
  }

  function bindFilterForms(root) {
    qsa('form[data-dashboard-filter]', root).forEach((form) => {
      if (form.dataset.filterBound === '1') return;
      form.dataset.filterBound = '1';
      form.addEventListener('submit', (event) => {
        if (form.method.toLowerCase() !== 'get') return;
        event.preventDefault();
        const params = new URLSearchParams(new FormData(form));
        const url = formActionUrl(form).replace(/\?.*$/, '') + '?' + params.toString();
        closeDrawer();
        navigate(url);
      });
    });
  }

  function bindMobileNav() {
    if (document.body.dataset.mobileNavBound === '1') return;
    document.body.dataset.mobileNavBound = '1';

    qs('#dashboard-menu-btn')?.addEventListener('click', openDrawer);
    qs('#dashboard-drawer-backdrop')?.addEventListener('click', closeDrawer);
    qs('#dashboard-drawer-close')?.addEventListener('click', closeDrawer);
    qs('#dashboard-bottom-menu-btn')?.addEventListener('click', (e) => {
      e.preventDefault();
      openDrawer();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') closeDrawer();
    });
  }

  function bindHistory() {
    window.addEventListener('popstate', (event) => {
      const url = event.state?.dashboardUrl || window.location.href;
      navigate(url, false);
    });
  }

  function bindPage(root) {
    bindNavigation(root);
    bindAjaxForms(root);
    bindExplicitSave(root);
    bindConfirm(root);
    bindFilterForms(root);
    if (typeof window.portalTokenPickerInit === 'function') {
      window.portalTokenPickerInit(root);
    }
    if (typeof window.portalHomeSectionsInit === 'function') {
      window.portalHomeSectionsInit(root);
    }
    if (typeof window.portalSpecialOffersInit === 'function') {
      window.portalSpecialOffersInit(root);
    }
    if (typeof window.portalMediaPickerInit === 'function') {
      window.portalMediaPickerInit();
    }
    if (typeof window.portalAboutEditorInit === 'function') {
      window.portalAboutEditorInit(root);
    }
    if (typeof window.portalAccountingStatementInit === 'function') {
      window.portalAccountingStatementInit(root);
    }
  }

  function init() {
    document.body.classList.add('dashboard-app', 'has-bottom-nav');
    bindMobileNav();
    bindHistory();
    bindPage(document);
    history.replaceState({ dashboardUrl: window.location.href }, '', window.location.href);
    updateActiveNav(currentDashboardRoute());
    const flash = qs('[data-dashboard-flash]');
    if (flash) {
      showToast(flash.textContent.trim(), flash.getAttribute('data-type') || 'success');
      flash.remove();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.dashboardApp = { navigate, showToast, setButtonLoading };
})();
