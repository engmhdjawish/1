<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\HomeSectionService;

require dirname(__DIR__) . '/views/helpers.php';

$sections = HomeSectionService::activeSections();
ob_start();
require dirname(__DIR__) . '/views/home.php';
$content = ob_get_clean();
$title = 'الرئيسية';
require dirname(__DIR__) . '/views/layout.php';
