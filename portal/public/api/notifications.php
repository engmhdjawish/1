<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\NotificationService;
use Portal\Support\DashboardHttp;

require dirname(__DIR__, 2) . '/views/helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
    NotificationService::ensureTable();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $action = trim((string) ($_GET['action'] ?? 'list'));
        if ($action === 'count') {
            echo json_encode([
                'ok' => true,
                'count' => NotificationService::unreadCount(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'latest_unread') {
            $item = NotificationService::latestUnread();
            echo json_encode([
                'ok' => true,
                'item' => $item,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'items' => NotificationService::listForReader(40),
            'unread' => NotificationService::unreadCount(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'طريقة غير مدعومة.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $action = trim((string) ($payload['action'] ?? ''));

    if ($action === 'read') {
        $id = trim((string) ($payload['id'] ?? ''));
        NotificationService::markRead($id);
        echo json_encode(['ok' => true, 'unread' => NotificationService::unreadCount()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'read_all') {
        $marked = NotificationService::markAllRead();
        echo json_encode(['ok' => true, 'marked' => $marked, 'unread' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'dismiss') {
        $id = trim((string) ($payload['id'] ?? ''));
        NotificationService::dismissForReader($id);
        echo json_encode([
            'ok' => true,
            'unread' => NotificationService::unreadCount(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        WebSession::requirePermission('notifications.manage');
        $id = trim((string) ($payload['id'] ?? ''));
        $deleted = NotificationService::deleteNotification($id);
        if (!$deleted) {
            throw new \RuntimeException('تعذر حذف الإشعار.');
        }
        echo json_encode(['ok' => true, 'message' => 'تم حذف الإشعار.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    WebSession::requirePermission('notifications.manage');
    $scope = trim((string) ($payload['scope'] ?? 'public'));
    $title = trim((string) ($payload['title'] ?? ''));
    $body = trim((string) ($payload['body'] ?? ''));
    $linkUrl = trim((string) ($payload['link_url'] ?? ''));
    $icon = trim((string) ($payload['icon'] ?? 'campaign'));
    $expiresAt = trim((string) ($payload['expires_at'] ?? ''));

    if ($title === '' || $body === '') {
        throw new \RuntimeException('العنوان والنص مطلوبان.');
    }

    $user = WebSession::user();
    $createdBy = isset($user['id']) ? (string) $user['id'] : null;
    $expires = $expiresAt !== '' ? $expiresAt : null;

    if ($scope === 'private') {
        $customerId = trim((string) ($payload['customer_id'] ?? ''));
        $staffId = trim((string) ($payload['staff_id'] ?? ''));
        if ($customerId !== '') {
            $id = NotificationService::createPrivateForCustomer($customerId, $title, $body, $linkUrl ?: null, $icon);
        } elseif ($staffId !== '') {
            $id = NotificationService::createPrivateForStaff($staffId, $title, $body, $linkUrl ?: null, $icon);
        } else {
            throw new \RuntimeException('حدّد عميلاً أو موظفاً للإشعار الخاص.');
        }
    } else {
        $audience = trim((string) ($payload['audience'] ?? NotificationService::AUDIENCE_ALL));
        $id = NotificationService::createPublic($title, $body, $audience, $linkUrl ?: null, $icon, $expires, $createdBy);
    }

    if (DashboardHttp::wantsJson()) {
        echo json_encode(['ok' => true, 'message' => 'تم إرسال الإشعار.', 'id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => 'تم إرسال الإشعار.', 'id' => $id], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $exception) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
