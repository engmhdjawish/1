<?php

declare(strict_types=1);

use Portal\Services\VisitorLogService;

/** @var callable(array<string, mixed>): string $buildUrl */
/** @var array<string, int> $summary */
/** @var list<array<string, mixed>> $recent */
/** @var list<array<string, mixed>> $topProducts */
/** @var list<array<string, mixed>> $topPages */
/** @var list<array<string, mixed>> $sessions */
/** @var list<array<string, mixed>> $accountGroups */
/** @var array<string, mixed>|null $accountProfile */
/** @var array<string, mixed>|null $sessionDigest */
/** @var string $accountKey */
/** @var string $visitorFilter */
$accountGroups = $accountGroups ?? [];
$accountProfile = $accountProfile ?? null;
$sessionDigest = $sessionDigest ?? null;
$accountKey = trim((string) ($accountKey ?? ''));
$visitorFilter = trim((string) ($visitorFilter ?? 'all'));
/** @var list<array<string, mixed>> $mapPoints */
/** @var list<array<string, mixed>> $locationStats */
/** @var list<array<string, mixed>> $onlineStaff */
/** @var list<array<string, mixed>> $onlineCustomers */
/** @var list<array<string, mixed>> $onlineGuests */
/** @var array{staff: int, customers: int, guests: int, total: int} $onlineCounts */
/** @var int $days */
/** @var string $tab */
/** @var bool $schemaReady */
/** @var bool $presenceReady */
/** @var bool $sessionsReady */
/** @var bool $canManageSessions */
/** @var string $sessionId */
/** @var string $customerId */
/** @var string $searchQ */
/** @var array<string, mixed>|null $filteredCustomer */
/** @var string|null $flash */
/** @var string $flashType */

$tab = in_array($tab ?? '', ['now', 'log', 'insights'], true) ? $tab : 'now';
$days = (int) ($days ?? 7);
$schemaReady = (bool) ($schemaReady ?? false);
$presenceReady = (bool) ($presenceReady ?? false);
$sessionsReady = (bool) ($sessionsReady ?? false);
$canManageSessions = (bool) ($canManageSessions ?? false);
$sessionId = trim((string) ($sessionId ?? ''));
$customerId = trim((string) ($customerId ?? ''));
$searchQ = trim((string) ($searchQ ?? ''));
$filteredCustomer = $filteredCustomer ?? null;
$mapJson = json_encode($mapPoints ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

$identityPill = static function (array $row): string {
    $kind = (string) ($row['identity_kind'] ?? '');
    if ($kind === 'customer') {
        return 'is-customer';
    }
    if ($kind === 'guest_order') {
        return 'is-known-guest';
    }

    return '';
};

$renderIdentityName = static function (array $row): string {
    $name = trim((string) ($row['display_name'] ?? ''));
    if ($name === '') {
        return 'زائر';
    }
    $subtitle = trim((string) ($row['identity_subtitle'] ?? ''));
    if ($subtitle !== '' && $subtitle !== $name) {
        return $name . ' · ' . $subtitle;
    }

    return $name;
};

$formatSeen = static function (?string $value): string {
    if ($value === null || trim($value) === '') {
        return '—';
    }
    $ts = strtotime($value);

    return $ts === false ? $value : date('Y-m-d H:i', $ts);
};

$locationLabel = static function (?string $city, ?string $country): string {
    $city = trim((string) $city);
    $country = trim((string) $country);
    if ($city !== '' && $country !== '') {
        return $city . '، ' . $country;
    }

    return $city !== '' ? $city : ($country !== '' ? $country : '—');
};

$guestSessionFromPresence = static function (array $row): string {
    $key = (string) ($row['presence_key'] ?? '');
    if (str_starts_with($key, 'guest:')) {
        return substr($key, 6);
    }

    return '';
};
?>
<section class="visitor-log">
  <div class="visitor-log__head">
    <div>
      <h1 class="visitor-log__title">سجل الزوار</h1>
      <p class="visitor-log__subtitle">المتصلون الآن، ملفات الزوار، ومسارات التحويل — في مكان واحد.</p>
    </div>
    <?php if ($tab !== 'now'): ?>
      <form method="get" class="visitor-log__period">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <?php if ($sessionId !== ''): ?>
          <input type="hidden" name="session" value="<?= h($sessionId) ?>">
        <?php endif; ?>
        <?php if ($accountKey !== ''): ?>
          <input type="hidden" name="account" value="<?= h($accountKey) ?>">
        <?php endif; ?>
        <?php if ($customerId !== ''): ?>
          <input type="hidden" name="customer_id" value="<?= h($customerId) ?>">
        <?php endif; ?>
        <?php if ($searchQ !== ''): ?>
          <input type="hidden" name="q" value="<?= h($searchQ) ?>">
        <?php endif; ?>
        <?php if ($visitorFilter !== 'all'): ?>
          <input type="hidden" name="filter" value="<?= h($visitorFilter) ?>">
        <?php endif; ?>
        <label>
          <span class="visitor-log__period-label">الفترة</span>
          <select name="days" class="visitor-log__period-select" onchange="this.form.submit()">
            <option value="1" <?= $days === 1 ? 'selected' : '' ?>>اليوم</option>
            <option value="7" <?= $days === 7 ? 'selected' : '' ?>>7 أيام</option>
            <option value="30" <?= $days === 30 ? 'selected' : '' ?>>30 يوماً</option>
            <option value="90" <?= $days === 90 ? 'selected' : '' ?>>90 يوماً</option>
          </select>
        </label>
      </form>
    <?php endif; ?>
  </div>

  <nav class="visitor-log__tabs" aria-label="أقسام سجل الزوار">
    <a href="<?= h($buildUrl(['tab' => 'now', 'session' => null])) ?>" class="visitor-log__tab<?= $tab === 'now' ? ' is-active' : '' ?>">
      <span class="material-symbols-outlined" aria-hidden="true">sensors</span>
      الآن
      <?php if (($onlineCounts['total'] ?? 0) > 0): ?>
        <span class="visitor-log__tab-badge"><?= (int) $onlineCounts['total'] ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= h($buildUrl(['tab' => 'log', 'account' => $accountKey !== '' ? $accountKey : null, 'session' => $sessionId !== '' ? $sessionId : null])) ?>" class="visitor-log__tab<?= $tab === 'log' ? ' is-active' : '' ?>">
      <span class="material-symbols-outlined" aria-hidden="true">groups</span>
      الزوار
    </a>
    <a href="<?= h($buildUrl(['tab' => 'insights', 'session' => null])) ?>" class="visitor-log__tab<?= $tab === 'insights' ? ' is-active' : '' ?>">
      <span class="material-symbols-outlined" aria-hidden="true">insights</span>
      الملخص
    </a>
  </nav>

  <?php if (!empty($flash)): ?>
    <p class="visitor-log__flash <?= $flashType === 'error' ? 'is-error' : 'is-success' ?>"><?= h((string) $flash) ?></p>
  <?php endif; ?>

  <?php if (!$schemaReady && $tab !== 'now'): ?>
    <div class="visitor-log__notice">جدول <code>visitor_logs</code> غير متوفر. شغّل ترحيل <code>005-visitor-logs.sql</code>.</div>
  <?php endif; ?>

  <?php if ($tab === 'now'): ?>
    <?php if (!$sessionsReady && !$presenceReady): ?>
      <div class="visitor-log__notice">تتبع المتصلين غير مفعّل. شغّل ترحيلات <code>009</code> و<code>011</code>.</div>
    <?php else: ?>
      <div class="visitor-log__stats">
        <div class="visitor-log__stat"><span>متصلون الآن</span><strong><?= (int) ($onlineCounts['total'] ?? 0) ?></strong></div>
        <div class="visitor-log__stat"><span>موظفون</span><strong class="text-indigo-700"><?= (int) ($onlineCounts['staff'] ?? 0) ?></strong></div>
        <div class="visitor-log__stat"><span>عملاء</span><strong class="text-emerald-700"><?= (int) ($onlineCounts['customers'] ?? 0) ?></strong></div>
        <div class="visitor-log__stat"><span>زوار</span><strong class="text-sky-700"><?= (int) ($onlineCounts['guests'] ?? 0) ?></strong></div>
      </div>

      <?php if ($canManageSessions): ?>
        <div class="visitor-log__toolbar">
          <form method="post" onsubmit="return confirm('إنهاء كل جلسات الموظفين المتصلين؟');">
            <input type="hidden" name="action" value="revoke_all_online">
            <input type="hidden" name="kind" value="staff">
            <button type="submit" class="visitor-log__tool-btn">إنهاء جلسات الموظفين</button>
          </form>
          <form method="post" onsubmit="return confirm('إنهاء كل جلسات العملاء المتصلين؟');">
            <input type="hidden" name="action" value="revoke_all_online">
            <input type="hidden" name="kind" value="customer">
            <button type="submit" class="visitor-log__tool-btn">إنهاء جلسات العملاء</button>
          </form>
        </div>
        <p class="visitor-log__hint">إنهاء جلستك الحالية يُخرجك من لوحة التحكم.</p>
      <?php endif; ?>

      <div class="visitor-log__grid">
        <?php
        $liveSections = [
            ['title' => 'موظفون', 'rows' => $onlineStaff, 'kind' => 'staff', 'empty' => 'لا موظفين متصلين.'],
            ['title' => 'عملاء', 'rows' => $onlineCustomers, 'kind' => 'customer', 'empty' => 'لا عملاء متصلين.'],
        ];
        foreach ($liveSections as $section):
        ?>
          <article class="visitor-log__panel">
            <header class="visitor-log__panel-head">
              <h2><?= h($section['title']) ?></h2>
              <span><?= count($section['rows']) ?> متصل</span>
            </header>
            <?php if ($section['rows'] === []): ?>
              <p class="visitor-log__empty"><?= h($section['empty']) ?></p>
            <?php else: ?>
              <ul class="visitor-log__live-list">
                <?php foreach ($section['rows'] as $row): ?>
                  <li>
                    <div class="visitor-log__live-main">
                      <strong><?= h((string) ($row['display_name'] ?? '—')) ?></strong>
                      <span><?= h($formatSeen((string) ($row['last_seen_at'] ?? ''))) ?></span>
                    </div>
                    <div class="visitor-log__live-meta" dir="ltr"><?= h((string) ($row['created_ip'] ?? '—')) ?></div>
                    <div class="visitor-log__live-actions">
                      <?php if ($section['kind'] === 'customer' && trim((string) ($row['subject_id'] ?? '')) !== ''): ?>
                        <a href="<?= h($buildUrl(['tab' => 'log', 'customer_id' => (string) $row['subject_id'], 'account' => 'customer:' . (string) $row['subject_id'], 'session' => null])) ?>" class="visitor-log__action">عرض النشاط</a>
                        <a href="/dashboard/customers.php?details=<?= h((string) $row['subject_id']) ?>" class="visitor-log__action">الملف</a>
                      <?php endif; ?>
                    <?php if ($canManageSessions): ?>
                        <form method="post" onsubmit="return confirm('إنهاء هذه الجلسة؟');">
                          <input type="hidden" name="action" value="revoke_one">
                          <input type="hidden" name="kind" value="<?= h($section['kind']) ?>">
                          <input type="hidden" name="session_id" value="<?= h((string) ($row['session_id'] ?? '')) ?>">
                          <button type="submit" class="visitor-log__action visitor-log__action--danger">إنهاء</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>

      <article class="visitor-log__panel visitor-log__panel--wide">
        <header class="visitor-log__panel-head">
          <h2>زوار غير مسجّلين</h2>
          <span><?= count($onlineGuests) ?> متصل</span>
        </header>
        <?php if ($onlineGuests === []): ?>
          <p class="visitor-log__empty">لا زوار متصلين حالياً. يظهرون هنا أثناء تصفح الموقع (آخر 5 دقائق).</p>
        <?php else: ?>
          <ul class="visitor-log__live-list">
            <?php foreach ($onlineGuests as $row): ?>
              <?php $guestSession = $guestSessionFromPresence($row); ?>
              <li>
                <div class="visitor-log__live-main">
                  <strong><?= h($renderIdentityName($row)) ?></strong>
                  <span><?= h($formatSeen((string) ($row['last_seen_at'] ?? ''))) ?></span>
                </div>
                <div class="visitor-log__live-meta">
                  <span dir="ltr"><?= h((string) ($row['visitor_ip'] ?? '—')) ?></span>
                  <?php if (trim((string) ($row['identity_phone'] ?? '')) !== ''): ?>
                    <span dir="ltr"> · <?= h((string) $row['identity_phone']) ?></span>
                  <?php endif; ?>
                  <span> · <?= h($locationLabel((string) ($row['city_ar'] ?? ''), (string) ($row['country_ar'] ?? ''))) ?></span>
                </div>
                <div class="visitor-log__live-actions">
                  <?php if ($guestSession !== ''): ?>
                    <a href="<?= h($buildUrl(['tab' => 'log', 'account' => 'session:' . $guestSession, 'session' => null])) ?>" class="visitor-log__action">عرض السجل</a>
                  <?php endif; ?>
                  <?php if (!empty($row['web_customer_id'])): ?>
                    <a href="<?= h($buildUrl(['tab' => 'log', 'account' => 'customer:' . (string) $row['web_customer_id'], 'customer_id' => (string) $row['web_customer_id'], 'session' => null])) ?>" class="visitor-log__action">نشاط العميل</a>
                  <?php endif; ?>
                  <?php if (!empty($row['map_url'])): ?>
                    <a href="<?= h((string) $row['map_url']) ?>" class="visitor-log__action visitor-log__action--map" target="_blank" rel="noopener noreferrer">عرض على الخريطة</a>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>

      <script>
        window.setInterval(() => window.location.reload(), 60000);
      </script>
    <?php endif; ?>

  <?php elseif ($tab === 'log'): ?>
    <?php require __DIR__ . '/visitor-analytics-log.php'; ?>

  <?php else: ?>
    <div class="visitor-log__stats visitor-log__stats--insights">
      <div class="visitor-log__stat"><span>زيارات الصفحات</span><strong><?= number_format((int) ($summary['page_views'] ?? 0)) ?></strong></div>
      <div class="visitor-log__stat"><span>شاهدوا منتجات</span><strong><?= number_format((int) ($summary['product_views'] ?? 0)) ?></strong></div>
      <div class="visitor-log__stat"><span>أضافوا للسلة</span><strong><?= number_format((int) ($summary['cart_adds'] ?? 0)) ?></strong></div>
      <div class="visitor-log__stat"><span>جلسات</span><strong><?= number_format((int) ($summary['unique_sessions'] ?? 0)) ?></strong></div>
    </div>

    <div class="visitor-log__grid">
      <article class="visitor-log__panel">
        <header class="visitor-log__panel-head"><h2>الأصناف الأكثر اهتماماً</h2></header>
        <?php if ($topProducts === []): ?>
          <p class="visitor-log__empty">لا بيانات بعد.</p>
        <?php else: ?>
          <ul class="visitor-log__rank-list">
            <?php foreach ($topProducts as $row): ?>
              <li>
                <strong><?= h((string) ($row['product_name'] ?? 'صنف')) ?></strong>
                <span><?= number_format((int) ($row['total_interest'] ?? 0)) ?> تفاعل</span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>

      <article class="visitor-log__panel">
        <header class="visitor-log__panel-head"><h2>أكثر الصفحات زيارة</h2></header>
        <?php if ($topPages === []): ?>
          <p class="visitor-log__empty">لا بيانات بعد.</p>
        <?php else: ?>
          <ul class="visitor-log__rank-list">
            <?php foreach ($topPages as $row): ?>
              <li>
                <strong class="visitor-log__mono"><?= h((string) ($row['page_path'] ?? '')) ?></strong>
                <span><?= number_format((int) ($row['hits'] ?? 0)) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>
    </div>

    <?php if ($locationStats !== []): ?>
      <article class="visitor-log__panel visitor-log__panel--wide">
        <header class="visitor-log__panel-head"><h2>من أين يزورون؟</h2></header>
        <div class="visitor-log__locations">
          <?php foreach ($locationStats as $row): ?>
            <div class="visitor-log__location-row">
              <span><?= h((string) ($row['city'] ?? '—')) ?> · <?= h((string) ($row['country'] ?? '—')) ?></span>
              <span><?= number_format((int) ($row['hits'] ?? 0)) ?> زيارة</span>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="visitor-log__hint">أسماء المدن من IP أو GPS تقريبية — استخدم خريطة الزيارات أدناه للموقع الدقيق.</p>
      </article>
    <?php endif; ?>

    <?php if ($mapPoints !== []): ?>
      <article class="visitor-log__panel visitor-log__panel--wide">
        <header class="visitor-log__panel-head"><h2>خريطة الزيارات</h2></header>
        <div id="visitor-map" class="visitor-log__map" role="img" aria-label="خريطة مواقع الزوار"></div>
      </article>
      <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
      <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
      <script>
      (() => {
        const points = <?= $mapJson ?: '[]' ?>;
        const el = document.getElementById('visitor-map');
        if (!el || !points.length || typeof L === 'undefined') return;
        const map = L.map(el, { scrollWheelZoom: false }).setView([24.0, 45.0], 4);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '&copy; OpenStreetMap' }).addTo(map);
        const bounds = [];
        points.forEach((p) => {
          const lat = parseFloat(p.latitude);
          const lng = parseFloat(p.longitude);
          if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
          const isGps = p.is_gps === true || p.is_gps === 't' || p.is_gps === 1;
          const color = isGps ? '#059669' : '#D81921';
          const city = p.city || '';
          const country = p.country || '';
          const label = [city, country].filter(Boolean).join('، ') || 'موقع زيارة';
          const mapUrl = `https://www.google.com/maps?q=${encodeURIComponent(`${lat},${lng}`)}`;
          const marker = L.circleMarker([lat, lng], { radius: 10, color, fillColor: color, fillOpacity: 0.6, weight: 2 }).addTo(map);
          marker.bindPopup(`<strong>${label}</strong><br>${p.hits || 0} زيارة<br><a href="${mapUrl}" target="_blank" rel="noopener">عرض على Google Maps</a>`);
          bounds.push([lat, lng]);
        });
        if (bounds.length === 1) map.setView(bounds[0], 8);
        else if (bounds.length > 1) map.fitBounds(bounds, { padding: [40, 40] });
      })();
      </script>
    <?php endif; ?>
  <?php endif; ?>
</section>
