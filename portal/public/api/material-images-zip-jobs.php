<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\MaterialImageZipJobService;
use Portal\Support\DashboardHttp;

WebSession::requireAnyPermission(['images.upload', 'images.view', 'orders.view']);
require dirname(__DIR__, 2) . '/views/helpers.php';

$user = WebSession::user();
$userId = isset($user['id']) ? (string) $user['id'] : null;
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$jobId = trim((string) ($_GET['jobId'] ?? ''));
$download = (string) ($_GET['download'] ?? '') === '1';

try {
    MaterialImageZipJobService::ensureTable();

    if ($download) {
        MaterialImageZipJobService::streamDownload($jobId, $userId);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $params = $_POST;
        if ($params === [] && str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $params = $decoded;
            }
        }

        $jobId = MaterialImageZipJobService::createJob($userId, $params);
        DashboardHttp::json(true, 'تم إنشاء طلب التحميل.', [
            'jobId' => $jobId,
            'status' => 'queued',
            'progressMessage' => 'في قائمة الانتظار...',
        ]);
    }

    if ($jobId !== '') {
        DashboardHttp::json(true, '', MaterialImageZipJobService::getStatus($jobId, $userId));
    }

    if ((string) ($_GET['list'] ?? '') === '1') {
        DashboardHttp::json(true, '', [
            'jobs' => MaterialImageZipJobService::listActiveJobsForUser($userId),
        ]);
    }

    DashboardHttp::json(false, 'طلب غير صالح.');
} catch (\Throwable $exception) {
    if ($download && !headers_sent()) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => $exception->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    DashboardHttp::json(false, $exception->getMessage());
}
