<?php

declare(strict_types=1);

/**
 * Forces a WebAPK/manifest refresh for homescreen icons stuck as Custom Tab (× bar).
 * Open this URL in full Chrome (not the broken icon), wait, then reopen the icon.
 */

define('PORTAL_NO_SESSION', true);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\PortalSettingsService;

require dirname(__DIR__) . '/views/helpers.php';

$company = PortalSettingsService::companySettings();
$siteName = trim((string) ($company['company_name'] ?? '')) !== ''
    ? (string) $company['company_name']
    : 'جاويش للتجارة';
$origin = portal_request_origin();
$host = portal_request_host();
$manifestUrl = '/manifest.php?heal=' . rawurlencode((string) time());
$homeUrl = '/?source=pwa&repaired=1';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#D81921">
  <meta name="robots" content="noindex, nofollow">
  <link rel="manifest" href="<?= h($manifestUrl) ?>">
  <title>تحديث تطبيق <?= h($siteName) ?></title>
  <style>
    :root { color-scheme: light; }
    body {
      margin: 0;
      min-height: 100dvh;
      font: 400 16px/1.6 Manrope, Tahoma, sans-serif;
      background: linear-gradient(165deg, #fff5f5 0%, #f6f6f8 45%, #ffffff 100%);
      color: #1c1917;
      display: grid;
      place-items: center;
      padding: 24px 18px calc(24px + env(safe-area-inset-bottom));
    }
    main {
      width: min(420px, 100%);
    }
    h1 {
      margin: 0 0 10px;
      font-size: 1.45rem;
      font-weight: 800;
      color: #D81921;
    }
    p { margin: 0 0 12px; color: #44403c; }
    ol { margin: 0 0 18px; padding-inline-start: 1.2rem; color: #292524; }
    li { margin-bottom: 8px; }
    .status {
      min-height: 1.4em;
      margin-bottom: 14px;
      font-weight: 700;
      color: #b91c1c;
    }
    .status.is-ok { color: #15803d; }
    a.btn, button.btn {
      display: block;
      width: 100%;
      box-sizing: border-box;
      margin: 0 0 10px;
      padding: 14px 16px;
      border: 0;
      border-radius: 12px;
      background: #D81921;
      color: #fff;
      font: 700 15px/1.2 Manrope, Tahoma, sans-serif;
      text-align: center;
      text-decoration: none;
      cursor: pointer;
    }
    a.btn--ghost, button.btn--ghost {
      background: transparent;
      color: #D81921;
      border: 1px solid #f0b4b7;
    }
  </style>
  <script>
    if ('serviceWorker' in navigator && window.isSecureContext) {
      navigator.serviceWorker.register('/sw.js?v=14', { scope: '/', updateViaCache: 'none' }).catch(function () {});
    }
  </script>
</head>
<body>
  <main>
    <h1>تحديث التطبيق تلقائياً</h1>
    <p>شريط × يظهر لأن النسخة القديمة تحتاج تحديثاً من Chrome — بدون حذف التطبيق.</p>
    <p class="status" id="status">جاري تحديث الملفات…</p>
    <ol>
      <li>اترك هذه الصفحة مفتوحة حتى تظهر «تم التحديث».</li>
      <li>أغلق كل نوافذ جاويش من قائمة التطبيقات الأخيرة.</li>
      <li>افتح أيقونة جاويش من الشاشة الرئيسية.</li>
    </ol>
    <a class="btn" id="open-chrome" href="<?= h($origin !== '' ? $origin . '/pwa-repair.php' : '/pwa-repair.php') ?>">إعادة تشغيل التحديث</a>
    <a class="btn btn--ghost" href="<?= h($homeUrl) ?>">العودة للمتجر</a>
  </main>
  <script>
    (function () {
      var status = document.getElementById('status');
      var host = <?= json_encode($host, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      var chromeIntent = host
        ? ('intent://' + host + '/pwa-repair.php#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url='
          + encodeURIComponent('https://' + host + '/pwa-repair.php') + ';end')
        : '';
      var openChrome = document.getElementById('open-chrome');
      if (chromeIntent && /Android/i.test(navigator.userAgent || '')) {
        openChrome.setAttribute('href', chromeIntent);
        openChrome.textContent = 'فتح التحديث داخل Chrome';
      }

      function setStatus(text, ok) {
        status.textContent = text;
        status.classList.toggle('is-ok', !!ok);
      }

      async function run() {
        try {
          await fetch(<?= json_encode($manifestUrl, JSON_UNESCAPED_SLASHES) ?>, {
            cache: 'reload',
            credentials: 'same-origin',
            headers: { Accept: 'application/manifest+json' },
          });
          if ('serviceWorker' in navigator) {
            var reg = await navigator.serviceWorker.register('/sw.js?v=14', {
              scope: '/',
              updateViaCache: 'none',
            });
            if (reg && reg.update) {
              await reg.update();
            }
            if (reg && reg.waiting) {
              reg.waiting.postMessage({ type: 'SKIP_WAITING' });
            }
          }
          if (typeof navigator.getInstalledRelatedApps === 'function') {
            try {
              await navigator.getInstalledRelatedApps();
            } catch (e) {}
          }
          setStatus('تم التحديث. أغلق التطبيق بالكامل ثم افتح الأيقونة من الشاشة الرئيسية.', true);
        } catch (err) {
          setStatus('تعذر التحديث تلقائياً. افتح هذه الصفحة من Chrome ثم أعد المحاولة.', false);
        }
      }

      run();
    })();
  </script>
</body>
</html>
