<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\PortalSettingsService;

require dirname(__DIR__) . '/views/helpers.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$company = PortalSettingsService::companySettings();
$logoUrl = PortalSettingsService::companyLogoUrl($company);
$icons = portal_site_icons($logoUrl ?? '');
$scheme = portal_request_scheme();
$host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
$pageUrl = $scheme . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/pwa-check.php');

$checks = [];

$checks[] = [
    'label' => 'الاتصال الآمن (HTTPS)',
    'ok' => portal_is_https_request(),
    'detail' => portal_is_https_request()
        ? 'الطلب يصل عبر HTTPS — جيد.'
        : 'الطلب يصل عبر HTTP. افتح الموقع بـ https://' . $host,
];

$checks[] = [
    'label' => 'ملف manifest.webmanifest',
    'ok' => is_file(dirname(__DIR__) . '/public/manifest.webmanifest'),
    'detail' => is_file(dirname(__DIR__) . '/public/manifest.webmanifest')
        ? '/manifest.webmanifest موجود (ملف ثابت — الأفضل للتثبيت)'
        : 'manifest.webmanifest مفقود — انسخه من آخر حزمة نشر',
];

$pwaHttpProbe = static function (string $path) use ($scheme, $host): array {
    $canonicalHost = (string) preg_replace('/:\d+$/', '', $host);
    $url = $scheme . '://' . $canonicalHost . $path;
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $matches)) {
        $status = (int) $matches[1];
    }

    return [
        'url' => $url,
        'status' => $status,
        'ok' => $status === 200 && is_string($body) && strlen($body) > 50,
    ];
};

$iconHttpChecks = [
    $pwaHttpProbe('/icons/icon-192.png'),
    $pwaHttpProbe('/icons/icon-512.png'),
    $pwaHttpProbe('/manifest.webmanifest'),
];
$iconsHttpOk = count(array_filter($iconHttpChecks, static fn (array $row): bool => (bool) $row['ok'])) >= 3;

$checks[] = [
    'label' => 'تحميل الأيقونات عبر HTTPS (Chrome يحتاج 200)',
    'ok' => $iconsHttpOk,
    'detail' => $iconsHttpOk
        ? 'icon-192.png و icon-512.png و manifest يُحمَّلون بنجاح.'
        : 'فشل تحميل أيقونة أو manifest — هذا يمنع التثبيت. انسخ ملفات icons/*.png من الحزمة.',
];

$iconChecks = [];
foreach ($icons['manifest_icons'] as $icon) {
    $src = (string) ($icon['src'] ?? '');
    $path = parse_url($src, PHP_URL_PATH) ?: $src;
    $file = dirname(__DIR__) . '/public' . $path;
    $iconChecks[] = [
        'src' => $src,
        'sizes' => (string) ($icon['sizes'] ?? ''),
        'exists' => is_file($file),
    ];
}
$allIconsOk = count(array_filter($iconChecks, static fn (array $row): bool => (bool) $row['exists'])) >= 2;

$checks[] = [
    'label' => 'أيقونات manifest (192 + 512)',
    'ok' => $allIconsOk,
    'detail' => $allIconsOk
        ? 'الأيقونات متوفرة بأبعاد صحيحة (بدون شعار الشركة في manifest).'
        : 'أيقونة أو أكثر مفقودة — راجع /icons/icon-192.png و icon-512.png',
];

$swFile = dirname(__DIR__) . '/public/sw.js';
$checks[] = [
    'label' => 'ملف Service Worker',
    'ok' => is_file($swFile),
    'detail' => is_file($swFile) ? '/sw.js موجود' : 'ملف sw.js مفقود',
];

$gdOk = function_exists('imagecreatetruecolor');
$checks[] = [
    'label' => 'امتداد PHP GD (اختياري)',
    'ok' => $gdOk || is_file(dirname(__DIR__) . '/public/icons/icon-192.png'),
    'detail' => $gdOk
        ? 'GD مفعّل — icon-png.php يولّد PNG ديناميكياً.'
        : 'GD غير مفعّل — يُستخدم icon-192.png الثابت (يجب أن يكون موجوداً).',
];

$allOk = count(array_filter($checks, static fn (array $row): bool => (bool) $row['ok'])) === count($checks);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>تشخيص PWA — جاويش</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 42rem; margin: 2rem auto; padding: 0 1rem; background: #f6f6f8; color: #111; }
    h1 { font-size: 1.35rem; }
    .card { background: #fff; border-radius: 12px; padding: 1rem 1.1rem; margin: 0.75rem 0; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .ok { color: #15803d; font-weight: 700; }
    .bad { color: #b91c1c; font-weight: 700; }
    ul { margin: 0.5rem 0 0; padding-right: 1.2rem; }
    code { background: #f1f5f9; padding: 0.1rem 0.35rem; border-radius: 4px; }
    #sw-status, #manifest-status { margin-top: 0.5rem; font-size: 0.9rem; }
    a { color: #2563eb; }
  </style>
  <link rel="manifest" href="/manifest.php">
</head>
<body>
  <h1>تشخيص تثبيت التطبيق (PWA)</h1>
  <p>الرابط: <code><?= h($pageUrl) ?></code></p>

  <?php foreach ($checks as $check): ?>
    <div class="card">
      <div class="<?= $check['ok'] ? 'ok' : 'bad' ?>"><?= $check['ok'] ? '✓' : '✗' ?> <?= h((string) $check['label']) ?></div>
      <div><?= h((string) $check['detail']) ?></div>
    </div>
  <?php endforeach; ?>

  <div class="card">
    <strong>فحص HTTP (مهم لـ Chrome)</strong>
    <ul>
      <?php foreach ($iconHttpChecks as $probe): ?>
        <li>
          <code><?= h($probe['url']) ?></code>
          — HTTP <?= (int) $probe['status'] ?>
          — <?= $probe['ok'] ? '✓' : '✗ فشل' ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="card">
    <strong>أيقونات manifest (على القرص)</strong>
    <ul>
      <?php foreach ($iconChecks as $icon): ?>
        <li>
          <code><?= h($icon['src']) ?></code>
          — <?= h($icon['sizes']) ?>
          — <?= $icon['exists'] ? 'موجود' : 'مفقود' ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="card">
    <strong>فحص المتصفح (JavaScript)</strong>
    <div id="sw-status">جاري فحص Service Worker…</div>
    <div id="manifest-status">جاري فحص manifest…</div>
    <div id="secure-status"></div>
    <div id="browser-protocol"></div>
    <div id="prompt-status"></div>
  </div>

  <div class="card">
    <p><strong>الخطوة التالية:</strong></p>
    <ul>
      <li>إن كان HTTPS ✗ — ثبّت شهادة SSL على IIS أو افتح الموقع عبر المنفذ 443.</li>
      <li>من Chrome: ⋮ → «تثبيت التطبيق» أو «Install Jawish».</li>
      <li>بعد التحديث: امسح cache المتصفح (Ctrl+Shift+Delete) ثم أعد التحميل.</li>
      <li><a href="/index.php">العودة للرئيسية</a></li>
    </ul>
  </div>

  <script>
    (function () {
      const secureEl = document.getElementById('secure-status');
      secureEl.textContent = window.isSecureContext
        ? '✓ المتصفح يرى السياق آمناً (isSecureContext)'
        : '✗ المتصفح لا يرى السياق آمناً — التثبيت التلقائي لن يعمل';

      document.getElementById('browser-protocol').textContent =
        window.location.protocol === 'https:'
          ? '✓ أنت تتصفح عبر ' + window.location.protocol
          : '✗ أنت على ' + window.location.protocol + ' — افتح https://' + window.location.hostname;

      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistration('/').then((reg) => {
          document.getElementById('sw-status').textContent = reg
            ? '✓ Service Worker مسجّل: ' + (reg.active ? reg.active.scriptURL : 'قيد التفعيل')
            : '✗ Service Worker غير مسجّل — سجّل من الصفحة الرئيسية أولاً';
        }).catch((err) => {
          document.getElementById('sw-status').textContent = '✗ خطأ SW: ' + err;
        });
      } else {
        document.getElementById('sw-status').textContent = '✗ المتصفح لا يدعم Service Worker';
      }

      fetch('/manifest.webmanifest', { cache: 'no-store' })
        .then((r) => r.json())
        .then(async (m) => {
          const n = (m.icons || []).length;
          let iconOk = true;
          for (const icon of (m.icons || []).slice(0, 2)) {
            try {
              const res = await fetch(icon.src, { cache: 'no-store' });
              if (!res.ok) {
                iconOk = false;
              }
            } catch (_) {
              iconOk = false;
            }
          }
          document.getElementById('manifest-status').textContent =
            (iconOk ? '✓' : '⚠') + ' manifest — ' + n + ' أيقونة، start_url=' + (m.start_url || '')
            + (iconOk ? '' : ' (تحقق من تحميل icon-192.png و icon-512.png)');
        })
        .catch((err) => {
          document.getElementById('manifest-status').textContent = '✗ فشل تحميل manifest: ' + err;
        });

      window.addEventListener('beforeinstallprompt', () => {
        document.getElementById('prompt-status').textContent =
          '✓ المتصفح جاهز للتثبيت التلقائي (beforeinstallprompt)';
      });
      window.setTimeout(() => {
        const el = document.getElementById('prompt-status');
        if (el && !el.textContent) {
          el.textContent = '— لم يظهر beforeinstallprompt بعد (استخدم التثبيت اليدوي من قائمة المتصفح)';
        }
      }, 3000);

      if (window.isSecureContext) {
        navigator.serviceWorker.register('/sw.js?v=6', { scope: '/', updateViaCache: 'none' }).catch(() => {});
      }
    })();
  </script>
</body>
</html>
