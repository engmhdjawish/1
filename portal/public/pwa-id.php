<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/views/helpers.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$origin = portal_request_origin();
?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="portal-build" content="<?= h(\Portal\Support\PwaBuild::id()) ?>">
<link rel="manifest" href="/manifest.php">
<title>هوية التطبيق</title>
<style>
body{font-family:system-ui,sans-serif;margin:16px;line-height:1.7;background:#f6f6f9;color:#222}
pre{background:#fff;border:1px solid #ddd;border-radius:8px;padding:10px;white-space:pre-wrap;word-break:break-all;direction:ltr;text-align:left}
.box{background:#fff3cd;border:1px solid #e0c060;border-radius:8px;padding:10px}
</style>
</head>
<body>
<h1>هوية التطبيق (تشخيص)</h1>
<p class="box">للحقيقة الكاملة: افتح Chrome واكتب في شريط العنوان <b dir="ltr">chrome://webapks</b> ثم صوّر الشاشة وأرسلها.
إن لم يظهر «جاويش» هناك فالأيقونة القديمة اختصار عادي (ليس تطبيقاً) — احذفها وثبّت من جديد.</p>
<pre id="out">جارٍ الفحص…</pre>
<script>
(async function () {
  var lines = [];
  var origin = <?= json_encode($origin, JSON_UNESCAPED_SLASHES) ?>;
  lines.push('page: ' + location.href);
  var standalone = matchMedia('(display-mode: standalone)').matches;
  lines.push('display-mode: ' + (standalone ? 'standalone (opened as app)' : (matchMedia('(display-mode: minimal-ui)').matches ? 'minimal-ui' : 'browser')));
  var link = document.querySelector('link[rel="manifest"]');
  lines.push('manifest link: ' + (link ? link.href : '(none)'));
  try {
    var m = await (await fetch('/manifest.php', { cache: 'no-store' })).json();
    var start = new URL(m.start_url, location.href);
    lines.push('id (computed): ' + new URL(m.id || m.start_url, start.origin).href);
    lines.push('start_url: ' + start.href);
    lines.push('scope: ' + m.scope);
    lines.push('display: ' + m.display);
  } catch (e) { lines.push('manifest error: ' + e); }
  if ('getInstalledRelatedApps' in navigator) {
    try {
      var apps = await navigator.getInstalledRelatedApps();
      lines.push('installed app with this identity: ' + (apps.length ? 'YES ' + JSON.stringify(apps) : 'NO'));
    } catch (e) { lines.push('getInstalledRelatedApps error: ' + e); }
  } else {
    lines.push('getInstalledRelatedApps: unsupported');
  }
  lines.push('expected origin: ' + origin);
  document.getElementById('out').textContent = lines.join('\n');
})();
</script>
</body>
</html>
