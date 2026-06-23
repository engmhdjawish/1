<?php

declare(strict_types=1);

/** @var array<string, int> $summary */
/** @var list<array<string, mixed>> $recent */
/** @var list<array<string, mixed>> $mapPoints */
/** @var int $days */
/** @var bool $schemaReady */

$summary = is_array($summary ?? null) ? $summary : [];
$recent = is_array($recent ?? null) ? $recent : [];
$mapPoints = is_array($mapPoints ?? null) ? $mapPoints : [];
$days = (int) ($days ?? 7);
$schemaReady = (bool) ($schemaReady ?? false);
$mapJson = json_encode($mapPoints, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<section class="mb-6">
  <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">نشاط الزوار والعملاء</h1>
      <p class="text-sm text-text-muted mt-1">
        تتبع زيارات الموقع والصفحات والمواقع الجغرافية للزوار خلال آخر <?= h((string) $days) ?> يوماً.
      </p>
    </div>
    <form method="get" class="flex items-end gap-3">
      <label class="text-sm">
        <span class="text-text-muted block mb-1">الفترة</span>
        <select name="days" class="h-11 rounded-xl border border-border-subtle px-3 focus:border-primary focus:ring-primary" onchange="this.form.submit()">
          <option value="1" <?= $days === 1 ? 'selected' : '' ?>>اليوم</option>
          <option value="7" <?= $days === 7 ? 'selected' : '' ?>>7 أيام</option>
          <option value="30" <?= $days === 30 ? 'selected' : '' ?>>30 يوماً</option>
          <option value="90" <?= $days === 90 ? 'selected' : '' ?>>90 يوماً</option>
        </select>
      </label>
    </form>
  </div>
</section>

<?php if (!$schemaReady): ?>
  <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
    جدول <code class="font-mono text-xs">visitor_logs</code> غير متوفر في قاعدة البيانات. شغّل سكربت المخطط من
    <code class="font-mono text-xs">docs/portal-db-schema.sql</code> لتفعيل التتبع.
  </div>
<?php endif; ?>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <div class="rounded-2xl border border-border-subtle bg-white p-4 shadow-sm">
    <p class="text-xs font-bold text-text-muted">زيارات الصفحات</p>
    <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= number_format((int) ($summary['page_views'] ?? 0)) ?></p>
  </div>
  <div class="rounded-2xl border border-border-subtle bg-white p-4 shadow-sm">
    <p class="text-xs font-bold text-text-muted">جلسات فريدة</p>
    <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= number_format((int) ($summary['unique_sessions'] ?? 0)) ?></p>
  </div>
  <div class="rounded-2xl border border-border-subtle bg-white p-4 shadow-sm">
    <p class="text-xs font-bold text-text-muted">عناوين IP</p>
    <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= number_format((int) ($summary['unique_ips'] ?? 0)) ?></p>
  </div>
  <div class="rounded-2xl border border-border-subtle bg-white p-4 shadow-sm">
    <p class="text-xs font-bold text-text-muted">زيارات مسجّلين</p>
    <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= number_format((int) ($summary['registered_hits'] ?? 0)) ?></p>
  </div>
</div>

<div class="rounded-2xl border border-border-subtle bg-white shadow-sm overflow-hidden mb-6">
  <div class="px-4 py-3 border-b border-border-subtle">
    <h2 class="text-lg font-extrabold text-slate-900">خريطة مواقع الزوار</h2>
    <p class="text-sm text-text-muted mt-0.5">مواقع تقريبية مستنتجة من عنوان IP.</p>
  </div>
  <div id="visitor-map" class="visitor-map" role="img" aria-label="خريطة مواقع الزوار"></div>
  <?php if ($mapPoints === []): ?>
    <p class="px-4 py-6 text-sm text-text-muted text-center">لا توجد بيانات موقع بعد. ستظهر بعد زيارات جديدة للموقع.</p>
  <?php endif; ?>
</div>

<div class="rounded-2xl border border-border-subtle bg-white shadow-sm overflow-hidden">
  <div class="px-4 py-3 border-b border-border-subtle">
    <h2 class="text-lg font-extrabold text-slate-900">آخر الأنشطة</h2>
  </div>
  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-surface-low text-text-muted">
        <tr>
          <th class="px-4 py-3 text-right font-bold">الوقت</th>
          <th class="px-4 py-3 text-right font-bold">الإجراء</th>
          <th class="px-4 py-3 text-right font-bold">الصفحة</th>
          <th class="px-4 py-3 text-right font-bold">الزائر</th>
          <th class="px-4 py-3 text-right font-bold">الموقع</th>
          <th class="px-4 py-3 text-right font-bold">IP</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-border-subtle">
        <?php if ($recent === []): ?>
          <tr><td colspan="6" class="px-4 py-8 text-center text-text-muted">لا يوجد نشاط مسجّل بعد.</td></tr>
        <?php else: ?>
          <?php foreach ($recent as $row): ?>
            <?php
            $isCustomer = !empty($row['web_customer_id']);
            $city = trim((string) ($row['city_ar'] ?? ''));
            $country = trim((string) ($row['country_ar'] ?? ''));
            $location = $city !== '' && $country !== '' ? $city . '، ' . $country : ($city !== '' ? $city : ($country !== '' ? $country : ''));
            $createdAt = (string) ($row['created_at'] ?? '');
            if ($createdAt !== '' && strtotime($createdAt) !== false) {
                $createdAt = date('Y-m-d H:i', strtotime($createdAt));
            }
            ?>
            <tr class="hover:bg-surface-low/60">
              <td class="px-4 py-3 text-text-muted whitespace-nowrap"><?= h($createdAt) ?></td>
              <td class="px-4 py-3"><span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-700"><?= h((string) ($row['action'] ?? '')) ?></span></td>
              <td class="px-4 py-3"><?= h((string) ($row['details_ar'] ?? '')) ?></td>
              <td class="px-4 py-3">
                <?php if ($isCustomer): ?>
                  <span class="inline-flex rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-bold text-green-800">عميل #<?= h((string) $row['web_customer_id']) ?></span>
                <?php else: ?>
                  <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600">زائر</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3"><?= h($location !== '' ? $location : '—') ?></td>
              <td class="px-4 py-3 text-text-muted font-mono text-xs"><?= h((string) ($row['visitor_ip'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<style>
.visitor-map { height: 420px; width: 100%; }
</style>
<script>
(() => {
  const points = <?= $mapJson ?: '[]' ?>;
  const el = document.getElementById('visitor-map');
  if (!el || !points.length || typeof L === 'undefined') return;

  const map = L.map(el, { scrollWheelZoom: false }).setView([24.0, 45.0], 4);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; OpenStreetMap'
  }).addTo(map);

  const bounds = [];
  points.forEach((p) => {
    const lat = parseFloat(p.latitude);
    const lng = parseFloat(p.longitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
    const label = [p.city, p.country].filter(Boolean).join('، ');
    const hits = parseInt(p.hits, 10) || 1;
    const radius = Math.min(28, 8 + Math.sqrt(hits) * 3);
    L.circleMarker([lat, lng], {
      radius,
      color: '#D81921',
      fillColor: '#D81921',
      fillOpacity: 0.55,
      weight: 2
    }).bindPopup(`<strong>${label || 'موقع'}</strong><br>زيارات: ${hits}`).addTo(map);
    bounds.push([lat, lng]);
  });

  if (bounds.length === 1) {
    map.setView(bounds[0], 8);
  } else if (bounds.length > 1) {
    map.fitBounds(bounds, { padding: [40, 40] });
  }
})();
</script>
