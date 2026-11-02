<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\MaterialApiSearchService;
use Portal\Services\ProcurementMaterialInvoiceSession;
use Portal\Services\ProcurementService;

header('Content-Type: application/json; charset=utf-8');

WebSession::requirePermission('procurement.manage');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $user = WebSession::user();
    $userId = isset($user['id']) ? (string) $user['id'] : null;

    $respond = static function (bool $ok, string $message, array $extra = []): void {
        echo json_encode(array_merge([
            'ok' => $ok,
            'message' => $message,
            'invoice' => ProcurementService::invoiceAjaxState(),
        ], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    };

    if ($action === 'invoice_add_materials') {
        $guids = array_values(array_unique(array_filter(array_map(
            static fn ($g): string => trim((string) $g),
            (array) ($_POST['material_guids'] ?? [])
        ))));
        if ($guids === []) {
            $respond(false, 'لم تُحدَّد مواد.');
        }
        $ensure = ProcurementService::ensureMaterialsRegistered($guids);
        $registered = $ensure['registered'] ?? [];
        $added = ProcurementMaterialInvoiceSession::addMany($registered);
        $missing = $ensure['missing'] ?? [];
        if ($added > 0) {
            $msg = sprintf('تمت إضافة %d مادة للفاتورة.', $added);
            if ($missing !== []) {
                $msg .= sprintf(' (تعذّر إيجاد %d في الأمين.)', count($missing));
            }
            $respond(true, $msg);
        }
        $respond(false, $missing !== []
            ? 'لم تُضف مواد — المحدّد غير موجود في الأمين.'
            : 'لم تُضف مواد — تحقق من الاختيار.');
    }

    if ($action === 'invoice_remove_material') {
        $guid = trim((string) ($_POST['material_guid'] ?? ''));
        if ($guid === '') {
            $respond(false, 'معرف المادة غير صالح.');
        }
        ProcurementMaterialInvoiceSession::remove($guid);
        $respond(true, 'تم حذف السطر من الفاتورة.');
    }

    if ($action === 'invoice_clear') {
        ProcurementMaterialInvoiceSession::clear();
        $respond(true, 'تم تفريغ الفاتورة.');
    }

    if ($action === 'invoice_save_all') {
        $saved = 0;
        $errors = [];
        foreach (ProcurementMaterialInvoiceSession::guids() as $guid) {
            $lifecycle = (array) ($_POST['lifecycle'] ?? []);
            $min = (array) ($_POST['min_cartons'] ?? []);
            $max = (array) ($_POST['max_cartons'] ?? []);
            $notes = (array) ($_POST['notes_ar'] ?? []);
            if (!array_key_exists($guid, $lifecycle)) {
                continue;
            }
            $result = ProcurementService::saveMaterial($guid, [
                'lifecycle' => (string) $lifecycle[$guid],
                'min_cartons' => $min[$guid] ?? null,
                'max_cartons' => $max[$guid] ?? null,
                'notes_ar' => (string) ($notes[$guid] ?? ''),
            ], $userId);
            if ($result['ok']) {
                $saved++;
            } else {
                $errors[] = $result['message'];
            }
        }
        $msg = $saved > 0
            ? sprintf('تم حفظ %d صنف.', $saved)
            : ($errors[0] ?? 'لا بنود للحفظ.');
        if ($errors !== [] && $saved > 0) {
            $msg .= ' ' . implode(' ', array_slice($errors, 0, 2));
        }
        $respond($saved > 0, $msg);
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'إجراء غير معروف.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim((string) ($_GET['q'] ?? $_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = max(1, min(48, (int) ($_GET['pageSize'] ?? 24)));
$manufacturer = trim((string) ($_GET['manufacturer'] ?? ''));

if ($q === '') {
    echo json_encode([
        'ok' => true,
        'items' => [],
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => 0,
        'hasMore' => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $storeGuids = ProcurementService::settings()['store_guids'] ?? [];
    $result = MaterialApiSearchService::searchPickerItems(
        $q,
        $page,
        $pageSize,
        is_array($storeGuids) ? $storeGuids : [],
        $manufacturer
    );
    if (!($result['ok'] ?? false)) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => (string) ($result['message'] ?? 'تعذر البحث عن المواد.'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'items' => $result['items'] ?? [],
        'page' => (int) ($result['page'] ?? $page),
        'pageSize' => (int) ($result['pageSize'] ?? $pageSize),
        'total' => (int) ($result['total'] ?? 0),
        'hasMore' => (bool) ($result['hasMore'] ?? false),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'تعذر البحث عن المواد.'], JSON_UNESCAPED_UNICODE);
}
