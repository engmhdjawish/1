<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Services\StoreCatalogService;

require dirname(__DIR__) . '/views/helpers.php';

$catalog = StoreCatalogService::catalogFromRequest($_GET);
$displayOptions = StoreCatalogService::displayOptions();
$isCustomer = CustomerSession::check();

ob_start();
require dirname(__DIR__) . '/views/store-catalog.php';
$content = ob_get_clean();
$title = 'المتجر';
require dirname(__DIR__) . '/views/layout.php';
