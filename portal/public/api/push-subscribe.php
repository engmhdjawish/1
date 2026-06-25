<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\PushSubscriptionService;

header('Content-Type: application/json; charset=utf-8');

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'طريقة غير مدعومة.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new \RuntimeException('بيانات الاشتراك غير صالحة.');
    }

    $action = trim((string) ($payload['action'] ?? 'subscribe'));
    if ($action === 'unsubscribe') {
        PushSubscriptionService::removeForCurrentReader((string) ($payload['endpoint'] ?? ''));
        echo json_encode(['ok' => true, 'message' => 'تم إلغاء الاشتراك.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $saved = PushSubscriptionService::saveForCurrentReader($payload);
    if (!$saved) {
        throw new \RuntimeException('تعذر حفظ اشتراك الإشعارات.');
    }

    echo json_encode(['ok' => true, 'message' => 'تم تفعيل إشعارات الجهاز.'], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $exception) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
