<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\NotificationService;

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

        if ($action === 'poll') {
            $since = trim((string) ($_GET['since'] ?? ''));
            $latest = NotificationService::latestActivityAt();
            $count = NotificationService::unreadCount();
            $changed = NotificationService::activityIsNewer($latest, $since);
            $item = null;
            if ($changed) {
                $candidate = NotificationService::latestUnread();
                $candidateAt = is_array($candidate) ? (string) ($candidate['created_at'] ?? '') : '';
                if ($candidate !== null && NotificationService::activityIsNewer($candidateAt, $since)) {
                    $item = $candidate;
                }
            }

            echo json_encode([
                'ok' => true,
                'changed' => $changed,
                'latest' => $latest,
                'count' => $count,
                'item' => $item,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'readers') {
            WebSession::requirePermission('notifications.manage');
            $id = trim((string) ($_GET['id'] ?? ''));
            $readers = NotificationService::listReadersForNotification($id, 150);
            echo json_encode([
                'ok' => true,
                'id' => $id,
                'count' => count($readers),
                'readers' => $readers,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 30)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $category = trim((string) ($_GET['category'] ?? ''));
        $rows = NotificationService::listForReader($limit + 1, $offset, $category);
        echo json_encode([
            'ok' => true,
            'items' => array_slice($rows, 0, $limit),
            'has_more' => count($rows) > $limit,
            'unread' => NotificationService::unreadCount(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'طريقة غير مدعومة.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($fetchSite === 'cross-site') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'طلب غير مسموح.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $action = trim((string) ($payload['action'] ?? ''));

    if ($action === 'read') {
        $id = trim((string) ($payload['id'] ?? ''));
        $via = trim((string) ($payload['via'] ?? 'web'));
        if (!NotificationService::markRead($id, $via)) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'message' => 'الإشعار غير متاح.',
                'unread' => NotificationService::unreadCount(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => true, 'unread' => NotificationService::unreadCount()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'read_all') {
        $marked = NotificationService::markAllRead();
        echo json_encode([
            'ok' => true,
            'marked' => $marked,
            'unread' => NotificationService::unreadCount(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'dismiss') {
        $id = trim((string) ($payload['id'] ?? ''));
        if (!NotificationService::dismissForReader($id)) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'message' => 'الإشعار غير متاح.',
                'unread' => NotificationService::unreadCount(),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
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

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'إجراء غير معروف.'], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $exception) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
