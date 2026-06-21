<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\MaterialImageDisplayTemplateService;
use Portal\Services\PortalSettingsService;
use Throwable;

header('Content-Type: application/json; charset=utf-8');

/** @param array<string, mixed> $payload */
function templateApiJson(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    echo $encoded !== false ? $encoded : '{"ok":false,"message":"json encode failed"}';
    exit;
}

WebSession::requirePermission('images.upload');

$user = WebSession::user();
$userId = isset($user['id']) ? (string) $user['id'] : null;
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        $action = trim((string) ($_GET['action'] ?? 'template'));
        if ($action === 'template') {
            templateApiJson([
                'ok' => true,
                'template' => MaterialImageDisplayTemplateService::getTemplate(),
                'defaultTemplate' => MaterialImageDisplayTemplateService::defaultTemplate(),
                'fieldCatalog' => MaterialImageDisplayTemplateService::fieldCatalog(),
                'qrTargetCatalog' => MaterialImageDisplayTemplateService::qrTargetCatalog(),
                'sampleFields' => MaterialImageDisplayTemplateService::sampleFieldMap(),
                'companyLogoUrl' => PortalSettingsService::companyLogoUrl(),
            ]);
        }

        templateApiJson(['ok' => false, 'message' => 'إجراء غير معروف.'], 400);
    }

    if ($method === 'POST') {
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'save') {
            $raw = trim((string) ($_POST['template'] ?? ''));
            if ($raw === '') {
                templateApiJson(['ok' => false, 'message' => 'القالب فارغ.'], 400);
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                templateApiJson(['ok' => false, 'message' => 'صيغة القالب غير صالحة.'], 400);
            }
            MaterialImageDisplayTemplateService::saveTemplate($decoded, $userId);
            templateApiJson([
                'ok' => true,
                'message' => 'تم حفظ قالب عرض الصور.',
                'template' => MaterialImageDisplayTemplateService::getTemplate(),
            ]);
        }

        if ($action === 'reset') {
            MaterialImageDisplayTemplateService::resetTemplate($userId);
            templateApiJson([
                'ok' => true,
                'message' => 'تمت استعادة القالب الافتراضي.',
                'template' => MaterialImageDisplayTemplateService::getTemplate(),
            ]);
        }

        templateApiJson(['ok' => false, 'message' => 'إجراء غير معروف.'], 400);
    }

    templateApiJson(['ok' => false, 'message' => 'طريقة غير مدعومة.'], 405);
} catch (Throwable $exception) {
    templateApiJson(['ok' => false, 'message' => $exception->getMessage()], 500);
}
