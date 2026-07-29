<?php

declare(strict_types=1);

/** @var callable(array<string, mixed>): string $buildUrl */
/** @var array<string, int> $summary */
/** @var list<array<string, mixed>> $recent */
/** @var list<array<string, mixed>> $topProducts */
/** @var list<array<string, mixed>> $topPages */
/** @var list<array<string, mixed>> $sessions */
/** @var list<array<string, mixed>> $sessionEvents */
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
/** @var string|null $flash */
/** @var string $flashType */

$tab = in_array($tab ?? '', ['now', 'log', 'insights'], true) ? $tab : 'now';
$days = (int) ($days ?? 7);
$schemaReady = (bool) ($schemaReady ?? false);
$presenceReady = (bool) ($presenceReady ?? false);
$sessionsReady = (bool) ($sessionsReady ?? false);
$canManageSessions = (bool) ($canManageSessions ?? false);
$sessionId = trim((string) ($sessionId ?? ''));
$mapJson = json_encode($mapPoints ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

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
      <p class="visitor-log__subtitle">من على الموقع الآن، ماذا فعلوا، ومن أين يزورون — في مكان واحد.</p>
    </div>
    <?php if ($tab !== 'now'): ?>
      <form method="get" class="visitor-log__period">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <?php if ($sessionId !== ''): ?>
          <input type="hidden" name="session" value="<?= h($sessionId) ?>">
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
    <a href="<?= h($buildUrl(['tab' => 'log', 'session' => $sessionId !== '' ? $sessionId : null])) ?>" class="visitor-log__tab<?= $tab === 'log' ? ' is-active' : '' ?>">
      <span class="material-symbols-outlined" aria-hidden="true">list_alt</span>
      السجل
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
                    <?php if ($canManageSessions): ?>
                      <div class="visitor-log__live-actions">
                        <form method="post" onsubmit="return confirm('إنهاء هذه الجلسة؟');">
                          <input type="hidden" name="action" value="revoke_one">
                          <input type="hidden" name="kind" value="<?= h($section['kind']) ?>">
                          <input type="hidden" name="session_id" value="<?= h((string) ($row['session_id'] ?? '')) ?>">
                          <button type="submit" class="visitor-log__action visitor-log__action--danger">إنهاء</button>
                        </form>
                      </div>
                    <?php endif; ?>
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
                  <strong><?= h($locationLabel((string) ($row['city_ar'] ?? ''), (string) ($row['country_ar'] ?? ''))) ?></strong>
                  <span><?= h($formatSeen((string) ($row['last_seen_at'] ?? ''))) ?></span>
                </div>
                <div class="visitor-log__live-meta" dir="ltr"><?= h((string) ($row['visitor_ip'] ?? '—')) ?></div>
                <?php if ($guestSession !== ''): ?>
                  <a href="<?= h($buildUrl(['tab' => 'log', 'session' => $guestSession])) ?>" class="visitor-log__action">عرض السجل</a>
                <?php endif; ?>
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
    <div class="visitor-log__split">
      <article class="visitor-log__panel">
        <header class="visitor-log__panel-head">
          <h2>جلسات الزيارة</h2>
          <span><?= count($sessions) ?> جلسة</span>
        </header>
        <?php if ($sessions === []): ?>
          <p class="visitor-log__empty">لا توجد جلسات في هذه الفترة.</p>
        <?php else: ?>
          <ul class="visitor-log__session-list">
            <?php foreach ($sessions as $row): ?>
              <?php
              $sid = (string) ($row['session_id'] ?? '');
              $isActive = $sessionId !== '' && $sessionId === $sid;
              ?>
              <li class="<?= $isActive ? 'is-active' : '' ?>">
                <a href="<?= h($buildUrl(['tab' => 'log', 'session' => $sid])) ?>" class="visitor-log__session-link">
                  <div class="visitor-log__session-top">
                    <span class="visitor-log__pill <?= !empty($row['web_customer_id']) ? 'is-customer' : '' ?>">
                      <?= !empty($row['web_customer_id']) ? 'عميل' : 'زائر' ?>
                    </span>
                    <time><?= h((string) ($row['last_seen_fmt'] ?? '')) ?></time>
                  </div>
                  <div class="visitor-log__session-stats">
                    <span><?= number_format((int) ($row['events'] ?? 0)) ?> حدث</span>
                    <span><?= number_format((int) ($row['product_views'] ?? 0)) ?> منتج</span>
                    <span><?= number_format((int) ($row['cart_adds'] ?? 0)) ?> سلة</span>
                  </div>
                  <div class="visitor-log__session-location"><?= h($locationLabel((string) ($row['city_ar'] ?? ''), (string) ($row['country_ar'] ?? ''))) ?></div>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>

      <article class="visitor-log__panel visitor-log__panel--detail">
        <?php if ($sessionId === ''): ?>
          <div class="visitor-log__detail-empty">
            <span class="material-symbols-outlined" aria-hidden="true">touch_app</span>
            <p>اختر جلسة من القائمة لعرض مسار الزائر خطوة بخطوة.</p>
          </div>
        <?php elseif ($sessionEvents === []): ?>
          <div class="visitor-log__detail-empty">
            <p>لا أحداث مسجّلة لهذه الجلسة.</p>
            <a href="<?= h($buildUrl(['tab' => 'log', 'session' => null])) ?>" class="visitor-log__action">عودة</a>
          </div>
        <?php else: ?>
          <header class="visitor-log__panel-head">
            <div>
              <h2>مسار الجلسة</h2>
              <p class="visitor-log__mono"><?= h($sessionId) ?></p>
            </div>
            <a href="<?= h($buildUrl(['tab' => 'log', 'session' => null])) ?>" class="visitor-log__action">إغلاق</a>
          </header>
          <ol class="visitor-log__timeline">
            <?php foreach ($sessionEvents as $row): ?>
              <li>
                <div class="visitor-log__timeline-time"><?= h((string) ($row['created_at_fmt'] ?? '')) ?></div>
                <div class="visitor-log__timeline-body">
                  <span class="visitor-log__pill"><?= h((string) ($row['action_label_ar'] ?? '')) ?></span>
                  <p><?= h((string) ($row['label_ar'] ?? '')) ?></p>
                </div>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </article>
    </div>

    <?php if ($recent !== []): ?>
      <article class="visitor-log__panel visitor-log__panel--wide visitor-log__panel--compact">
        <header class="visitor-log__panel-head">
          <h2>آخر النشاط</h2>
          <span><?= count($recent) ?> حدث</span>
        </header>
        <div class="visitor-log__feed">
          <?php foreach ($recent as $row): ?>
            <div class="visitor-log__feed-row">
              <time><?= h((string) ($row['created_at_fmt'] ?? '')) ?></time>
              <span class="visitor-log__pill"><?= h((string) ($row['action_label_ar'] ?? '')) ?></span>
              <span class="visitor-log__feed-label"><?= h((string) ($row['label_ar'] ?? '')) ?></span>
              <span class="visitor-log__feed-meta"><?= h($locationLabel((string) ($row['city_ar'] ?? ''), (string) ($row['country_ar'] ?? ''))) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </article>
    <?php endif; ?>

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
          L.circleMarker([lat, lng], { radius: 10, color, fillColor: color, fillOpacity: 0.6, weight: 2 }).addTo(map);
          bounds.push([lat, lng]);
        });
        if (bounds.length === 1) map.setView(bounds[0], 8);
        else if (bounds.length > 1) map.fitBounds(bounds, { padding: [40, 40] });
      })();
      </script>
    <?php endif; ?>
  <?php endif; ?>
</section>
