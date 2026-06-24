/**
 * PWA install prompt + service worker registration.
 */
(function () {
  'use strict';

  if (!('serviceWorker' in navigator)) {
    return;
  }

  let deferredPrompt = null;

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;
    showInstallBanner();
  });

  function showInstallBanner() {
    if (document.getElementById('pwa-install-banner')) {
      return;
    }
    if (window.matchMedia('(display-mode: standalone)').matches) {
      return;
    }

    const banner = document.createElement('div');
    banner.id = 'pwa-install-banner';
    banner.className = 'pwa-install-banner';
    banner.innerHTML =
      '<div class="pwa-install-banner__inner">' +
      '<span class="material-symbols-outlined" aria-hidden="true">install_mobile</span>' +
      '<div class="pwa-install-banner__text">' +
      '<strong>ثبّت التطبيق على هاتفك</strong>' +
      '<span>وصول أسرع للمتجر من الشاشة الرئيسية</span>' +
      '</div>' +
      '<button type="button" class="pwa-install-banner__btn" data-pwa-install>تثبيت</button>' +
      '<button type="button" class="pwa-install-banner__close" data-pwa-dismiss aria-label="إغلاق">×</button>' +
      '</div>';

    document.body.appendChild(banner);

    banner.querySelector('[data-pwa-install]')?.addEventListener('click', async () => {
      if (!deferredPrompt) {
        return;
      }
      deferredPrompt.prompt();
      await deferredPrompt.userChoice;
      deferredPrompt = null;
      banner.remove();
    });

    banner.querySelector('[data-pwa-dismiss]')?.addEventListener('click', () => {
      banner.remove();
      try {
        sessionStorage.setItem('pwa-install-dismissed', '1');
      } catch (_) {}
    });
  }

  if (!sessionStorage.getItem('pwa-install-dismissed')) {
    setTimeout(showInstallBanner, 2400);
  }

  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
  });
})();
