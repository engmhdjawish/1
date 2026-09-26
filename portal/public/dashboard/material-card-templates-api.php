<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\MaterialCardTemplateService;

WebSession::requireAnyPermission(['images.templates.manage', 'images.upload']);
require_once dirname(__DIR__, 2) . '/views/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function materialCardTemplatesApiJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));
$user = WebSession::user();
$userId = isset($user['id']) ? (string) $user['id'] : null;

try {
    if ($action === 'list' || $action === '') {
        materialCardTemplatesApiJson([
            'ok' => true,
            'items' => MaterialCardTemplateService::listTemplates(),
            'fonts' => MaterialCardTemplateService::listFonts(),
            'requirements' => MaterialCardTemplateService::processingRequirements(),
            'field_kinds' => MaterialCardTemplateService::FIELD_KINDS,
            'field_labels' => MaterialCardTemplateService::FIELD_LABELS,
        ]);
    }

    if ($action === 'fonts') {
        materialCardTemplatesApiJson([
            'ok' => true,
            'fonts' => MaterialCardTemplateService::listFonts(),
        ]);
    }

    if ($action === 'get') {
        $id = trim((string) ($_GET['id'] ?? $_POST['id'] ?? ''));
        $template = MaterialCardTemplateService::getById($id);
        if ($template === null) {
            materialCardTemplatesApiJson(['ok' => false, 'message' => 'القالب غير موجود.'], 404);
        }
        materialCardTemplatesApiJson(['ok' => true, 'template' => $template]);
    }

    if ($action === 'default') {
        $template = MaterialCardTemplateService::getDefault();
        materialCardTemplatesApiJson([
            'ok' => $template !== null,
            'template' => $template,
            'requirements' => MaterialCardTemplateService::processingRequirements(),
            'message' => $template === null ? 'لا يوجد قالب افتراضي.' : 'ok',
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        materialCardTemplatesApiJson(['ok' => false, 'message' => 'طريقة غير مدعومة.'], 405);
    }

    if ($action === 'upload') {
        $targetW = (int) ($_POST['target_width'] ?? 0);
        $targetH = (int) ($_POST['target_height'] ?? 0);
        $result = MaterialCardTemplateService::upload(
            is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [],
            trim((string) ($_POST['name_ar'] ?? '')),
            $userId,
            (string) ($_POST['make_default'] ?? '') === '1',
            $targetW > 0 ? $targetW : null,
            $targetH > 0 ? $targetH : null
        );
        materialCardTemplatesApiJson($result, !empty($result['ok']) ? 200 : 400);
    }

    if ($action === 'upload-font') {
        $result = MaterialCardTemplateService::uploadFont(
            is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [],
            trim((string) ($_POST['name_ar'] ?? '')),
            $userId
        );
        materialCardTemplatesApiJson($result, !empty($result['ok']) ? 200 : 400);
    }

    if ($action === 'delete-font') {
        $result = MaterialCardTemplateService::deleteFont(trim((string) ($_POST['id'] ?? '')));
        materialCardTemplatesApiJson($result, !empty($result['ok']) ? 200 : 400);
    }

    if ($action === 'save') {
        $id = trim((string) ($_POST['id'] ?? ''));
        $fieldsRaw = $_POST['fields'] ?? '[]';
        if (is_string($fieldsRaw)) {
            $decoded = json_decode($fieldsRaw, true);
            $fields = is_array($decoded) ? $decoded : [];
        } else {
            $fields = is_array($fieldsRaw) ? $fieldsRaw : [];
        }
        $result = MaterialCardTemplateService::update($id, [
            'name_ar' => trim((string) ($_POST['name_ar'] ?? '')),
            'is_active' => (string) ($_POST['is_active'] ?? '1') === '1',
            'is_default' => (string) ($_POST['is_default'] ?? '') === '1',
            'fields' => $fields,
        ]);
        materialCardTemplatesApiJson($result, !empty($result['ok']) ? 200 : 400);
    }

    if ($action === 'delete') {
        $result = MaterialCardTemplateService::delete(trim((string) ($_POST['id'] ?? '')));
        materialCardTemplatesApiJson($result, !empty($result['ok']) ? 200 : 400);
    }

    materialCardTemplatesApiJson(['ok' => false, 'message' => 'إجراء غير معروف.'], 400);
} catch (Throwable $e) {
    materialCardTemplatesApiJson([
        'ok' => false,
        'message' => 'خطأ: ' . $e->getMessage(),
    ], 500);
}
