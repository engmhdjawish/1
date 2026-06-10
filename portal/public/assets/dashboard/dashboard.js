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

  function updateActiveNav(route) {
    qsa('[data-dashboard-route]').forEach((el) => {
      const match = el.getAttribute('data-dashboard-route') === route;
      el.classList.toggle('is-active', match);
      el.classList.toggle('bg-primary/10', match && el.tagName === 'A');
      el.classList.toggle('text-primary', match && el.tagName === 'A');
      el.classList.toggle('font-bold', match && el.tagName === 'A');
    });
  }

  function closeDrawer() {
    qs('#dashboard-drawer')?.classList.remove('is-open');
    qs('#dashboard-drawer-backdrop')?.classList.remove('is-open');
    document.body.style.overflow = '';
  }

  function openDrawer() {
    qs('#dashboard-drawer')?.classList.add('is-open');
    qs('#dashboard-drawer-backdrop')?.classList.add('is-open');
    document.body.style.overflow = 'hidden';
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
    let data = null;
    try {
      data = await res.json();
    } catch {
      data = { ok: false, message: 'استجابة غير صالحة من الخادم.' };
    }
    if (!res.ok || data.ok === false) {
      throw new Error(data.message || ('خطأ ' + res.status));
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
      const route = newMain.getAttribute('data-current-route') || '';
      if (route) {
        main.setAttribute('data-current-route', route);
        updateActiveNav(route);
      }
      if (doc.title) document.title = doc.title;
      if (push) history.pushState({ dashboardUrl: url }, '', url);
      bindPage(main);
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
      const data = await fetchJson(form.action || window.location.href, {
        method: (form.method || 'POST').toUpperCase(),
        body,
      });
      showToast(data.message || 'تم التنفيذ.', data.ok === false ? 'error' : 'success');
      if (redirect) {
        await navigate(redirect);
      } else if (reload || data.reload) {
        await navigate(window.location.href, false);
      }
    } catch (err) {
      showToast(err.message || 'تعذر تنفيذ العملية.', 'error');
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
          return;
        }
        explicit = false;
        if (form.hasAttribute('data-dashboard-ajax')) return;
        setButtonLoading(getSubmitter(form, event), true);
      });
      form.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        const target = event.target;
        if (!target || target.tagName === 'TEXTAREA') return;
        if (target === saveBtn) return;
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
        const url = (form.action || window.location.pathname) + '?' + params.toString();
        navigate(url);
      });
    });
  }

  function bindMobileNav() {
    qs('#dashboard-menu-btn')?.addEventListener('click', openDrawer);
    qs('#dashboard-drawer-backdrop')?.addEventListener('click', closeDrawer);
    qs('#dashboard-bottom-menu-btn')?.addEventListener('click', (e) => {
      e.preventDefault();
      openDrawer();
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
  }

  function init() {
    document.body.classList.add('dashboard-app', 'has-bottom-nav');
    bindMobileNav();
    bindHistory();
    bindPage(document);
    history.replaceState({ dashboardUrl: window.location.href }, '', window.location.href);
    const route = qs('[data-dashboard-main]')?.getAttribute('data-current-route');
    if (route) updateActiveNav(route);
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
