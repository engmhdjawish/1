/**
 * In-app notification bell + device push notifications.
 */
(function () {
  'use strict';

  const API = '/api/notifications.php';
  const PUSH_API = '/api/push-subscribe.php';
  const PUSH_CONFIG_API = '/api/push-config.php';
  const POLL_MS = 45_000;
  const ICON_URL = '/icons/brand-icon.php?size=192';

  function formatTime(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('ar-SY', { dateStyle: 'short', timeStyle: 'short' });
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    const output = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; ++i) {
      output[i] = raw.charCodeAt(i);
    }
    return output;
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

  function supportsPush() {
    return (
      typeof window !== 'undefined'
      && 'serviceWorker' in navigator
      && 'PushManager' in window
      && typeof Notification !== 'undefined'
    );
  }

  async function getServiceWorkerRegistration() {
    if (!('serviceWorker' in navigator)) {
      return null;
    }
    try {
      return await navigator.serviceWorker.ready;
    } catch {
      return null;
    }
  }

  async function showDeviceNotification(item) {
    if (!item || typeof Notification === 'undefined') {
      return;
    }
    if (Notification.permission !== 'granted') {
      return;
    }

    const title = String(item.title_ar || 'إشعار جديد');
    const body = String(item.body_ar || '');
    const url = String(item.link_url || '/');
    const tag = String(item.id || 'jawish-portal-notification');
    const options = {
      body,
      icon: ICON_URL,
      badge: ICON_URL,
      tag,
      data: { url, id: tag },
    };

    const registration = await getServiceWorkerRegistration();
    if (registration && typeof registration.showNotification === 'function') {
      await registration.showNotification(title, options);
      return;
    }

    try {
      new Notification(title, options);
    } catch (_) {
      /* ignore */
    }
  }

  async function subscribeToPush() {
    if (!supportsPush()) {
      throw new Error('المتصفح لا يدعم إشعارات الجهاز.');
    }

    const config = await fetchJson(PUSH_CONFIG_API);
    if (!config.supported || !config.publicKey) {
      throw new Error('إشعارات الجهاز غير مفعّلة على الخادم.');
    }

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
      throw new Error('لم يتم منح إذن الإشعارات.');
    }

    const registration = await getServiceWorkerRegistration();
    if (!registration) {
      throw new Error('تعذر تجهيز Service Worker.');
    }

    let subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(String(config.publicKey)),
      });
    }

    await fetchJson(PUSH_API, {
      method: 'POST',
      body: JSON.stringify(subscription.toJSON()),
    });

    return true;
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

  function initBell(root) {
    const btn = root.querySelector('[data-notif-bell-btn]');
    const panel = root.querySelector('[data-notif-bell-panel]');
    const list = root.querySelector('[data-notif-bell-list]');
    const badge = root.querySelector('[data-notif-bell-badge]');
    const markAll = root.querySelector('[data-notif-bell-mark-all]');
    const enablePush = root.querySelector('[data-notif-enable-push]');
    if (!btn || !panel || !list || !badge) return;

    let open = false;
    let lastUnread = -1;

    const setBadge = (count) => {
      const n = Math.max(0, Number(count) || 0);
      badge.textContent = n > 99 ? '99+' : String(n);
      badge.classList.toggle('hidden', n === 0);
    };

    const updatePushButton = async () => {
      if (!enablePush) return;
      if (!supportsPush()) {
        enablePush.hidden = true;
        return;
      }
      const granted = Notification.permission === 'granted';
      const registration = await getServiceWorkerRegistration();
      const subscribed = granted && registration
        ? Boolean(await registration.pushManager.getSubscription())
        : false;
      enablePush.hidden = false;
      enablePush.classList.toggle('is-visible', !subscribed);
      enablePush.classList.toggle('is-enabled', subscribed);
      enablePush.textContent = subscribed
        ? 'إشعارات الجهاز مفعّلة'
        : 'تفعيل إشعارات الجهاز';
      enablePush.disabled = subscribed;
    };

    const notifyNewItems = async (count) => {
      if (count <= lastUnread || lastUnread < 0) {
        return;
      }
      document.querySelectorAll('[data-notif-bell]').forEach((bellRoot) => {
        bellRoot.classList.add('notif-bell--pulse');
        window.setTimeout(() => bellRoot.classList.remove('notif-bell--pulse'), 2400);
      });
      try {
        const data = await fetchJson(API + '?action=latest_unread');
        if (data.item) {
          await showDeviceNotification(data.item);
        }
      } catch {
        await showDeviceNotification({
          title_ar: 'إشعار جديد',
          body_ar: 'لديك إشعارات جديدة في جاويش للتجارة',
          link_url: '/',
          id: 'jawish-portal-notification',
        });
      }
    };

    const pollUnread = async () => {
      try {
        const data = await fetchJson(API + '?action=count');
        const count = Math.max(0, Number(data.count) || 0);
        await notifyNewItems(count);
        lastUnread = count;
        setBadge(count);
      } catch {
        /* ignore */
      }
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
        lastUnread = Math.max(0, Number(data.unread) || 0);
      } catch {
        list.innerHTML = '<p class="notif-bell__empty">تعذر تحميل الإشعارات.</p>';
      }
      updatePushButton();
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
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      panel.classList.toggle('is-open', open);
      if (open) load();
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && open) {
        open = false;
        btn.setAttribute('aria-expanded', 'false');
        panel.classList.remove('is-open');
      }
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

    enablePush?.addEventListener('click', async (event) => {
      event.preventDefault();
      enablePush.disabled = true;
      try {
        await subscribeToPush();
        await updatePushButton();
      } catch (error) {
        enablePush.disabled = false;
        window.alert(error instanceof Error ? error.message : 'تعذر تفعيل إشعارات الجهاز.');
      }
    });

    document.addEventListener('click', (event) => {
      if (!open || root.contains(event.target)) return;
      open = false;
      panel.classList.remove('is-open');
      btn.setAttribute('aria-expanded', 'false');
    });

    fetchJson(API + '?action=count')
      .then((data) => {
        const count = Math.max(0, Number(data.count) || 0);
        lastUnread = count;
        setBadge(count);
      })
      .catch(() => {});

    updatePushButton();

    if (supportsPush() && Notification.permission === 'default') {
      window.setTimeout(() => {
        updatePushButton();
      }, 1200);
    } else if (supportsPush() && Notification.permission === 'granted') {
      subscribeToPush().catch(() => {});
    }

    window.setInterval(pollUnread, POLL_MS);
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') {
        pollUnread();
      }
    });
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
