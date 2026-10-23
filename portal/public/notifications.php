<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\NotificationService;

require dirname(__DIR__) . '/views/helpers.php';

if (WebSession::check() && !\Portal\Auth\CustomerSession::isLoggedIn()) {
    header('Location: /dashboard/notifications.php');
    exit;
}

$perPage = 20;
$categories = NotificationService::CATEGORY_LABELS;
$category = (string) ($_GET['c'] ?? '');
$category = isset($categories[$category]) ? $category : '';
$page = max(1, (int) ($_GET['page'] ?? 1));

$openId = trim((string) ($_GET['open'] ?? ''));
if ($openId !== '') {
    $target = '/notifications.php';
    try {
        NotificationService::markRead($openId);
        foreach (NotificationService::listForReader(100) as $row) {
            if ((string) ($row['id'] ?? '') === $openId) {
                $target = NotificationService::normalizeLinkUrl((string) ($row['link_url'] ?? '')) ?? $target;
                break;
            }
        }
    } catch (Throwable) {
    }
    header('Location: ' . $target, true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'read_all'
    && strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin')) !== 'cross-site'
) {
    try {
        NotificationService::markAllRead();
    } catch (Throwable) {
    }
    header('Location: /notifications.php' . ($category !== '' ? '?c=' . rawurlencode($category) : ''), true, 303);
    exit;
}

try {
    $rows = NotificationService::listForReader($perPage + 1, ($page - 1) * $perPage, $category);
    $unread = NotificationService::unreadCount();
} catch (Throwable $e) {
    error_log('notifications.php: ' . $e->getMessage());
    $rows = [];
    $unread = 0;
}
$hasMore = count($rows) > $perPage;
$rows = array_slice($rows, 0, $perPage);

$pageUrl = static function (string $c, int $p): string {
    $query = array_filter(['c' => $c, 'page' => $p > 1 ? (string) $p : ''], static fn (string $v): bool => $v !== '');

    return '/notifications.php' . ($query !== [] ? '?' . http_build_query($query) : '');
};
$formatTime = static function (string $value): string {
    try {
        $date = new DateTimeImmutable($value);

        return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y/m/d · H:i');
    } catch (Throwable) {
        return $value;
    }
};

ob_start();
?>
<section class="notif-page">
  <div class="notif-page__head">
    <h1>الإشعارات</h1>
    <?php if ($unread > 0): ?>
      <form method="post">
        <input type="hidden" name="action" value="read_all">
        <button type="submit" class="notif-page__mark-all">تعليم الكل كمقروء (<?= (int) $unread ?>)</button>
      </form>
    <?php endif; ?>
  </div>

  <nav class="notif-page__tabs" aria-label="أنواع الإشعارات">
    <a href="<?= h($pageUrl('', 1)) ?>" class="<?= $category === '' ? 'is-active' : '' ?>">الكل</a>
    <?php foreach ($categories as $key => $label): ?>
      <a href="<?= h($pageUrl($key, 1)) ?>" class="<?= $category === $key ? 'is-active' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($rows === []): ?>
    <p class="notif-page__empty">لا توجد إشعارات<?= $category !== '' ? ' في «' . h($categories[$category]) . '»' : '' ?>.</p>
  <?php else: ?>
    <div class="notif-page__list">
      <?php foreach ($rows as $row): ?>
        <a class="notif-page__item<?= empty($row['is_read']) ? ' is-unread' : '' ?>" href="/notifications.php?open=<?= h(rawurlencode((string) $row['id'])) ?>">
          <span class="notif-page__icon"><span class="material-symbols-outlined" aria-hidden="true"><?= h((string) $row['icon']) ?></span></span>
          <span class="notif-page__body">
            <span class="notif-page__title"><?= h((string) ($row['title_ar'] ?? '')) ?></span>
            <span class="notif-page__text"><?= h((string) ($row['body_ar'] ?? '')) ?></span>
            <span class="notif-page__meta"><?= h($categories[$row['category']] ?? '') ?> · <?= h($formatTime((string) ($row['created_at'] ?? ''))) ?></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($page > 1 || $hasMore): ?>
    <nav class="notif-page__pager" aria-label="الصفحات">
      <?php if ($page > 1): ?><a href="<?= h($pageUrl($category, $page - 1)) ?>">الأحدث</a><?php else: ?><span></span><?php endif; ?>
      <?php if ($hasMore): ?><a href="<?= h($pageUrl($category, $page + 1)) ?>">الأقدم</a><?php endif; ?>
    </nav>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
$title = 'الإشعارات';
$metaRobots = 'noindex, nofollow';
require dirname(__DIR__) . '/views/layout.php';
