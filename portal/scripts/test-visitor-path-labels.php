<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Services/VisitorLogService.php';

use Portal\Services\VisitorLogService;

$failed = 0;

$check = static function (string $label, string $actual, string $expected) use (&$failed): void {
    if ($actual === $expected) {
        echo "  ok  {$label}\n";

        return;
    }
    $failed++;
    echo "  FAIL {$label}\n    expected: {$expected}\n    actual:   {$actual}\n";
};

$arabic = rawurlencode('سيراميك');
$store = VisitorLogService::describePath('/store.php?q=' . $arabic);
$check('arabic search title', $store['title'], 'المتجر');
$check('arabic search detail', $store['detail'], 'بحث: سيراميك');

$double = rawurlencode('%D8%B3');
$decoded = VisitorLogService::describePath('/store.php?q=' . $double);
$check('double-encoded search', $decoded['detail'], 'بحث: س');

$plain = VisitorLogService::describePath('/store.php');
$check('store home', $plain['label'], 'المتجر');

$material = rawurlencode('بورسلان');
$filtered = VisitorLogService::describePath('/store.php?materialTypes=' . $material . '&page=2');
$check('filter and page', $filtered['detail'], 'نوع المادة: بورسلان · صفحة 2');

$plus = VisitorLogService::describePath('/store.php?q=' . rawurlencode('سيراميك أبيض'));
$check('search with space', $plus['detail'], 'بحث: سيراميك أبيض');

$product = VisitorLogService::describePath(
    '/product.php?guid=550e8400-e29b-41d4-a716-446655440000',
    'بلاط أبيض — شركة الجاويش'
);
$check('product title', $product['label'], 'بلاط أبيض');

$check('home', VisitorLogService::describePath('/')['label'], 'الرئيسية');
$check('orders', VisitorLogService::describePath('/my-orders.php')['label'], 'طلباتي');
$check('cart', VisitorLogService::describePath('/store-cart.php')['label'], 'السلة');
$check('dashboard', VisitorLogService::describePath('/dashboard/visitor-analytics.php')['label'], 'سجل الزوار');

$guidOnly = VisitorLogService::describePath('/store.php?storeGuids=550e8400-e29b-41d4-a716-446655440000');
$check('hidden guid query', $guidOnly['label'], 'المتجر');

$share = VisitorLogService::describePath(
    '/share.php?t=abc',
    'عرض الجملة الخاص — جاويش للتجارة'
);
$check('share link uses page name', $share['title'], 'رابط مشاركة');
$check('share link keeps share title', $share['detail'], 'عرض الجملة الخاص');
$check('share link label', $share['label'], 'رابط مشاركة — عرض الجملة الخاص');

$check('notifications page', VisitorLogService::describePath('/notifications.php')['label'], 'الإشعارات');

echo $failed === 0 ? "\npath labels ok\n" : "\n{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
