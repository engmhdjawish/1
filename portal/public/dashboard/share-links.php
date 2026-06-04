<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Config;
use Portal\Services\ShareLinkService;

WebSession::requirePermission('share_links.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$flash = null;
$flashType = 'success';
$editId = trim((string) ($_GET['edit'] ?? ''));
$editLink = null;
$user = WebSession::user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save') {
        $parseCsv = static function (string $value): array {
            $parts = preg_split('/[,|\n]+/u', $value) ?: [];
            $values = [];
            foreach ($parts as $part) {
                $item = trim((string) $part);
                if ($item !== '') {
                    $values[] = $item;
                }
            }
            return array_values(array_unique($values));
        };
        $result = ShareLinkService::save(
            trim((string) ($_POST['id'] ?? '')) ?: null,
            trim((string) ($_POST['name_ar'] ?? '')),
            trim((string) ($_POST['access_policy_id'] ?? '')),
            isset($_POST['require_password']),
            trim((string) ($_POST['access_username'] ?? '')),
            trim((string) ($_POST['plain_password'] ?? '')),
            trim((string) ($_POST['keyword'] ?? '')),
            (float) ($_POST['min_quantity'] ?? 0),
            trim((string) ($_POST['expires_at'] ?? '')),
            isset($_POST['is_active']),
            isset($user['id']) ? (string) $user['id'] : null,
            $parseCsv(trim((string) ($_POST['forced_material_types'] ?? ''))),
            $parseCsv(trim((string) ($_POST['forced_age_categories'] ?? ''))),
            $parseCsv(trim((string) ($_POST['forced_manufacturers'] ?? ''))),
            $parseCsv(trim((string) ($_POST['forced_size_ranges'] ?? ''))),
            $parseCsv(trim((string) ($_POST['forced_country_origins'] ?? ''))),
            isset($_POST['option_show_images']),
            trim((string) ($_POST['option_price_mode'] ?? 'both')),
            isset($_POST['option_allow_client_filters']),
            isset($_POST['option_allow_sorting']),
            isset($_POST['option_include_result_filters']),
            trim((string) ($_POST['option_default_sort'] ?? 'number:asc'))
        );
        $flash = $result['message'];
        $flashType = $result['ok'] ? 'success' : 'error';
        if ($result['ok']) {
            $editId = (string) ($result['id'] ?? '');
        }
    } elseif ($action === 'toggle') {
        $ok = ShareLinkService::setActive(
            trim((string) ($_POST['id'] ?? '')),
            ($_POST['next_active'] ?? '0') === '1'
        );
        $flash = $ok ? 'تم تحديث حالة الرابط.' : 'تعذر تحديث حالة الرابط.';
        $flashType = $ok ? 'success' : 'error';
    }
}

$filters = [
    'active' => trim((string) ($_GET['active'] ?? '')),
    'q' => trim((string) ($_GET['q'] ?? '')),
    'limit' => (int) ($_GET['limit'] ?? 100),
];

$links = ShareLinkService::list($filters);
if ($editId !== '') {
    $editLink = ShareLinkService::getById($editId);
}
if ($editLink === null) {
    $editId = '';
}
$stats = ShareLinkService::stats();
$policies = ShareLinkService::listAccessPolicies();
$publicBaseUrl = rtrim(Config::appUrl(), '/');

$currentRoute = '/dashboard/share-links.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/share-links.php';
$content = ob_get_clean();
$title = 'روابط المشاركة';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
