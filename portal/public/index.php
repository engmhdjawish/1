<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\HomeSectionService;
use Portal\Services\SpecialOfferService;

require dirname(__DIR__) . '/views/helpers.php';

$sections = HomeSectionService::activeSections();
foreach ($sections as &$section) {
    $section['_sort'] = (int) ($section['sort_order'] ?? 0);
}
unset($section);

$offerSections = SpecialOfferService::activeHomeSections();
foreach ($offerSections as &$section) {
    $section['_sort'] = (int) ($section['home_sort_order'] ?? 0);
}
unset($section);

$sections = array_merge($sections, $offerSections);
usort($sections, static fn (array $a, array $b): int => ($a['_sort'] ?? 0) <=> ($b['_sort'] ?? 0));

ob_start();
require dirname(__DIR__) . '/views/home.php';
$content = ob_get_clean();
$title = 'الرئيسية';
require dirname(__DIR__) . '/views/layout.php';
