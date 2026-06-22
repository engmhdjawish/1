<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Auth\WebSession;
use Portal\Services\AccountingApiService;
use Portal\Services\OrderService;

WebSession::requireAnyPermission(['accounting.view', 'orders.view']);
require dirname(__DIR__, 2) . '/views/helpers.php';

$syncCounts = OrderService::syncCounts();
$statusCounts = OrderService::statusCounts();
$amine = AccountingApiService::overviewSnapshot();
$error = $amine['error'] ?? null;
$user = WebSession::user();
$currentRoute = '/dashboard/accounting.php';

ob_start();
require dirname(__DIR__, 2) . '/views/dashboard/accounting.php';
$content = ob_get_clean();
$title = 'لوحة أمين';
require dirname(__DIR__, 2) . '/views/dashboard/layout.php';
