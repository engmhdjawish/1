<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\ShareLinkService;

WebSession::requirePermission('share_links.manage');
require dirname(__DIR__, 2) . '/views/helpers.php';

$filters = [
    'active' => trim((string) ($_GET['active'] ?? '')),
    'q' => trim((string) ($_GET['q'] ?? '')),
    'limit' => (int) ($_GET['limit'] ?? 100),
];

$links = ShareLinkService::list($filters);
$activeCount = 0;
foreach ($links as $item) {
    if (!empty($item['is_active'])) {
        $activeCount++;
    }
}

$user = WebSession::user();
$currentRoute = '/dashboard/share-links.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/share-links.php';
$content = ob_get_clean();
$title = 'روابط المشاركة';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
