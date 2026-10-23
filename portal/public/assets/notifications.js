/**
 * In-app notification bell, toasts, and device push notifications.
 */
(function () {
  'use strict';

  const API = '/api/notifications.php';
  const PUSH_API = '/api/push-subscribe.php';
  const PUSH_CONFIG_API = '/api/push-config.php';
  const POLL_MS = document.body?.classList.contains('dashboard-app') ? 12_000 : 20_000;
  const ICON_URL = '/icons/brand-icon.php?size=192';
  const CONSENT_DISMISS_KEY = 'jawish_site_consent_dismissed_at';
  const CONSENT_DISMISS_MS = 30 * 24 * 60 * 60 * 1000;
  const CONSENT_VISITS_KEY = 'jawish_site_consent_visits';
  const CONSENT_MIN_VISITS = 3;
  const CONSENT_DELAY_MS = 10_000;
  // Never interrupt someone who is placing an order or signing in.
  const CONSENT_QUIET_PATHS = [
    '/store-cart.php', '/cart.php', '/checkout.php', '/order-confirmation.php',
    '/login.php', '/customer-login.php', '/staff-login.php', '/register.php',
    '/track-order.php', '/install.php', '/pwa-check.php', '/guide.php',
  ];
  const announcedAt = new Map();
  let pushSubscribed = false;

  function defaultOpenUrl() {
    if (document.body && document.body.classList.contains('dashboard-app')) {
      return '/dashboard/index.php';
    }
    return '/';
  }

  function safeIcon(icon) {
    const value = String(icon || 'notifications').trim();
    return /^[a-z0-9_]{1,50}$/i.test(value) ? value : 'notifications';
  }

  function safeHref(url) {
    const value = String(url || '').trim();
    if (!value) return defaultOpenUrl();
    if (value.startsWith('/') && !value.startsWith('//') && !value.includes('://')) {
      return value;
    }
    try {
      const parsed = new URL(value, window.location.origin);
      if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
        return parsed.href;
      }
    } catch (_) {
      /* ignore */
    }
    return defaultOpenUrl();
  }

  function isRead(item) {
    const value = item?.is_read;
    return value === true || value === 1 || value === '1' || value === 't' || value === 'true';
  }

  function formatTime(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    const diff = Date.now() - date.getTime();
    const minutes = Math.round(diff / 60000);
    if (minutes < 1) return 'الآن';
    if (minutes < 60) return 'قبل ' + minutes + ' د';
    const hours = Math.round(minutes / 60);
    if (hours < 24) return 'قبل ' + hours + ' س';
    const days = Math.round(hours / 24);
    if (days < 7) return 'قبل ' + days + ' ي';
    return date.toLocaleString('ar-SY', { dateStyle: 'short', timeStyle: 'short' });
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function rememberAnnouncement(id) {
    const key = String(id || '').trim();
    if (!key) return true;
    const now = Date.now();
    const previous = announcedAt.get(key) || 0;
    if (now - previous < 30000) return false;
    announcedAt.set(key, now);
    if (announcedAt.size > 40) {
      const oldest = announcedAt.keys().next().value;
      announcedAt.delete(oldest);
    }
    return true;
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

  function toastStack() {
    let stack = document.getElementById('notif-toast-stack');
    if (stack) return stack;
    stack = document.createElement('div');
    stack.id = 'notif-toast-stack';
    stack.className = 'notif-toast-stack';
    stack.setAttribute('aria-live', 'polite');
    document.body.appendChild(stack);
    return stack;
  }

  function showToast(item) {
    const stack = toastStack();
    const toast = document.createElement('a');
    toast.className = 'notif-toast';
    toast.href = safeHref(item?.link_url);
    toast.innerHTML =
      '<span class="notif-toast__icon"><span class="material-symbols-outlined" aria-hidden="true">' +
      escapeHtml(safeIcon(item?.icon)) +
      '</span></span>' +
      '<span><strong>' + escapeHtml(item?.title_ar || 'إشعار جديد') + '</strong>' +
      '<span>' + escapeHtml(item?.body_ar || '') + '</span></span>';
    toast.addEventListener('click', () => {
      const id = String(item?.id || '').trim();
      if (!id) return;
      fetchJson(API, {
        method: 'POST',
        body: JSON.stringify({ action: 'read', id }),
      }).catch(() => {});
    });
    stack.appendChild(toast);
    window.setTimeout(() => toast.remove(), 7000);
  }

  function pulseBells() {
    document.querySelectorAll('[data-notif-bell]').forEach((bellRoot) => {
      bellRoot.classList.add('notif-bell--pulse');
      window.setTimeout(() => bellRoot.classList.remove('notif-bell--pulse'), 2400);
    });
  }

  async function showDeviceNotification(item) {
    if (!item || typeof Notification === 'undefined' || Notification.permission !== 'granted') {
      return;
    }

    const title = String(item.title_ar || 'إشعار جديد');
    const body = String(item.body_ar || '');
    const url = safeHref(item.link_url);
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
      const notification = new Notification(title, options);
      notification.onclick = () => {
        window.focus();
        window.location.href = url;
        notification.close();
      };
    } catch (_) {
      /* ignore */
    }
  }

  async function announce(item) {
    if (!item || !rememberAnnouncement(item.id)) return;
    pulseBells();
    if (document.visibilityState === 'visible') {
      showToast(item);
      return;
    }
    if (pushSubscribed) return;
    await showDeviceNotification(item);
  }

  async function refreshPushSubscriptionState() {
    if (!supportsPush() || Notification.permission !== 'granted') {
      pushSubscribed = false;
      return false;
    }
    const registration = await getServiceWorkerRegistration();
    pushSubscribed = Boolean(registration && await registration.pushManager.getSubscription());
    return pushSubscribed;
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
    pushSubscribed = true;
    return true;
  }

  async function unsubscribeFromPush() {
    const registration = await getServiceWorkerRegistration();
    const subscription = registration ? await registration.pushManager.getSubscription() : null;
    const endpoint = subscription?.endpoint || '';
    if (subscription) {
      await subscription.unsubscribe();
    }
    if (endpoint) {
      await fetchJson(PUSH_API, {
        method: 'POST',
        body: JSON.stringify({ action: 'unsubscribe', endpoint }),
      });
    }
    pushSubscribed = false;
  }

  function renderItem(item) {
    const url = safeHref(item.link_url);
    const el = document.createElement('article');
    el.className = 'notif-bell__item' + (isRead(item) ? '' : ' is-unread');
    el.dataset.notificationId = item.id || '';
    el.innerHTML =
      '<a href="' + escapeHtml(url) + '" class="notif-bell__link">' +
      '<span class="notif-bell__icon"><span class="material-symbols-outlined" aria-hidden="true">' +
      escapeHtml(safeIcon(item.icon)) +
      '</span></span>' +
      '<span class="notif-bell__body">' +
      '<span class="notif-bell__title">' + escapeHtml(item.title_ar || '') + '</span>' +
      '<span class="notif-bell__text">' + escapeHtml(item.body_ar || '') + '</span>' +
      '<span class="notif-bell__time">' + escapeHtml(formatTime(item.created_at)) + '</span>' +
      '</span>' +
      '</a>' +
      '<button type="button" class="notif-bell__dismiss" aria-label="إخفاء الإشعار" title="إخفاء">' +
      '<span class="material-symbols-outlined" aria-hidden="true">close</span>' +
      '</button>';
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
    let lastActivityAt = '';
    let items = [];
    let filter = 'all';

    const setPanelOpen = (isOpen) => {
      open = isOpen;
      btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      panel.classList.toggle('is-open', isOpen);
      panel.hidden = !isOpen;
    };

    const setBadge = (count) => {
      const n = Math.max(0, Number(count) || 0);
      badge.textContent = n > 99 ? '99+' : String(n);
      badge.classList.toggle('hidden', n === 0);
    };

    const syncCategoryTabs = () => {
      const present = new Set(items.map((item) => String(item.category || 'news')));
      const showTabs = present.size > 1;
      root.querySelectorAll('[data-notif-filter^="cat:"]').forEach((tab) => {
        const category = (tab.getAttribute('data-notif-filter') || '').slice(4);
        tab.hidden = !(showTabs && present.has(category));
      });
      if (filter.startsWith('cat:') && !present.has(filter.slice(4))) {
        filter = 'all';
        root.querySelectorAll('[data-notif-filter]').forEach((tab) => {
          tab.classList.toggle('is-active', tab.getAttribute('data-notif-filter') === 'all');
        });
      }
    };

    const renderList = () => {
      const visible = filter === 'unread'
        ? items.filter((item) => !isRead(item))
        : filter.startsWith('cat:')
          ? items.filter((item) => String(item.category || 'news') === filter.slice(4))
          : items;
      list.innerHTML = '';
      if (visible.length === 0) {
        list.innerHTML = '<p class="notif-bell__empty">' +
          (items.length === 0 ? 'لا توجد إشعارات حالياً.' : (filter === 'unread' ? 'لا توجد إشعارات غير مقروءة.' : 'لا توجد إشعارات هنا.')) +
          '</p>';
        return;
      }
      visible.forEach((item) => list.appendChild(renderItem(item)));
    };

    const updatePushButton = async () => {
      if (!enablePush) return;
      if (!supportsPush()) {
        enablePush.hidden = true;
        return;
      }
      const subscribed = await refreshPushSubscriptionState();
      enablePush.hidden = false;
      enablePush.classList.add('is-visible');
      enablePush.classList.toggle('is-enabled', subscribed);
      enablePush.textContent = subscribed ? 'إيقاف إشعارات الجهاز' : 'تفعيل إشعارات الجهاز';
      enablePush.disabled = false;
    };

    const pollUnread = async (silent = false) => {
      try {
        const pollUrl = API + '?action=poll' + (lastActivityAt ? '&since=' + encodeURIComponent(lastActivityAt) : '');
        const data = await fetchJson(pollUrl);
        const count = Math.max(0, Number(data.count) || 0);
        const countChanged = lastUnread >= 0 && count !== lastUnread;
        if (data.latest) {
          lastActivityAt = String(data.latest);
        } else if (!lastActivityAt) {
          lastActivityAt = '1970-01-01T00:00:00+00:00';
        }
        if (!silent && data.changed && data.item) {
          await announce(data.item);
        }
        if (open && (data.changed || countChanged)) {
          await load();
        }
        lastUnread = count;
        setBadge(count);
      } catch {
        /* ignore */
      }
    };

    const load = async () => {
      list.innerHTML = '<p class="notif-bell__empty">جاري التحميل...</p>';
      try {
        const data = await fetchJson(API);
        items = Array.isArray(data.items) ? data.items : [];
        syncCategoryTabs();
        renderList();
        setBadge(data.unread ?? 0);
        lastUnread = Math.max(0, Number(data.unread) || 0);
      } catch {
        list.innerHTML = '<p class="notif-bell__empty">تعذر تحميل الإشعارات. <button type="button" data-notif-retry>إعادة المحاولة</button></p>';
      }
      updatePushButton();
    };

    const markRead = async (id, element) => {
      if (!id) return;
      const item = items.find((row) => String(row.id) === String(id));
      if (item) item.is_read = 1;
      element?.classList.remove('is-unread');
      if (filter === 'unread') renderList();
      try {
        const data = await fetchJson(API, {
          method: 'POST',
          body: JSON.stringify({ action: 'read', id }),
        });
        setBadge(data.unread ?? 0);
        lastUnread = Math.max(0, Number(data.unread) || 0);
      } catch {
        /* ignore */
      }
    };

    btn.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const nextOpen = !open;
      setPanelOpen(nextOpen);
      if (nextOpen) load();
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && open) {
        setPanelOpen(false);
      }
    });

    root.querySelectorAll('[data-notif-filter]').forEach((button) => {
      button.addEventListener('click', () => {
        filter = button.getAttribute('data-notif-filter') || 'all';
        root.querySelectorAll('[data-notif-filter]').forEach((other) => {
          other.classList.toggle('is-active', other === button);
        });
        renderList();
      });
    });

    const dismissItem = async (id, element) => {
      if (!id) return;
      try {
        const data = await fetchJson(API, {
          method: 'POST',
          body: JSON.stringify({ action: 'dismiss', id }),
        });
        items = items.filter((row) => String(row.id) !== String(id));
        element?.remove();
        setBadge(data.unread ?? 0);
        lastUnread = Math.max(0, Number(data.unread) || 0);
        if (!list.querySelector('.notif-bell__item')) {
          renderList();
        }
      } catch {
        /* ignore */
      }
    };

    list.addEventListener('click', (event) => {
      const retry = event.target.closest('[data-notif-retry]');
      if (retry) {
        event.preventDefault();
        load();
        return;
      }
      const dismissBtn = event.target.closest('.notif-bell__dismiss');
      if (dismissBtn) {
        event.preventDefault();
        event.stopPropagation();
        const item = dismissBtn.closest('[data-notification-id]');
        if (item) {
          dismissItem(item.dataset.notificationId || '', item);
        }
        return;
      }
      const link = event.target.closest('.notif-bell__link');
      if (!link) return;
      const item = link.closest('[data-notification-id]');
      if (item) {
        markRead(item.dataset.notificationId || '', item);
      }
    });

    markAll?.addEventListener('click', async (event) => {
      event.preventDefault();
      try {
        const data = await fetchJson(API, {
          method: 'POST',
          body: JSON.stringify({ action: 'read_all' }),
        });
        items = items.map((item) => ({ ...item, is_read: 1 }));
        setBadge(data.unread ?? 0);
        lastUnread = Math.max(0, Number(data.unread) || 0);
        renderList();
      } catch {
        /* ignore */
      }
    });

    enablePush?.addEventListener('click', async (event) => {
      event.preventDefault();
      enablePush.disabled = true;
      try {
        if (pushSubscribed) {
          await unsubscribeFromPush();
        } else {
          await subscribeToPush();
        }
        await updatePushButton();
      } catch (error) {
        enablePush.disabled = false;
        window.alert(error instanceof Error ? error.message : 'تعذر تحديث إشعارات الجهاز.');
      }
    });

    document.addEventListener('click', (event) => {
      if (!open || root.contains(event.target)) return;
      setPanelOpen(false);
    });

    root.addEventListener('notif:incoming', () => {
      pollUnread(true);
    });

    fetchJson(API + '?action=poll')
      .then((data) => {
        const count = Math.max(0, Number(data.count) || 0);
        if (data.latest) {
          lastActivityAt = String(data.latest);
        } else if (!lastActivityAt) {
          lastActivityAt = '1970-01-01T00:00:00+00:00';
        }
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

    window.setInterval(() => {
      if (document.visibilityState === 'visible') pollUnread(false);
    }, POLL_MS);
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') {
        pollUnread(false);
      }
    });
  }

  function countConsentVisit() {
    try {
      const next = (Number(window.localStorage.getItem(CONSENT_VISITS_KEY)) || 0) + 1;
      window.localStorage.setItem(CONSENT_VISITS_KEY, String(next));
      return next;
    } catch {
      return 0;
    }
  }

  function isConsentAudience() {
    if (!document.body || document.body.classList.contains('dashboard-app')) {
      return false;
    }
    if (CONSENT_QUIET_PATHS.includes(window.location.pathname)) {
      return false;
    }
    if (window.PortalPwa?.isInAppBrowser?.()) {
      return false;
    }
    const reader = String(document.body.dataset.notifReader || 'guest').trim();
    return reader === 'guest' || reader === 'customer';
  }

  function consentDismissedRecently() {
    try {
      const raw = window.localStorage.getItem(CONSENT_DISMISS_KEY);
      if (!raw) return false;
      return Date.now() - Number(raw) < CONSENT_DISMISS_MS;
    } catch {
      return false;
    }
  }

  function rememberConsentDismiss() {
    try {
      window.localStorage.setItem(CONSENT_DISMISS_KEY, String(Date.now()));
    } catch {
      /* ignore */
    }
  }

  async function isPushServerSupported() {
    if (!supportsPush()) {
      return false;
    }
    try {
      const config = await fetchJson(PUSH_CONFIG_API);
      return Boolean(config.supported && config.publicKey);
    } catch {
      return false;
    }
  }

  async function isPushFullyEnabled() {
    if (!supportsPush() || !(await isPushServerSupported())) {
      return true;
    }
    if (Notification.permission !== 'granted') {
      return false;
    }
    return refreshPushSubscriptionState();
  }

  function shouldPromptPwaInstall() {
    if (typeof window.PortalPwa?.shouldPromptInstall === 'function') {
      return window.PortalPwa.shouldPromptInstall();
    }
    return !(
      window.matchMedia('(display-mode: standalone)').matches
      || window.matchMedia('(display-mode: fullscreen)').matches
      || window.matchMedia('(display-mode: minimal-ui)').matches
      || window.navigator.standalone === true
    );
  }

  function showNotificationSettingsHelp() {
    const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const message = isIOS
      ? 'من iPhone: الإعدادات → Safari → إشعارات → ابحث عن الموقع وفعّل الإشعارات.\n\nأو: اضغط أيقونة القفل/الموقع في شريط العنوان → إشعارات → سماح.'
      : 'من Chrome/Edge: اضغط أيقونة القفل بجانب العنوان → إعدادات الموقع → الإشعارات → «سماح».';
    window.alert(message);
  }

  async function refreshSiteConsentBanner(banner) {
    const pushNeeded = !(await isPushFullyEnabled()) && (await isPushServerSupported());
    const pwaNeeded = shouldPromptPwaInstall();
    if (!pushNeeded && !pwaNeeded) {
      banner.remove();
      return;
    }

    const permission = typeof Notification !== 'undefined' ? Notification.permission : 'default';
    const denied = permission === 'denied';
    const titleEl = banner.querySelector('[data-site-consent-title]');
    const textEl = banner.querySelector('[data-site-consent-text]');
    const actionsEl = banner.querySelector('[data-site-consent-actions]');
    if (!titleEl || !textEl || !actionsEl) {
      return;
    }

    titleEl.textContent = denied && pushNeeded
      ? 'الإشعارات معطّلة في المتصفح'
      : 'فعّل الإشعارات والتطبيق';
    textEl.textContent = denied && pushNeeded
      ? 'لتلقي العروض والتحديثات، فعّل الإشعارات من إعدادات المتصفح لهذا الموقع.'
      : 'ابقَ على اطلاع بالعروض والتحديثات — فعّل إشعارات الجهاز'
        + (pwaNeeded ? ' وثبّت التطبيق على جهازك.' : '.');

    let actionsHtml = '';
    if (pushNeeded && !denied) {
      actionsHtml += '<button type="button" class="site-consent-banner__btn site-consent-banner__btn--primary" data-site-consent-push>تفعيل الإشعارات</button>';
    }
    if (pwaNeeded) {
      actionsHtml += '<button type="button" class="site-consent-banner__btn" data-site-consent-pwa>تثبيت التطبيق</button>';
    }
    if (pushNeeded && denied) {
      actionsHtml += '<button type="button" class="site-consent-banner__btn" data-site-consent-help>كيف أفعّلها؟</button>';
    }
    actionsEl.innerHTML = actionsHtml;
    bindSiteConsentBannerActions(banner);
  }

  function bindSiteConsentBannerActions(banner) {
    banner.querySelector('[data-site-consent-push]')?.addEventListener('click', async () => {
      const btn = banner.querySelector('[data-site-consent-push]');
      if (btn) {
        btn.disabled = true;
      }
      try {
        await subscribeToPush();
        await refreshSiteConsentBanner(banner);
      } catch (error) {
        if (btn) {
          btn.disabled = false;
        }
        window.alert(error instanceof Error ? error.message : 'تعذر تفعيل إشعارات الجهاز.');
      }
    });

    banner.querySelector('[data-site-consent-pwa]')?.addEventListener('click', async () => {
      if (typeof window.PortalPwa?.install === 'function') {
        const installed = await window.PortalPwa.install();
        if (installed) {
          await refreshSiteConsentBanner(banner);
          return;
        }
      }
      window.PortalPwa?.open?.();
    });

    banner.querySelector('[data-site-consent-help]')?.addEventListener('click', () => {
      showNotificationSettingsHelp();
    });
  }

  function renderSiteConsentBanner() {
    if (document.getElementById('site-consent-banner') || consentDismissedRecently()) {
      return;
    }

    const banner = document.createElement('div');
    banner.id = 'site-consent-banner';
    banner.className = 'site-consent-banner';
    banner.setAttribute('data-site-consent-banner', '1');
    banner.innerHTML =
      '<div class="site-consent-banner__inner">' +
      '<span class="material-symbols-outlined" aria-hidden="true">notifications_active</span>' +
      '<div class="site-consent-banner__copy">' +
      '<strong data-site-consent-title>فعّل الإشعارات والتطبيق</strong>' +
      '<span data-site-consent-text>ابقَ على اطلاع بالعروض والتحديثات.</span>' +
      '</div>' +
      '<div class="site-consent-banner__actions" data-site-consent-actions></div>' +
      '<button type="button" class="site-consent-banner__close" data-site-consent-close aria-label="إغلاق">×</button>' +
      '</div>';

    banner.querySelector('[data-site-consent-close]')?.addEventListener('click', () => {
      rememberConsentDismiss();
      banner.remove();
    });

    document.body.appendChild(banner);
    refreshSiteConsentBanner(banner).then(() => {
      if (document.body.contains(banner)) {
        requestAnimationFrame(() => banner.classList.add('is-visible'));
      }
    });
  }

  async function initSiteConsentPrompt() {
    if (!isConsentAudience() || consentDismissedRecently()) {
      return;
    }
    if (countConsentVisit() < CONSENT_MIN_VISITS) {
      return;
    }
    await new Promise((resolve) => window.setTimeout(resolve, CONSENT_DELAY_MS));
    if (document.hidden || document.body.classList.contains('pwa-modal-open')) {
      return;
    }

    const pushNeeded = !(await isPushFullyEnabled()) && (await isPushServerSupported());
    const pwaNeeded = shouldPromptPwaInstall();
    if (!pushNeeded && !pwaNeeded) {
      return;
    }

    renderSiteConsentBanner();
  }

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event) => {
      const data = event.data || {};
      if (data.type !== 'portal-notification' || !data.payload) return;
      const payload = data.payload;
      announce({
        id: payload.id,
        title_ar: payload.title,
        body_ar: payload.body,
        link_url: payload.url,
        icon: 'notifications',
      });
      document.querySelectorAll('[data-notif-bell]').forEach((root) => {
        root.dispatchEvent(new CustomEvent('notif:incoming'));
      });
    });
  }

  function init() {
    document.querySelectorAll('[data-notif-bell]').forEach(initBell);
    initSiteConsentPrompt();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
