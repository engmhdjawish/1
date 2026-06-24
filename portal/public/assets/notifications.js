/**
 * In-app notification bell widget.
 */
(function () {
  'use strict';

  const API = '/api/notifications.php';

  function formatTime(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('ar-SY', { dateStyle: 'short', timeStyle: 'short' });
  }

  async function fetchJson(url, options = {}) {
    const res = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(options.headers || {}),
      },
      ...options,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.ok === false) {
      throw new Error(data.message || 'تعذر تحميل الإشعارات.');
    }
    return data;
  }

  function renderItem(item) {
    const link = (item.link_url || '').trim();
    const tag = link ? 'a' : 'div';
    const el = document.createElement(tag);
    el.className = 'notif-bell__item' + (item.is_read ? '' : ' is-unread');
    if (link) {
      el.href = link;
    }
    el.dataset.notificationId = item.id || '';
    el.innerHTML =
      '<span class="notif-bell__icon"><span class="material-symbols-outlined" aria-hidden="true">' +
      (item.icon || 'notifications') +
      '</span></span>' +
      '<div class="notif-bell__body">' +
      '<div class="notif-bell__title">' + escapeHtml(item.title_ar || '') + '</div>' +
      '<div class="notif-bell__text">' + escapeHtml(item.body_ar || '') + '</div>' +
      '<div class="notif-bell__time">' + escapeHtml(formatTime(item.created_at)) + '</div>' +
      '</div>';
    return el;
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function initBell(root) {
    const btn = root.querySelector('[data-notif-bell-btn]');
    const panel = root.querySelector('[data-notif-bell-panel]');
    const list = root.querySelector('[data-notif-bell-list]');
    const badge = root.querySelector('[data-notif-bell-badge]');
    const markAll = root.querySelector('[data-notif-bell-mark-all]');
    if (!btn || !panel || !list || !badge) return;

    let open = false;

    const setBadge = (count) => {
      const n = Math.max(0, Number(count) || 0);
      badge.textContent = n > 99 ? '99+' : String(n);
      badge.classList.toggle('hidden', n === 0);
    };

    const load = async () => {
      try {
        const data = await fetchJson(API);
        list.innerHTML = '';
        const items = Array.isArray(data.items) ? data.items : [];
        if (items.length === 0) {
          list.innerHTML = '<p class="notif-bell__empty">لا توجد إشعارات حالياً.</p>';
        } else {
          items.forEach((item) => list.appendChild(renderItem(item)));
        }
        setBadge(data.unread ?? 0);
      } catch {
        list.innerHTML = '<p class="notif-bell__empty">تعذر تحميل الإشعارات.</p>';
      }
    };

    const markRead = async (id) => {
      if (!id) return;
      try {
        const data = await fetchJson(API, {
          method: 'POST',
          body: JSON.stringify({ action: 'read', id }),
        });
        setBadge(data.unread ?? 0);
      } catch {
        /* ignore */
      }
    };

    btn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      open = !open;
      panel.classList.toggle('is-open', open);
      if (open) load();
    });

    list.addEventListener('click', (event) => {
      const item = event.target.closest('[data-notification-id]');
      if (!item) return;
      markRead(item.dataset.notificationId || '');
    });

    markAll?.addEventListener('click', async (event) => {
      event.preventDefault();
      try {
        await fetchJson(API, {
          method: 'POST',
          body: JSON.stringify({ action: 'read_all' }),
        });
        setBadge(0);
        await load();
      } catch {
        /* ignore */
      }
    });

    document.addEventListener('click', (event) => {
      if (!open || root.contains(event.target)) return;
      open = false;
      panel.classList.remove('is-open');
    });

    fetchJson(API + '?action=count')
      .then((data) => setBadge(data.count ?? 0))
      .catch(() => {});
  }

  function init() {
    document.querySelectorAll('[data-notif-bell]').forEach(initBell);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
