<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\ApiClient;
use Portal\Services\CatalogSectionResolver;
use Portal\Services\HomeSectionService;
use Portal\Services\StoreCatalogService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=20');

$term = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($term) < 2) {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$section = trim((string) ($_GET['section'] ?? ''));
$offer = trim((string) ($_GET['offer'] ?? ''));
$context = CatalogSectionResolver::resolve($section, $offer);
$rules = is_array($context['filter_rules'] ?? null) ? $context['filter_rules'] : [];
$rules = StoreCatalogService::mergePolicyFilterRules($rules);

$scoped = HomeSectionService::materialsListQuery($rules, 8);
unset($scoped['page'], $scoped['pageSize'], $scoped['sort'], $scoped['includeTotalCount']);
$scoped['q'] = $term;
$scoped['limit'] = 8;

try {
    $response = ApiClient::get('/api/materials/suggest', $scoped, 12);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$items = [];
$rows = is_array($response['data']['items'] ?? null) ? $response['data']['items'] : [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $guid = trim((string) ($row['materialGuid'] ?? $row['MaterialGuid'] ?? $row['guid'] ?? ''));
    $name = trim((string) ($row['name'] ?? $row['Name'] ?? ''));
    $code = trim((string) ($row['materialCode'] ?? $row['MaterialCode'] ?? ''));
    if ($guid === '' || $name === '') {
        continue;
    }
    $params = ['guid' => $guid];
    if ($offer !== '') {
        $params['offer'] = $offer;
    }
    $items[] = [
        'guid' => $guid,
        'name' => $name,
        'code' => $code,
        'url' => '/product.php?' . http_build_query($params),
    ];
}

echo json_encode([
    'ok' => (bool) ($response['ok'] ?? false),
    'items' => $items,
], JSON_UNESCAPED_UNICODE);
