<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\ApiClient;
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

/**
 * @return array{ok: bool, data?: array<string, mixed>}
 */
function procurementMaterialsSearch(string $q, int $page, int $pageSize, string $manufacturer): array
{
    $base = [
        'page' => $page,
        'pageSize' => $pageSize,
        'includeTotalCount' => 'true',
    ];
    if ($manufacturer !== '') {
        $base['manufacturers'] = $manufacturer;
    }

    $attempts = [
        array_merge($base, ['search' => $q]),
        array_merge($base, ['keyword' => $q]),
    ];

    foreach ($attempts as $query) {
        $response = ApiClient::get('/api/materials', $query, 90);
        if (!($response['ok'] ?? false)) {
            continue;
        }
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $rows = is_array($data['items'] ?? null) ? $data['items'] : [];
        if ($rows !== []) {
            return $response;
        }
        $total = (int) ($data['totalCount'] ?? $data['TotalCount'] ?? 0);
        if ($total > 0) {
            return $response;
        }
    }

    return ApiClient::get('/api/materials', array_merge($base, ['search' => $q]), 90);
}

$items = [];
$total = 0;
$hasMore = false;

try {
    $response = procurementMaterialsSearch($q, $page, $pageSize, $manufacturer);
    if ($response['ok']) {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $rows = is_array($data['items'] ?? null) ? $data['items'] : [];
        $total = max(0, (int) ($data['totalCount'] ?? $data['TotalCount'] ?? 0));
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $guid = trim((string) ($row['materialGuid'] ?? $row['MaterialGuid'] ?? $row['guid'] ?? ''));
            if ($guid === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? $row['Name'] ?? ''));
            $code = trim((string) ($row['materialCode'] ?? $row['MaterialCode'] ?? ''));
            $mfr = trim((string) ($row['manufacturer'] ?? $row['Manufacturer'] ?? ''));
            $label = $name !== '' ? $name . ($code !== '' ? ' (' . $code . ')' : '') : $code;
            if ($mfr !== '') {
                $label .= ' — ' . $mfr;
            }
            $imageGuid = trim((string) ($row['productImageGuid'] ?? $row['ProductImageGuid'] ?? ''));
            $items[] = [
                'value' => $guid,
                'label' => $label,
                'code' => $code,
                'name' => $name,
                'manufacturer' => $mfr,
                'image' => $imageGuid !== '' ? '/api/image.php?id=' . rawurlencode($imageGuid) . '&thumb=1' : '',
            ];
        }
        $hasMore = ($page * $pageSize) < $total;
    }
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'تعذر البحث عن المواد.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'items' => $items,
    'page' => $page,
    'pageSize' => $pageSize,
    'total' => $total,
    'hasMore' => $hasMore,
], JSON_UNESCAPED_UNICODE);
