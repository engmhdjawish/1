<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\NotificationService;
use Portal\Services\WebCustomerService;
use Portal\Database;
use PDO;

WebSession::requirePermission('notifications.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

NotificationService::ensureTable();

$flash = null;
$flashType = 'success';

if (!empty($_SESSION['notifications_flash'])) {
    $flash = (string) $_SESSION['notifications_flash'];
    $flashType = (string) ($_SESSION['notifications_flash_type'] ?? 'success');
    unset($_SESSION['notifications_flash'], $_SESSION['notifications_flash_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'send') {
        try {
            $scope = trim((string) ($_POST['scope'] ?? 'public'));
            $title = trim((string) ($_POST['title_ar'] ?? ''));
            $body = trim((string) ($_POST['body_ar'] ?? ''));
            $linkUrl = trim((string) ($_POST['link_url'] ?? ''));
            $icon = trim((string) ($_POST['icon'] ?? 'campaign'));
            $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));
            $user = WebSession::user();
            $createdBy = isset($user['id']) ? (string) $user['id'] : null;

            if ($title === '' || $body === '') {
                throw new \RuntimeException('العنوان والنص مطلوبان.');
            }

            if ($scope === 'private') {
                $customerId = trim((string) ($_POST['customer_id'] ?? ''));
                $staffId = trim((string) ($_POST['staff_id'] ?? ''));
                if ($customerId !== '') {
                    NotificationService::createPrivateForCustomer($customerId, $title, $body, $linkUrl ?: null, $icon);
                } elseif ($staffId !== '') {
                    NotificationService::createPrivateForStaff($staffId, $title, $body, $linkUrl ?: null, $icon);
                } else {
                    throw new \RuntimeException('حدّد عميلاً أو موظفاً للإشعار الخاص.');
                }
            } else {
                $audience = trim((string) ($_POST['audience'] ?? NotificationService::AUDIENCE_ALL));
                NotificationService::createPublic(
                    $title,
                    $body,
                    $audience,
                    $linkUrl ?: null,
                    $icon,
                    $expiresAt !== '' ? $expiresAt : null,
                    $createdBy
                );
            }

            $_SESSION['notifications_flash'] = 'تم إرسال الإشعار بنجاح.';
            $_SESSION['notifications_flash_type'] = 'success';
            header('Location: /dashboard/notifications.php', true, 303);
            exit;
        } catch (\Throwable $exception) {
            $flash = $exception->getMessage();
            $flashType = 'error';
        }
    } elseif ($action === 'delete') {
        try {
            $id = trim((string) ($_POST['id'] ?? ''));
            if ($id === '') {
                throw new \RuntimeException('معرّف الإشعار مطلوب.');
            }
            if (!NotificationService::deleteNotification($id)) {
                throw new \RuntimeException('تعذر حذف الإشعار.');
            }
            $_SESSION['notifications_flash'] = 'تم حذف الإشعار.';
            $_SESSION['notifications_flash_type'] = 'success';
            header('Location: /dashboard/notifications.php', true, 303);
            exit;
        } catch (\Throwable $exception) {
            $flash = $exception->getMessage();
            $flashType = 'error';
        }
    }
}

try {
    $sentNotifications = NotificationService::listSent(40);
} catch (\Throwable $exception) {
    $sentNotifications = [];
    if ($flash === null) {
        $flash = 'تعذر تحميل قائمة الإشعارات: ' . $exception->getMessage();
        $flashType = 'error';
    }
}

try {
    $customers = WebCustomerService::listByStatus('active', '', '', 100);
} catch (\Throwable) {
    $customers = [];
}

try {
    $staffUsers = Database::pdo()->query(
        "SELECT id::text AS id, display_name_ar, user_name
         FROM web_users
         WHERE is_active = TRUE
         ORDER BY display_name_ar ASC
         LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable) {
    $staffUsers = [];
}

$currentRoute = '/dashboard/notifications.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/notifications.php';
$content = ob_get_clean();
$title = 'الإشعارات';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
