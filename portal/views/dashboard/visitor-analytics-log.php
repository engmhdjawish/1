<?php

declare(strict_types=1);

/** @var callable(array<string, mixed>): string $buildUrl */
/** @var list<array<string, mixed>> $accountGroups */
/** @var array<string, mixed>|null $accountProfile */
/** @var array<string, mixed>|null $sessionDigest */
/** @var string $accountKey */
/** @var string $sessionId */
/** @var string $customerId */
/** @var string $searchQ */
/** @var string $visitorFilter */
/** @var int $days */
/** @var array<string, mixed>|null $filteredCustomer */

$accountProfile = $accountProfile ?? null;
$visitorFilter = trim((string) ($visitorFilter ?? 'all'));
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

$filterChips = [
    'all' => 'الكل',
    'known' => 'معروفون',
    'customers' => 'عملاء',
    'cart' => 'سلة',
    'ordered' => 'طلبات',
];
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
      <a href="<?= h($buildUrl(['customer_id' => null, 'account' => null, 'session' => null])) ?>" class="visitor-log__action">إزالة التصفية</a>
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
    <span class="material-symbols-outlined visitor-log__search-icon" aria-hidden="true">search</span>
    <input type="search" name="q" value="<?= h($searchQ) ?>" placeholder="ابحث بالاسم، الهاتف، أو IP..." class="visitor-log__search-input">
    <?php if ($searchQ !== ''): ?>
      <a href="<?= h($buildUrl(['q' => null])) ?>" class="visitor-log__search-clear" aria-label="مسح البحث">×</a>
    <?php endif; ?>
  </form>

  <div class="visitor-log__chips" role="group" aria-label="تصفية الزوار">
    <?php foreach ($filterChips as $key => $label): ?>
      <a href="<?= h($buildUrl(['filter' => $key === 'all' ? null : $key, 'account' => $accountKey !== '' ? $accountKey : null, 'session' => $sessionId !== '' ? $sessionId : null])) ?>"
         class="visitor-log__chip<?= $visitorFilter === $key ? ' is-active' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="visitor-log__crm">
  <aside class="visitor-log__crm-list">
    <header class="visitor-log__crm-list-head">
      <h2>الزوار</h2>
      <span><?= count($accountGroups) ?></span>
    </header>

    <?php if ($accountGroups === []): ?>
      <p class="visitor-log__empty">لا زوار في هذه الفترة<?= $visitorFilter !== 'all' || $searchQ !== '' ? ' — جرّب توسيع البحث أو إزالة التصفية' : '' ?>.</p>
    <?php else: ?>
      <ul class="visitor-log__visitor-table">
        <?php foreach ($accountGroups as $group): ?>
          <?php
          $key = (string) ($group['account_key'] ?? '');
          $isActive = $accountKey !== '' && $accountKey === $key;
          $funnel = is_array($group['funnel'] ?? null) ? $group['funnel'] : [];
          ?>
          <li class="<?= $isActive ? 'is-active' : '' ?>">
            <a href="<?= h($buildUrl(['tab' => 'log', 'account' => $key, 'session' => null])) ?>" class="visitor-log__visitor-row">
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
    <?php endif; ?>
  </aside>

  <main class="visitor-log__crm-detail">
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
      $timeline = is_array($accountProfile['timeline'] ?? null) ? $accountProfile['timeline'] : [];
      $profileSessions = is_array($accountProfile['sessions'] ?? null) ? $accountProfile['sessions'] : [];
      $digest = is_array($accountProfile['digest'] ?? null) ? $accountProfile['digest'] : [];
      $digestStats = is_array($digest['stats'] ?? null) ? $digest['stats'] : [];
      $profileCustomerId = trim((string) ($accountProfile['web_customer_id'] ?? ''));
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
          <a href="<?= h($buildUrl(['account' => null, 'session' => null])) ?>" class="visitor-log__action">إغلاق</a>
        </div>
      </header>

      <?php if ($funnel !== []): ?>
        <section class="visitor-log__funnel" aria-label="مسار التحويل">
          <div class="visitor-log__funnel-track">
            <?php foreach ($funnel as $i => $step): ?>
              <?php if ($i > 0): ?>
                <span class="visitor-log__funnel-arrow material-symbols-outlined" aria-hidden="true">chevron_left</span>
              <?php endif; ?>
              <div class="visitor-log__funnel-step<?= !empty($step['reached']) ? ' is-reached' : '' ?>">
                <strong><?= number_format((int) ($step['count'] ?? 0)) ?></strong>
                <span><?= h((string) ($step['label'] ?? '')) ?></span>
                <em><?= (int) ($step['pct'] ?? 0) ?>%</em>
              </div>
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
          <div class="visitor-log__digest-stats visitor-log__digest-stats--inline">
            <div class="visitor-log__digest-stat"><span>صفحات</span><strong><?= (int) ($sessStats['page_views'] ?? 0) ?></strong></div>
            <div class="visitor-log__digest-stat"><span>منتجات</span><strong><?= (int) ($sessStats['product_views'] ?? 0) ?></strong></div>
            <div class="visitor-log__digest-stat"><span>+سلة</span><strong><?= (int) ($sessStats['cart_adds'] ?? 0) ?></strong></div>
            <div class="visitor-log__digest-stat"><span>طلبات</span><strong><?= (int) ($sessStats['orders'] ?? 0) ?></strong></div>
          </div>
        </section>
      <?php elseif (count($profileSessions) > 1): ?>
        <section class="visitor-log__sessions-strip">
          <h3>الجلسات</h3>
          <div class="visitor-log__sessions-scroll">
            <?php foreach ($profileSessions as $sess): ?>
              <?php
              $sid = (string) ($sess['session_id'] ?? '');
              $isSessActive = $sessionId !== '' && $sessionId === $sid;
              ?>
              <a href="<?= h($buildUrl(['session' => $sid])) ?>" class="visitor-log__session-card<?= $isSessActive ? ' is-active' : '' ?>">
                <time><?= h((string) ($sess['last_seen_relative'] ?? $sess['last_seen_fmt'] ?? '')) ?></time>
                <span><?= number_format((int) ($sess['events'] ?? 0)) ?> حدث</span>
                <?php if ((int) ($sess['orders'] ?? 0) > 0): ?>
                  <span class="visitor-log__badge visitor-log__badge--order"><?= (int) $sess['orders'] ?> طلب</span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($timeline !== []): ?>
        <section class="visitor-log__timeline-pro">
          <h3>الخط الزمني</h3>
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
        </section>
      <?php else: ?>
        <p class="visitor-log__empty">لا أحداث مسجّلة لهذا الزائر في الفترة المحددة.</p>
      <?php endif; ?>

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
