<?php

declare(strict_types=1);

/**
 * Store seasons: current season by mode and date, suggested seasons, and in-season-first paging.
 * Usage: php scripts/test-store-seasons.php
 */

define('PORTAL_NO_SESSION', true);
$_SESSION = [];

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Services\StoreCatalogService;
use Portal\Services\StoreSeasonService;

$failures = [];
$assert = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo "FAIL: {$label}\n";
        return;
    }
    echo "OK: {$label}\n";
};

$summer = StoreSeasonService::normalize(['id' => 's', 'name_ar' => 'صيفي', 'customer_title_ar' => 'تشكيلة الصيف', 'icon' => 'wb_sunny', 'group_guids' => '["g-sum","g-gen","g-sum"]', 'starts_month' => 4, 'starts_day' => 1]);
$winter = StoreSeasonService::normalize(['id' => 'w', 'name_ar' => 'شتوي', 'icon' => 'nope', 'group_guids' => ['g-win', 'g-gen'], 'starts_month' => 10, 'starts_day' => 15]);
$seasons = [$summer, $winter];
$at = static fn (string $date): int => (int) strtotime($date . ' 12:00:00');
$auto = ['mode' => 'auto', 'manual_season_id' => '', 'show_to_customers' => true];

$assert('normalize dedupes groups and rejects unknown icons', $summer['group_guids'] === ['g-sum', 'g-gen'] && $winter['icon'] === 'wb_sunny');
$assert('auto picks summer in July', (StoreSeasonService::resolve($seasons, $auto, $at('2026-07-10'))['id'] ?? '') === 's');
$assert('auto keeps summer until the winter start day', (StoreSeasonService::resolve($seasons, $auto, $at('2026-10-14'))['id'] ?? '') === 's');
$assert('auto picks winter from its start day', (StoreSeasonService::resolve($seasons, $auto, $at('2026-10-15'))['id'] ?? '') === 'w');
$assert('auto wraps the year: winter in February', (StoreSeasonService::resolve($seasons, $auto, $at('2027-02-01'))['id'] ?? '') === 'w');
$assert('manual uses the chosen season', (StoreSeasonService::resolve($seasons, ['mode' => 'manual', 'manual_season_id' => 'w', 'show_to_customers' => false], $at('2026-07-10'))['id'] ?? '') === 'w');
$assert('off means no season', StoreSeasonService::resolve($seasons, ['mode' => 'off', 'manual_season_id' => 'w', 'show_to_customers' => true], $at('2026-07-10')) === null);
$resolved = StoreSeasonService::resolve($seasons, $auto, $at('2026-07-10'));
$assert('customer title falls back to the name', ($resolved['title_ar'] ?? '') === 'تشكيلة الصيف' && (StoreSeasonService::resolve($seasons, $auto, $at('2026-11-01'))['title_ar'] ?? '') === 'شتوي');
$next = StoreSeasonService::nextSwitch($seasons, 's', $at('2026-07-10'));
$assert('next switch is the winter start', ($next['season']['id'] ?? '') === 'w' && date('Y-m-d', $next['at']) === '2026-10-15');
$assert('start label uses Levant month names', StoreSeasonService::startLabel($winter) === '15 تشرين الأول');

$groups = [
    ['guid' => 'a', 'name' => 'صيفي رجالي'],
    ['guid' => 'b', 'name' => 'شتوي'],
    ['guid' => 'c', 'name' => 'عام'],
    ['guid' => 'd', 'name' => 'مدرسي'],
    ['guid' => 'e', 'name' => 'أحذية الشتاء'],
];
$suggested = StoreSeasonService::suggestedSeasons($groups);
$assert('suggested summer is summer plus general', $suggested[0]['group_guids'] === ['a', 'c'] && $suggested[0]['starts_month'] === 4);
$assert('suggested winter matches both spellings plus general', $suggested[1]['group_guids'] === ['b', 'e', 'c'] && $suggested[1]['starts_month'] === 10);
$assert('save validates before the database', StoreSeasonService::save(null, ['name_ar' => ''], null)['ok'] === false && StoreSeasonService::save(null, ['name_ar' => 'x', 'group_guids' => []], null)['ok'] === false);

$known = array_map(static fn (string $g): array => ['guid' => $g], ['g-sum', 'g-win', 'g-gen', 'g-sch']);
$split = StoreCatalogService::labSeasonSplit([], ['g-sum', 'g-gen'], $known);
$assert('split without a group filter: season vs every other group', $split === ['in' => ['g-sum', 'g-gen'], 'out' => ['g-win', 'g-sch']]);
$assert('split keeps a group filter', StoreCatalogService::labSeasonSplit(['g-win', 'G-SUM'], ['g-sum'], $known) === ['in' => ['G-SUM'], 'out' => ['g-win']]);
$assert('no split when all requested groups are in season', StoreCatalogService::labSeasonSplit(['g-sum'], ['g-sum', 'g-gen'], $known) === null);
$assert('no split without a season or known groups', StoreCatalogService::labSeasonSplit([], [], $known) === null && StoreCatalogService::labSeasonSplit([], ['g-sum'], []) === null);

$segment = static function (string $prefix, int $total, array &$calls): callable {
    return static function (int $page, int $size) use ($prefix, $total, &$calls): array {
        $calls[] = "{$prefix}:{$page}:{$size}";
        $items = [];
        for ($i = ($page - 1) * $size; $i < min($total, $page * $size); $i++) {
            $items[] = ['materialGuid' => "{$prefix}-{$i}", 'warehouseQuantity' => 5];
        }

        return ['ok' => true, 'data' => ['items' => $items, 'totalCount' => $total]];
    };
};
$guids = static fn (array $result): array => array_column($result['products'], 'materialGuid');

$calls = [];
$segments = [$segment('in', 30, $calls), $segment('out', 50, $calls), $segment('soon-in', 3, $calls), $segment('soon-out', 4, $calls)];
$first = StoreCatalogService::fetchLabSegmentedPage(1, 24, $segments, null);
$assert('page 1 is all in season', $first['totalCount'] === 87 && count($first['products']) === 24 && $guids($first)[0] === 'in-0' && $guids($first)[23] === 'in-23');
$second = StoreCatalogService::fetchLabSegmentedPage(2, 24, $segments, null);
$assert('page 2 finishes the season then starts the rest', count($second['products']) === 24 && $guids($second)[0] === 'in-24' && $guids($second)[5] === 'in-29' && $guids($second)[6] === 'out-0' && $guids($second)[23] === 'out-17');
$last = StoreCatalogService::fetchLabSegmentedPage(4, 24, $segments, null);
$assert('last page: the rest, then unavailable in season first', $guids($last) === ['out-42', 'out-43', 'out-44', 'out-45', 'out-46', 'out-47', 'out-48', 'out-49', 'soon-in-0', 'soon-in-1', 'soon-in-2', 'soon-out-0', 'soon-out-1', 'soon-out-2', 'soon-out-3']);
$failing = StoreCatalogService::fetchLabSegmentedPage(1, 24, [$segment('in', 3, $calls), static fn (): array => ['ok' => false, 'error' => 'down']], null);
$assert('an API error stops the page', $failing['products'] === [] && $failing['apiError'] !== null);

echo "\n";
if ($failures !== []) {
    echo count($failures) . " failure(s)\n";
    exit(1);
}

echo "store seasons ok\n";
