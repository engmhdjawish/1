<?php

declare(strict_types=1);

/** @var array<string, int> $summary */
/** @var list<array<string, mixed>> $recent */
/** @var list<array<string, mixed>> $topProducts */
/** @var list<array<string, mixed>> $topPages */
/** @var list<array<string, mixed>> $actionBreakdown */
/** @var list<array<string, mixed>> $topReferrers */
/** @var list<array<string, mixed>> $sessions */
/** @var list<array<string, mixed>> $sessionEvents */
/** @var list<array<string, mixed>> $mapPoints */
/** @var int $days */
/** @var bool $schemaReady */
/** @var string $sessionId */

use Portal\Services\VisitorLogService;

$summary = is_array($summary ?? null) ? $summary : [];
$recent = is_array($recent ?? null) ? $recent : [];
$topProducts = is_array($topProducts ?? null) ? $topProducts : [];
$topPages = is_array($topPages ?? null) ? $topPages : [];
$actionBreakdown = is_array($actionBreakdown ?? null) ? $actionBreakdown : [];
$topReferrers = is_array($topReferrers ?? null) ? $topReferrers : [];
$sessions = is_array($sessions ?? null) ? $sessions : [];
$sessionEvents = is_array($sessionEvents ?? null) ? $sessionEvents : [];
$mapPoints = is_array($mapPoints ?? null) ? $mapPoints : [];
$days = (int) ($days ?? 7);
$schemaReady = (bool) ($schemaReady ?? false);
$sessionId = trim((string) ($sessionId ?? ''));
$mapJson = json_encode($mapPoints, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

$buildUrl = static function (array $params = []) use ($days, $sessionId): string {
    $query = array_filter(array_merge(['days' => $days], $params), static fn ($value) => $value !== null && $value !== '');

    return '/dashboard/visitor-analytics.php?' . http_build_query($query);
};
?>
<section class="mb-6">
  <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">نشاط الزوار والعملاء</h1>
      <p class="text-sm text-text-muted mt-1">
        رؤية تفصيلية لزيارات الموقع، اهتمام الزوار بالأصناف، وسلوك الجلسات خلال آخر <?= h((string) $days) ?> يوماً.
      </p>
    </div>
    <form method="get" class="flex items-end gap-3">
      <?php if ($sessionId !== ''): ?>
        <input type="hidden" name="session" value="<?= h($sessionId) ?>">
      <?php endif; ?>
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
    جدول <code class="font-mono text-xs">visitor_logs</code> غير متوفر. شغّل:
    <code class="font-mono text-xs">docs/portal-migrations/005-visitor-logs.sql</code>
  </div>
<?php endif; ?>

<div class="grid grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-3 mb-6">
  <?php
  $statCards = [
      ['label' => 'إجمالي الأحداث', 'value' => $summary['total_events'] ?? 0],
      ['label' => 'زيارات الصفحات', 'value' => $summary['page_views'] ?? 0],
      ['label' => 'مشاهدات الأصناف', 'value' => $summary['product_views'] ?? 0],
      ['label' => 'إضافات للسلة', 'value' => $summary['cart_adds'] ?? 0],
      ['label' => 'جلسات فريدة', 'value' => $summary['unique_sessions'] ?? 0],
      ['label' => 'عناوين IP', 'value' => $summary['unique_ips'] ?? 0],
      ['label' => 'زيارات مسجّلين', 'value' => $summary['registered_hits'] ?? 0],
  ];
  foreach ($statCards as $card): ?>
    <div class="rounded-2xl border border-border-subtle bg-white p-4 shadow-sm">
      <p class="text-xs font-bold text-text-muted"><?= h($card['label']) ?></p>
      <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= number_format((int) $card['value']) ?></p>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($actionBreakdown !== []): ?>
  <div class="mb-6 flex flex-wrap gap-2">
    <?php foreach ($actionBreakdown as $item): ?>
      <span class="inline-flex items-center gap-2 rounded-full border border-border-subtle bg-white px-3 py-1.5 text-xs font-bold text-slate-700">
        <?= h((string) ($item['label_ar'] ?? $item['action'] ?? '')) ?>
        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-900"><?= number_format((int) ($item['hits'] ?? 0)) ?></span>
      </span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
  <div class="rounded-2xl border border-border-subtle bg-white shadow-sm overflow-hidden">
    <div class="px-4 py-3 border-b border-border-subtle">
      <h2 class="text-lg font-extrabold text-slate-900">الأصناف الأكثر اهتماماً</h2>
      <p class="text-sm text-text-muted mt-0.5">حسب المعاينة، العرض، والإضافة للسلة.</p>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-surface-low text-text-muted">
          <tr>
            <th class="px-4 py-3 text-right font-bold">الصنف</th>
            <th class="px-4 py-3 text-right font-bold">مشاهدات</th>
            <th class="px-4 py-3 text-right font-bold">سلة</th>
            <th class="px-4 py-3 text-right font-bold">الإجمالي</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php if ($topProducts === []): ?>
            <tr><td colspan="4" class="px-4 py-8 text-center text-text-muted">لا توجد بيانات أصناف بعد. ستظهر عند تصفح المنتجات أو إضافتها للسلة.</td></tr>
          <?php else: ?>
            <?php foreach ($topProducts as $row): ?>
              <tr class="hover:bg-surface-low/60">
                <td class="px-4 py-3">
                  <div class="font-bold text-slate-900"><?= h((string) ($row['product_name'] ?? 'صنف')) ?></div>
                  <?php if (!empty($row['product_code'])): ?>
                    <div class="text-xs text-text-muted font-mono mt-0.5"><?= h((string) $row['product_code']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3"><?= number_format((int) ($row['views'] ?? 0)) ?></td>
                <td class="px-4 py-3"><?= number_format((int) ($row['cart_adds'] ?? 0)) ?></td>
                <td class="px-4 py-3 font-extrabold text-primary"><?= number_format((int) ($row['total_interest'] ?? 0)) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="rounded-2xl border border-border-subtle bg-white shadow-sm overflow-hidden">
    <div class="px-4 py-3 border-b border-border-subtle">
      <h2 class="text-lg font-extrabold text-slate-900">أكثر الصفحات زيارة</h2>
      <p class="text-sm text-text-muted mt-0.5">مسارات الصفحات الأكثر تصفحاً.</p>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-surface-low text-text-muted">
          <tr>
            <th class="px-4 py-3 text-right font-bold">الصفحة</th>
            <th class="px-4 py-3 text-right font-bold">الزيارات</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php if ($topPages === []): ?>
            <tr><td colspan="2" class="px-4 py-8 text-center text-text-muted">لا توجد صفحات مسجّلة بعد.</td></tr>
          <?php else: ?>
            <?php foreach ($topPages as $row): ?>
              <tr class="hover:bg-surface-low/60">
                <td class="px-4 py-3">
                  <div class="font-mono text-xs text-slate-800"><?= h((string) ($row['page_path'] ?? '')) ?></div>
                  <?php if (!empty($row['page_title'])): ?>
                    <div class="text-xs text-text-muted mt-0.5"><?= h((string) $row['page_title']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 font-extrabold"><?= number_format((int) ($row['hits'] ?? 0)) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
  <div class="xl:col-span-2 rounded-2xl border border-border-subtle bg-white shadow-sm overflow-hidden">
    <div class="px-4 py-3 border-b border-border-subtle">
      <h2 class="text-lg font-extrabold text-slate-900">جلسات الزوار</h2>
      <p class="text-sm text-text-muted mt-0.5">اضغط «تفاصيل» لمتابعة مسار جلسة واحدة.</p>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-surface-low text-text-muted">
          <tr>
            <th class="px-4 py-3 text-right font-bold">آخر نشاط</th>
            <th class="px-4 py-3 text-right font-bold">الزائر</th>
            <th class="px-4 py-3 text-right font-bold">الأحداث</th>
            <th class="px-4 py-3 text-right font-bold">أصناف</th>
            <th class="px-4 py-3 text-right font-bold">سلة</th>
            <th class="px-4 py-3 text-right font-bold">الموقع</th>
            <th class="px-4 py-3 text-right font-bold"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php if ($sessions === []): ?>
            <tr><td colspan="7" class="px-4 py-8 text-center text-text-muted">لا توجد جلسات مسجّلة بعد.</td></tr>
          <?php else: ?>
            <?php foreach ($sessions as $row): ?>
              <?php
              $isCustomer = !empty($row['web_customer_id']);
              $city = trim((string) ($row['city_ar'] ?? ''));
              $country = trim((string) ($row['country_ar'] ?? ''));
              $location = $city !== '' && $country !== '' ? $city . '، ' . $country : ($city !== '' ? $city : ($country !== '' ? $country : '—'));
              ?>
              <tr class="hover:bg-surface-low/60">
                <td class="px-4 py-3 text-text-muted whitespace-nowrap"><?= h((string) ($row['last_seen_fmt'] ?? '')) ?></td>
                <td class="px-4 py-3">
                  <?php if ($isCustomer): ?>
                    <span class="inline-flex rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-bold text-green-800">عميل</span>
                  <?php else: ?>
                    <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600">زائر</span>
                  <?php endif; ?>
                  <div class="text-xs text-text-muted font-mono mt-1"><?= h((string) ($row['visitor_ip'] ?? '')) ?></div>
                </td>
                <td class="px-4 py-3"><?= number_format((int) ($row['events'] ?? 0)) ?></td>
                <td class="px-4 py-3"><?= number_format((int) ($row['product_views'] ?? 0)) ?></td>
                <td class="px-4 py-3"><?= number_format((int) ($row['cart_adds'] ?? 0)) ?></td>
                <td class="px-4 py-3"><?= h($location) ?></td>
                <td class="px-4 py-3">
                  <a href="<?= h($buildUrl(['session' => (string) ($row['session_id'] ?? '')])) ?>" class="text-primary font-bold hover:underline">تفاصيل</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="rounded-2xl border border-border-subtle bg-white shadow-sm overflow-hidden">
    <div class="px-4 py-3 border-b border-border-subtle">
      <h2 class="text-lg font-extrabold text-slate-900">مصادر الزيارة</h2>
      <p class="text-sm text-text-muted mt-0.5">من أين أتى الزائر قبل دخول الموقع.</p>
    </div>
    <div class="divide-y divide-border-subtle">
      <?php if ($topReferrers === []): ?>
        <p class="px-4 py-8 text-center text-sm text-text-muted">لا توجد بيانات مصدر بعد.</p>
      <?php else: ?>
        <?php foreach ($topReferrers as $row): ?>
          <div class="px-4 py-3 flex items-center justify-between gap-3">
            <span class="text-sm text-slate-800 break-all"><?= h((string) ($row['referer'] ?? '')) ?></span>
            <span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-900"><?= number_format((int) ($row['hits'] ?? 0)) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($sessionId !== ''): ?>
  <div class="rounded-2xl border border-primary/20 bg-white shadow-sm overflow-hidden mb-6">
    <div class="px-4 py-3 border-b border-border-subtle flex items-center justify-between gap-3">
      <div>
        <h2 class="text-lg font-extrabold text-slate-900">تفاصيل الجلسة</h2>
        <p class="text-xs text-text-muted font-mono mt-0.5"><?= h($sessionId) ?></p>
      </div>
      <a href="<?= h($buildUrl(['session' => null])) ?>" class="text-sm font-bold text-primary hover:underline">إغلاق</a>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-surface-low text-text-muted">
          <tr>
            <th class="px-4 py-3 text-right font-bold">الوقت</th>
            <th class="px-4 py-3 text-right font-bold">النشاط</th>
            <th class="px-4 py-3 text-right font-bold">التفاصيل</th>
            <th class="px-4 py-3 text-right font-bold">المصدر</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
          <?php if ($sessionEvents === []): ?>
            <tr><td colspan="4" class="px-4 py-8 text-center text-text-muted">لا توجد أحداث لهذه الجلسة.</td></tr>
          <?php else: ?>
            <?php foreach ($sessionEvents as $row): ?>
              <tr class="hover:bg-surface-low/60">
                <td class="px-4 py-3 text-text-muted whitespace-nowrap"><?= h((string) ($row['created_at_fmt'] ?? '')) ?></td>
                <td class="px-4 py-3"><span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-700"><?= h((string) ($row['action_label_ar'] ?? '')) ?></span></td>
                <td class="px-4 py-3"><?= h((string) ($row['label_ar'] ?? '')) ?></td>
                <td class="px-4 py-3 text-text-muted"><?= h((string) ($row['referer_short'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="rounded-2xl border border-border-subtle bg-white shadow-sm overflow-hidden mb-6">
  <div class="px-4 py-3 border-b border-border-subtle">
    <h2 class="text-lg font-extrabold text-slate-900">خريطة مواقع الزوار</h2>
    <p class="text-sm text-text-muted mt-0.5">مواقع تقريبية مستنتجة من عنوان IP.</p>
  </div>
  <div id="visitor-map" class="visitor-map" role="img" aria-label="خريطة مواقع الزوار"></div>
  <?php if ($mapPoints === []): ?>
    <p class="px-4 py-6 text-sm text-text-muted text-center">لا توجد بيانات موقع جغرافي بعد.</p>
  <?php endif; ?>
</div>

<div class="rounded-2xl border border-border-subtle bg-white shadow-sm overflow-hidden">
  <div class="px-4 py-3 border-b border-border-subtle">
    <h2 class="text-lg font-extrabold text-slate-900">سجل النشاط التفصيلي</h2>
    <p class="text-sm text-text-muted mt-0.5">آخر <?= count($recent) ?> حدثاً مسجّلاً.</p>
  </div>
  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-surface-low text-text-muted">
        <tr>
          <th class="px-4 py-3 text-right font-bold">الوقت</th>
          <th class="px-4 py-3 text-right font-bold">النشاط</th>
          <th class="px-4 py-3 text-right font-bold">التفاصيل</th>
          <th class="px-4 py-3 text-right font-bold">الزائر</th>
          <th class="px-4 py-3 text-right font-bold">المصدر</th>
          <th class="px-4 py-3 text-right font-bold">الموقع</th>
          <th class="px-4 py-3 text-right font-bold">IP</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-border-subtle">
        <?php if ($recent === []): ?>
          <tr><td colspan="7" class="px-4 py-8 text-center text-text-muted">لا يوجد نشاط مسجّل بعد.</td></tr>
        <?php else: ?>
          <?php foreach ($recent as $row): ?>
            <?php
            $isCustomer = !empty($row['web_customer_id']);
            $city = trim((string) ($row['city_ar'] ?? ''));
            $country = trim((string) ($row['country_ar'] ?? ''));
            $location = $city !== '' && $country !== '' ? $city . '، ' . $country : ($city !== '' ? $city : ($country !== '' ? $country : '—'));
            ?>
            <tr class="hover:bg-surface-low/60">
              <td class="px-4 py-3 text-text-muted whitespace-nowrap"><?= h((string) ($row['created_at_fmt'] ?? '')) ?></td>
              <td class="px-4 py-3"><span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-700"><?= h((string) ($row['action_label_ar'] ?? '')) ?></span></td>
              <td class="px-4 py-3">
                <div><?= h((string) ($row['label_ar'] ?? '')) ?></div>
                <?php if (!empty($row['page_path'])): ?>
                  <div class="text-xs text-text-muted font-mono mt-0.5"><?= h((string) $row['page_path']) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3">
                <?php if ($isCustomer): ?>
                  <span class="inline-flex rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-bold text-green-800">عميل</span>
                <?php else: ?>
                  <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600">زائر</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-text-muted"><?= h((string) ($row['referer_short'] ?? '')) ?></td>
              <td class="px-4 py-3"><?= h($location) ?></td>
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
