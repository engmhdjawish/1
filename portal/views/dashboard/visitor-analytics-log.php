<?php

declare(strict_types=1);

/** @var callable(array<string, mixed>): string $buildUrl */
/** @var list<array<string, mixed>> $accountGroupsPage */
/** @var array<string, mixed>|null $accountProfile */
/** @var array<string, mixed>|null $sessionDigest */
/** @var string $accountKey */
/** @var string $sessionId */
/** @var string $customerId */
/** @var string $searchQ */
/** @var string $visitorFilter */
/** @var string $eventCategory */
/** @var int $visitorsPage */
/** @var int $visitorsTotal */
/** @var int $days */
/** @var array<string, mixed>|null $filteredCustomer */

use Portal\Services\VisitorLogService;

$accountProfile = $accountProfile ?? null;
$accountGroupsPage = $accountGroupsPage ?? ($accountGroups ?? []);
$visitorsTotal = (int) ($visitorsTotal ?? count($accountGroupsPage));
$visitorFilter = trim((string) ($visitorFilter ?? 'all'));
$eventCategory = trim((string) ($eventCategory ?? 'all'));
$accountKey = trim((string) ($accountKey ?? ''));

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

$kindLabel = static function (array $row): string {
    return match ((string) ($row['identity_kind'] ?? '')) {
        'customer' => 'عميل',
        'guest_order' => 'معروف',
        default => 'زائر',
    };
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

$renderFunnelMini = static function (array $funnel): void {
    if ($funnel === []) {
        return;
    }
    echo '<div class="visitor-log__funnel-mini" aria-hidden="true">';
    foreach ($funnel as $step) {
        $pct = max(4, (int) ($step['pct'] ?? 0));
        $reached = !empty($step['reached']);
        echo '<span class="visitor-log__funnel-seg visitor-log__funnel-seg--' . h((string) ($step['key'] ?? ''));
        echo $reached ? ' is-on' : '';
        echo '" style="flex-grow:' . $pct . '" title="' . h((string) ($step['label'] ?? '')) . ': ' . (int) ($step['count'] ?? 0) . '"></span>';
    }
    echo '</div>';
};

$renderPagination = static function (
    int $page,
    int $totalPages,
    int $total,
    string $pageParam
) use ($buildUrl): void {
    if ($totalPages <= 1) {
        return;
    }
    echo '<nav class="visitor-log__pager" aria-label="ترقيم الصفحات">';
    if ($page > 1) {
        echo '<a href="' . h($buildUrl([$pageParam => $page - 1])) . '" class="visitor-log__pager-btn">السابق</a>';
    }
    echo '<span class="visitor-log__pager-info">' . $page . ' / ' . $totalPages . ' · ' . number_format($total) . '</span>';
    if ($page < $totalPages) {
        echo '<a href="' . h($buildUrl([$pageParam => $page + 1])) . '" class="visitor-log__pager-btn">التالي</a>';
    }
    echo '</nav>';
};

$filterChips = [
    'all' => 'الكل',
    'known' => 'معروفون',
    'customers' => 'عملاء',
    'cart' => 'سلة',
    'ordered' => 'طلبات',
];

$eventCategories = VisitorLogService::timelineCategoryLabels();
$visitorsPerPage = 20;
$visitorsTotalPages = max(1, (int) ceil($visitorsTotal / $visitorsPerPage));
?>
<?php if ($filteredCustomer !== null): ?>
  <div class="visitor-log__filter-banner">
    <div>
      <strong>نشاط العميل: <?= h((string) ($filteredCustomer['name_ar'] ?? '')) ?></strong>
      <?php if (trim((string) ($filteredCustomer['phone'] ?? '')) !== ''): ?>
        <span dir="ltr"><?= h((string) $filteredCustomer['phone']) ?></span>
      <?php endif; ?>
    </div>
    <div class="visitor-log__filter-banner-actions">
      <a href="/dashboard/customers.php?details=<?= h($customerId) ?>" class="visitor-log__action">ملف العميل</a>
      <a href="/dashboard/orders.php?web_customer_id=<?= h($customerId) ?>" class="visitor-log__action">طلباته</a>
      <a href="<?= h($buildUrl(['customer_id' => null, 'account' => null, 'session' => null, 'vp' => null, 'sp' => null, 'tp' => null])) ?>" class="visitor-log__action">إزالة التصفية</a>
    </div>
  </div>
<?php endif; ?>

<div class="visitor-log__toolbar visitor-log__toolbar--visitors">
  <form method="get" class="visitor-log__search">
    <input type="hidden" name="tab" value="log">
    <input type="hidden" name="days" value="<?= (int) $days ?>">
    <?php if ($accountKey !== ''): ?>
      <input type="hidden" name="account" value="<?= h($accountKey) ?>">
    <?php endif; ?>
    <?php if ($sessionId !== ''): ?>
      <input type="hidden" name="session" value="<?= h($sessionId) ?>">
    <?php endif; ?>
    <?php if ($visitorFilter !== 'all'): ?>
      <input type="hidden" name="filter" value="<?= h($visitorFilter) ?>">
    <?php endif; ?>
    <?php if ($eventCategory !== 'all'): ?>
      <input type="hidden" name="ecat" value="<?= h($eventCategory) ?>">
    <?php endif; ?>
    <span class="material-symbols-outlined visitor-log__search-icon" aria-hidden="true">search</span>
    <input type="search" name="q" value="<?= h($searchQ) ?>" placeholder="ابحث بالاسم، الهاتف، أو IP..." class="visitor-log__search-input">
    <?php if ($searchQ !== ''): ?>
      <a href="<?= h($buildUrl(['q' => null, 'vp' => null])) ?>" class="visitor-log__search-clear" aria-label="مسح البحث">×</a>
    <?php endif; ?>
  </form>

  <div class="visitor-log__chips" role="group" aria-label="تصفية الزوار">
    <?php foreach ($filterChips as $key => $label): ?>
      <a href="<?= h($buildUrl(['filter' => $key === 'all' ? null : $key, 'account' => $accountKey !== '' ? $accountKey : null, 'session' => $sessionId !== '' ? $sessionId : null, 'vp' => null, 'sp' => null, 'tp' => null])) ?>"
         class="visitor-log__chip<?= $visitorFilter === $key ? ' is-active' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="visitor-log__crm<?= $accountKey !== '' ? ' visitor-log__crm--profile' : '' ?>">
  <aside class="visitor-log__crm-list">
    <header class="visitor-log__crm-list-head">
      <h2>الزوار</h2>
      <span><?= number_format($visitorsTotal) ?></span>
    </header>

    <?php if ($accountGroupsPage === []): ?>
      <p class="visitor-log__empty">لا زوار في هذه الفترة<?= $visitorFilter !== 'all' || $searchQ !== '' ? ' — جرّب توسيع البحث أو إزالة التصفية' : '' ?>.</p>
    <?php else: ?>
      <ul class="visitor-log__visitor-table">
        <?php foreach ($accountGroupsPage as $group): ?>
          <?php
          $key = (string) ($group['account_key'] ?? '');
          $isActive = $accountKey !== '' && $accountKey === $key;
          $funnel = is_array($group['funnel'] ?? null) ? $group['funnel'] : [];
          ?>
          <li class="<?= $isActive ? 'is-active' : '' ?>">
            <a href="<?= h($buildUrl(['tab' => 'log', 'account' => $key, 'session' => null, 'sp' => null, 'tp' => null, 'ecat' => null])) ?>" class="visitor-log__visitor-row">
              <span class="visitor-log__visitor-avatar visitor-log__pill <?= h($identityPill($group)) ?>"><?= h(mb_substr($renderIdentityName($group), 0, 1)) ?></span>
              <span class="visitor-log__visitor-main">
                <strong><?= h($renderIdentityName($group)) ?></strong>
                <span class="visitor-log__visitor-meta">
                  <span class="visitor-log__pill <?= h($identityPill($group)) ?>"><?= h($kindLabel($group)) ?></span>
                  <?php if (trim((string) ($group['identity_phone'] ?? '')) !== ''): ?>
                    <span dir="ltr"><?= h((string) $group['identity_phone']) ?></span>
                  <?php endif; ?>
                  <?php if ((int) ($group['session_count'] ?? 0) > 1): ?>
                    <span><?= (int) $group['session_count'] ?> جلسات</span>
                  <?php endif; ?>
                </span>
                <?php $renderFunnelMini($funnel); ?>
              </span>
              <span class="visitor-log__visitor-side">
                <time><?= h((string) ($group['last_seen_relative'] ?? $group['last_seen_fmt'] ?? '')) ?></time>
                <?php if ((int) ($group['orders'] ?? 0) > 0): ?>
                  <span class="visitor-log__badge visitor-log__badge--order"><?= (int) $group['orders'] ?> طلب</span>
                <?php elseif ((int) ($group['cart_adds'] ?? 0) > 0): ?>
                  <span class="visitor-log__badge visitor-log__badge--cart">+<?= (int) $group['cart_adds'] ?></span>
                <?php endif; ?>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php $renderPagination((int) ($visitorsPage ?? 1), $visitorsTotalPages, $visitorsTotal, $buildUrl, 'vp'); ?>
    <?php endif; ?>
  </aside>

  <main class="visitor-log__crm-detail">
    <?php if ($accountKey !== '' && $accountProfile !== null): ?>
      <a href="<?= h($buildUrl(['account' => null, 'session' => null, 'sp' => null, 'tp' => null, 'ecat' => null])) ?>" class="visitor-log__mobile-back">
        <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
        <span>عودة للزوار</span>
      </a>
    <?php endif; ?>

    <?php if ($accountKey === '' || $accountProfile === null): ?>
      <div class="visitor-log__detail-empty visitor-log__detail-empty--pro">
        <span class="material-symbols-outlined" aria-hidden="true">groups</span>
        <h3>اختر زائراً</h3>
        <p>اعرض مسار التحويل الكامل، الجلسات، والأحداث المهمة — بدون ضجيج الصفحات المتكررة.</p>
      </div>
    <?php else: ?>
      <?php
      $stats = is_array($accountProfile['stats'] ?? null) ? $accountProfile['stats'] : [];
      $funnel = is_array($accountProfile['funnel'] ?? null) ? $accountProfile['funnel'] : [];
      $timeline = is_array($accountProfile['timeline_page'] ?? null) ? $accountProfile['timeline_page'] : [];
      $timelineMeta = is_array($accountProfile['timeline_meta'] ?? null) ? $accountProfile['timeline_meta'] : [];
      $profileSessions = is_array($accountProfile['sessions_page'] ?? null) ? $accountProfile['sessions_page'] : [];
      $sessionsMeta = is_array($accountProfile['sessions_meta'] ?? null) ? $accountProfile['sessions_meta'] : [];
      $digest = is_array($accountProfile['digest'] ?? null) ? $accountProfile['digest'] : [];
      $digestStats = is_array($digest['stats'] ?? null) ? $digest['stats'] : [];
      $location = is_array($accountProfile['location'] ?? null) ? $accountProfile['location'] : [];
      $profileCustomerId = trim((string) ($accountProfile['web_customer_id'] ?? ''));

      $funnelCategoryMap = [
          'visit' => 'browse',
          'product' => 'product',
          'cart' => 'cart',
          'order' => 'order',
      ];
      ?>

      <header class="visitor-log__profile-head">
        <div class="visitor-log__profile-identity">
          <span class="visitor-log__profile-avatar visitor-log__pill <?= h($identityPill($accountProfile)) ?>"><?= h(mb_substr((string) ($accountProfile['display_name'] ?? 'ز'), 0, 1)) ?></span>
          <div>
            <h2><?= h((string) ($accountProfile['display_name'] ?? 'زائر')) ?></h2>
            <p class="visitor-log__profile-sub">
              <span class="visitor-log__pill <?= h($identityPill($accountProfile)) ?>"><?= h($kindLabel($accountProfile)) ?></span>
              <?php if (trim((string) ($accountProfile['identity_phone'] ?? '')) !== ''): ?>
                <span dir="ltr"><?= h((string) $accountProfile['identity_phone']) ?></span>
              <?php endif; ?>
              <span>آخر نشاط <?= h((string) ($stats['last_seen_relative'] ?? $stats['last_seen_fmt'] ?? '')) ?></span>
            </p>
          </div>
        </div>
        <div class="visitor-log__profile-actions">
          <?php if ($profileCustomerId !== ''): ?>
            <a href="/dashboard/customers.php?details=<?= h($profileCustomerId) ?>" class="visitor-log__action">الملف</a>
            <a href="/dashboard/orders.php?web_customer_id=<?= h($profileCustomerId) ?>" class="visitor-log__action">الطلبات</a>
          <?php endif; ?>
          <a href="<?= h($buildUrl(['account' => null, 'session' => null, 'sp' => null, 'tp' => null, 'ecat' => null])) ?>" class="visitor-log__action visitor-log__action--close">إغلاق</a>
        </div>
      </header>

      <?php
      $locationLabel = trim((string) ($location['location_label'] ?? ''));
      $mapUrl = trim((string) ($location['map_url'] ?? ''));
      if ($locationLabel !== '' && $locationLabel !== '—'):
      ?>
        <div class="visitor-log__digest-location visitor-log__digest-location--profile">
          <span class="material-symbols-outlined" aria-hidden="true">location_on</span>
          <span><?= h($locationLabel) ?></span>
          <?php if (trim((string) ($location['location_source_label'] ?? '')) !== ''): ?>
            <span class="visitor-log__digest-tag"><?= h((string) $location['location_source_label']) ?></span>
          <?php endif; ?>
          <?php if ($mapUrl !== ''): ?>
            <a href="<?= h($mapUrl) ?>" class="visitor-log__map-link" target="_blank" rel="noopener noreferrer">عرض على الخريطة</a>
          <?php endif; ?>
          <?php if (trim((string) ($location['visitor_ip'] ?? '')) !== ''): ?>
            <span class="visitor-log__digest-ip-inline" dir="ltr">IP: <?= h((string) $location['visitor_ip']) ?></span>
          <?php endif; ?>
        </div>
      <?php elseif ($mapUrl !== ''): ?>
        <div class="visitor-log__digest-location visitor-log__digest-location--profile">
          <span class="material-symbols-outlined" aria-hidden="true">location_on</span>
          <a href="<?= h($mapUrl) ?>" class="visitor-log__map-link" target="_blank" rel="noopener noreferrer">عرض الموقع على الخريطة</a>
          <?php if (trim((string) ($location['visitor_ip'] ?? '')) !== ''): ?>
            <span class="visitor-log__digest-ip-inline" dir="ltr">IP: <?= h((string) $location['visitor_ip']) ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($funnel !== []): ?>
        <section class="visitor-log__funnel" aria-label="مسار التحويل">
          <div class="visitor-log__funnel-track">
            <?php foreach ($funnel as $i => $step): ?>
              <?php
              $stepKey = (string) ($step['key'] ?? '');
              $ecatForStep = $funnelCategoryMap[$stepKey] ?? null;
              ?>
              <?php if ($i > 0): ?>
                <span class="visitor-log__funnel-arrow material-symbols-outlined" aria-hidden="true">chevron_left</span>
              <?php endif; ?>
              <?php if ($ecatForStep !== null): ?>
                <a href="<?= h($buildUrl(['ecat' => $ecatForStep, 'tp' => null])) ?>" class="visitor-log__funnel-step visitor-log__funnel-step--link<?= !empty($step['reached']) ? ' is-reached' : '' ?><?= $eventCategory === $ecatForStep ? ' is-active' : '' ?>">
                  <strong><?= number_format((int) ($step['count'] ?? 0)) ?></strong>
                  <span><?= h((string) ($step['label'] ?? '')) ?></span>
                  <em><?= (int) ($step['pct'] ?? 0) ?>%</em>
                </a>
              <?php else: ?>
                <div class="visitor-log__funnel-step<?= !empty($step['reached']) ? ' is-reached' : '' ?>">
                  <strong><?= number_format((int) ($step['count'] ?? 0)) ?></strong>
                  <span><?= h((string) ($step['label'] ?? '')) ?></span>
                  <em><?= (int) ($step['pct'] ?? 0) ?>%</em>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <div class="visitor-log__profile-stats">
        <div><span>جلسات</span><strong><?= number_format((int) ($stats['session_count'] ?? 0)) ?></strong></div>
        <div><span>أحداث</span><strong><?= number_format((int) ($stats['events'] ?? 0)) ?></strong></div>
        <div><span>صفحات</span><strong><?= number_format((int) ($stats['page_views'] ?? 0)) ?></strong></div>
        <div><span>منتجات</span><strong><?= number_format((int) ($stats['product_views'] ?? 0)) ?></strong></div>
        <div><span>+سلة</span><strong><?= number_format((int) ($stats['cart_adds'] ?? 0)) ?></strong></div>
        <div><span>طلبات</span><strong><?= number_format((int) ($stats['orders'] ?? 0)) ?></strong></div>
      </div>

      <?php if ($sessionId !== '' && $sessionDigest !== null && ($sessionDigest['raw_count'] ?? 0) > 0): ?>
        <?php $sessStats = is_array($sessionDigest['stats'] ?? null) ? $sessionDigest['stats'] : []; ?>
        <section class="visitor-log__session-focus">
          <header>
            <h3>جلسة محددة</h3>
            <a href="<?= h($buildUrl(['session' => null])) ?>" class="visitor-log__inline-link">عرض كل الجلسات</a>
          </header>
          <p class="visitor-log__digest-period">
            <?= h((string) ($sessStats['started_fmt'] ?? '')) ?>
            <?php if (!empty($sessStats['ended_fmt']) && ($sessStats['ended_fmt'] ?? '') !== ($sessStats['started_fmt'] ?? '')): ?>
              → <?= h((string) $sessStats['ended_fmt']) ?>
            <?php endif; ?>
            · <?= h((string) ($sessStats['duration_label'] ?? '—')) ?>
          </p>
          <?php if (trim((string) ($sessStats['location_label'] ?? '')) !== '' && ($sessStats['location_label'] ?? '') !== '—'): ?>
            <div class="visitor-log__digest-location visitor-log__digest-location--compact">
              <span class="material-symbols-outlined" aria-hidden="true">location_on</span>
              <span><?= h((string) $sessStats['location_label']) ?></span>
              <?php if (!empty($sessStats['map_url'])): ?>
                <a href="<?= h((string) $sessStats['map_url']) ?>" class="visitor-log__map-link" target="_blank" rel="noopener noreferrer">خريطة</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="visitor-log__digest-stats visitor-log__digest-stats--inline">
            <div class="visitor-log__digest-stat"><span>صفحات</span><strong><?= (int) ($sessStats['page_views'] ?? 0) ?></strong></div>
            <div class="visitor-log__digest-stat"><span>منتجات</span><strong><?= (int) ($sessStats['product_views'] ?? 0) ?></strong></div>
            <div class="visitor-log__digest-stat"><span>+سلة</span><strong><?= (int) ($sessStats['cart_adds'] ?? 0) ?></strong></div>
            <div class="visitor-log__digest-stat"><span>طلبات</span><strong><?= (int) ($sessStats['orders'] ?? 0) ?></strong></div>
          </div>
        </section>
      <?php elseif ((int) ($sessionsMeta['total'] ?? 0) > 0): ?>
        <section class="visitor-log__sessions-strip">
          <header class="visitor-log__sessions-strip-head">
            <h3>الجلسات</h3>
            <span><?= number_format((int) ($sessionsMeta['total'] ?? 0)) ?> جلسة</span>
          </header>
          <div class="visitor-log__sessions-scroll">
            <?php foreach ($profileSessions as $sess): ?>
              <?php
              $sid = (string) ($sess['session_id'] ?? '');
              $isSessActive = $sessionId !== '' && $sessionId === $sid;
              ?>
              <a href="<?= h($buildUrl(['session' => $sid, 'tp' => null])) ?>" class="visitor-log__session-card<?= $isSessActive ? ' is-active' : '' ?>">
                <time><?= h((string) ($sess['last_seen_relative'] ?? $sess['last_seen_fmt'] ?? '')) ?></time>
                <span><?= number_format((int) ($sess['events'] ?? 0)) ?> حدث</span>
                <?php if ((int) ($sess['orders'] ?? 0) > 0): ?>
                  <span class="visitor-log__badge visitor-log__badge--order"><?= (int) $sess['orders'] ?> طلب</span>
                <?php endif; ?>
                <?php
                $sessCity = trim((string) ($sess['city_ar'] ?? ''));
                $sessCountry = trim((string) ($sess['country_ar'] ?? ''));
                if ($sessCity !== '' || $sessCountry !== ''):
                ?>
                  <span class="visitor-log__session-loc"><?= h($sessCity !== '' && $sessCountry !== '' ? $sessCity . '، ' . $sessCountry : ($sessCity ?: $sessCountry)) ?></span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
          <?php $renderPagination((int) ($sessionsMeta['page'] ?? 1), (int) ($sessionsMeta['total_pages'] ?? 1), (int) ($sessionsMeta['total'] ?? 0), $buildUrl, 'sp'); ?>
        </section>
      <?php endif; ?>

      <section class="visitor-log__timeline-pro">
        <header class="visitor-log__timeline-head">
          <h3>الخط الزمني</h3>
          <?php if ((int) ($timelineMeta['total'] ?? 0) > 0): ?>
            <span><?= number_format((int) ($timelineMeta['total'] ?? 0)) ?> حدث</span>
          <?php endif; ?>
        </header>

        <div class="visitor-log__chips visitor-log__chips--timeline" role="group" aria-label="تصفية الأحداث">
          <?php foreach ($eventCategories as $catKey => $catLabel): ?>
            <a href="<?= h($buildUrl(['ecat' => $catKey === 'all' ? null : $catKey, 'tp' => null])) ?>"
               class="visitor-log__chip<?= $eventCategory === $catKey ? ' is-active' : '' ?>"><?= h($catLabel) ?></a>
          <?php endforeach; ?>
        </div>

        <?php if ($timeline === []): ?>
          <p class="visitor-log__empty visitor-log__empty--inline">لا أحداث<?= $eventCategory !== 'all' ? ' في هذا التصنيف' : '' ?> في الفترة المحددة.</p>
        <?php else: ?>
          <?php foreach ($timeline as $dayGroup): ?>
            <div class="visitor-log__timeline-day">
              <h4><?= h((string) ($dayGroup['day_label'] ?? '')) ?></h4>
              <ol>
                <?php foreach ($dayGroup['items'] ?? [] as $item): ?>
                  <li class="visitor-log__timeline-item visitor-log__timeline-item--<?= h((string) ($item['kind'] ?? 'moment')) ?>">
                    <span class="material-symbols-outlined" aria-hidden="true"><?= h((string) ($item['icon'] ?? 'trip_origin')) ?></span>
                    <div>
                      <strong><?= h((string) ($item['action_label_ar'] ?? '')) ?></strong>
                      <?php if (trim((string) ($item['label_ar'] ?? '')) !== '' && trim((string) ($item['action_label_ar'] ?? '')) !== trim((string) ($item['label_ar'] ?? ''))): ?>
                        <p><?= h((string) $item['label_ar']) ?></p>
                      <?php elseif (trim((string) ($item['label_ar'] ?? '')) !== ''): ?>
                        <p><?= h((string) $item['label_ar']) ?></p>
                      <?php endif; ?>
                    </div>
                    <time><?= h((string) ($item['created_at_relative'] ?? $item['created_at_fmt'] ?? '')) ?></time>
                  </li>
                <?php endforeach; ?>
              </ol>
            </div>
          <?php endforeach; ?>
          <?php $renderPagination((int) ($timelineMeta['page'] ?? 1), (int) ($timelineMeta['total_pages'] ?? 1), (int) ($timelineMeta['total'] ?? 0), $buildUrl, 'tp'); ?>
        <?php endif; ?>
      </section>

      <?php
      $topProducts = is_array($digestStats['top_products'] ?? null) ? $digestStats['top_products'] : [];
      if ($topProducts !== []):
      ?>
        <section class="visitor-log__digest-block">
          <h3>أكثر المنتجات اهتماماً</h3>
          <ul class="visitor-log__digest-list">
            <?php foreach (array_slice($topProducts, 0, 8) as $product): ?>
              <li><?= h((string) ($product['product_name'] ?? 'صنف')) ?></li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>
