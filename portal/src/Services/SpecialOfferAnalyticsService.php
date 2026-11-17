<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

/**
 * Offer effectiveness: sales from order_items + engagement from visitor_logs.
 */
final class SpecialOfferAnalyticsService
{
    private const PRICE_EPSILON = 0.009;

    /**
     * @param list<string> $offerIds
     * @param array{include_engagement?: bool, days?: int} $options
     * @return array<string, array<string, int|float>>
     */
    public static function summariesForOffers(array $offerIds, array $options = []): array
    {
        $includeEngagement = !empty($options['include_engagement']);
        $days = max(1, min(365, (int) ($options['days'] ?? 90)));
        $offerIds = self::normalizeOfferIds($offerIds);
        $out = [];
        foreach ($offerIds as $id) {
            $out[$id] = self::emptySummary();
        }
        if ($offerIds === []) {
            return $out;
        }

        [$in, $params] = self::placeholders($offerIds, 'o');

        try {
            $sales = Database::pdo()->prepare(
                'SELECT oi.special_offer_id::text AS offer_id,
                        COUNT(DISTINCT oi.order_id)::int AS orders,
                        COUNT(*)::int AS lines,
                        COALESCE(SUM(oi.quantity), 0)::float AS packages,
                        COALESCE(SUM(oi.sale_price_sp * oi.quantity), 0)::float AS revenue_syp,
                        COALESCE(SUM(oi.sale_price_usd * oi.quantity), 0)::float AS revenue_usd,
                        COALESCE(SUM(GREATEST(COALESCE(oi.original_sale_price_sp, oi.sale_price_sp) - oi.sale_price_sp, 0) * oi.quantity), 0)::float AS discount_syp,
                        COALESCE(SUM(GREATEST(COALESCE(oi.original_sale_price_usd, oi.sale_price_usd) - oi.sale_price_usd, 0) * oi.quantity), 0)::float AS discount_usd,
                        COUNT(DISTINCT oi.material_guid)::int AS materials
                 FROM order_items oi
                 INNER JOIN orders o ON o.id = oi.order_id
                 WHERE oi.special_offer_id IN (' . $in . ')
                   AND o.status NOT IN (\'cancelled\', \'rejected\')
                 GROUP BY oi.special_offer_id'
            );
            $sales->execute($params);
            foreach ($sales->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (string) ($row['offer_id'] ?? '');
                if ($id === '' || !isset($out[$id])) {
                    continue;
                }
                $out[$id] = self::applySalesRow($out[$id], $row);
                $out[$id]['orders_linked'] = (int) ($row['orders'] ?? 0);
            }
        } catch (\Throwable) {
            // Missing special_offer_id / original_sale_price_* columns — keep zero sales.
        }

        self::mergeInferredSummaries($out, $offerIds, $params, $in);

        if (!$includeEngagement) {
            return $out;
        }

        $slugById = [];
        try {
            $slugs = Database::pdo()->prepare(
                'SELECT id::text AS id, slug FROM special_offers WHERE id IN (' . $in . ')'
            );
            $slugs->execute($params);
            foreach ($slugs->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $slugById[(string) $row['id']] = (string) $row['slug'];
            }
        } catch (\Throwable) {
            return $out;
        }

        foreach ($slugById as $id => $slug) {
            $out[$id]['page_views'] = self::engagementCount($id, $slug, ['page_view', 'product_view'], $days);
            $out[$id]['add_to_cart'] = self::engagementCount($id, $slug, ['add_to_cart'], $days);
        }

        return $out;
    }

    /**
     * @return array{
     *   summary: array<string, mixed>,
     *   materials: list<array<string, mixed>>,
     *   recent_orders: list<array<string, mixed>>
     * }
     */
    public static function detailForOffer(string $offerId, int $days = 90): array
    {
        $offerId = trim($offerId);
        $summaries = self::summariesForOffers([$offerId], ['include_engagement' => true, 'days' => $days]);
        $summary = $summaries[$offerId] ?? self::emptySummary();

        $materials = [];
        $recentOrders = [];
        if ($offerId !== '') {
            try {
                $stmt = Database::pdo()->prepare(
                    'SELECT oi.material_guid::text AS material_guid,
                            MAX(oi.material_name_ar) AS material_name_ar,
                            MAX(oi.material_code) AS material_code,
                            COUNT(DISTINCT oi.order_id)::int AS orders,
                            COALESCE(SUM(oi.quantity), 0)::float AS packages,
                            COALESCE(SUM(oi.sale_price_sp * oi.quantity), 0)::float AS revenue_syp,
                            COALESCE(SUM(oi.sale_price_usd * oi.quantity), 0)::float AS revenue_usd,
                            COALESCE(SUM(GREATEST(COALESCE(oi.original_sale_price_sp, oi.sale_price_sp) - oi.sale_price_sp, 0) * oi.quantity), 0)::float AS discount_syp
                     FROM order_items oi
                     INNER JOIN orders o ON o.id = oi.order_id
                     INNER JOIN special_offers so ON so.id = :offer_id
                     WHERE o.status NOT IN (\'cancelled\', \'rejected\')
                       AND (
                         oi.special_offer_id = :offer_id
                         OR (
                           oi.special_offer_id IS NULL
                           AND EXISTS (
                             SELECT 1 FROM special_offer_products sop
                             WHERE sop.offer_id = :offer_id
                               AND LOWER(TRIM(sop.material_guid::text)) = LOWER(TRIM(oi.material_guid::text))
                           )
                           AND o.created_at >= so.starts_at
                           AND (so.ends_at IS NULL OR o.created_at < so.ends_at)
                           AND ' . self::discountedLineSql('oi') . '
                         )
                       )
                     GROUP BY oi.material_guid
                     ORDER BY packages DESC, revenue_syp DESC
                     LIMIT 48'
                );
                $stmt->execute(['offer_id' => $offerId]);
                $materials = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable) {
                $materials = [];
            }

            $recentOrders = self::recentOrdersForOffer($offerId, 20);
        }

        $conversion = $summary['page_views'] > 0
            ? round(($summary['orders'] / max(1, $summary['page_views'])) * 100, 2)
            : null;
        $summary['conversion_pct'] = $conversion;
        $summary['cart_rate_pct'] = $summary['page_views'] > 0
            ? round(($summary['add_to_cart'] / max(1, $summary['page_views'])) * 100, 2)
            : null;
        $summary['order_submit_rate_pct'] = $summary['add_to_cart'] > 0
            ? round(($summary['orders'] / max(1, $summary['add_to_cart'])) * 100, 2)
            : null;

        return ['summary' => $summary, 'materials' => $materials, 'recent_orders' => $recentOrders];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recentOrdersForOffer(string $offerId, int $limit = 20): array
    {
        $offerId = trim($offerId);
        $limit = max(1, min(50, $limit));
        if ($offerId === '') {
            return [];
        }

        try {
            $stmt = Database::pdo()->prepare(
                'SELECT o.id::text AS order_id,
                        o.order_number,
                        o.status,
                        o.created_at,
                        o.guest_name_ar,
                        o.guest_phone,
                        COUNT(*)::int AS line_count,
                        COALESCE(SUM(oi.quantity), 0)::float AS packages,
                        COALESCE(SUM(oi.sale_price_sp * oi.quantity), 0)::float AS revenue_syp,
                        BOOL_AND(oi.special_offer_id IS NOT NULL) AS all_lines_linked
                 FROM orders o
                 INNER JOIN order_items oi ON oi.order_id = o.id
                 INNER JOIN special_offers so ON so.id = :offer_id
                 WHERE o.status NOT IN (\'cancelled\', \'rejected\')
                   AND (
                     oi.special_offer_id = :offer_id
                     OR (
                       oi.special_offer_id IS NULL
                       AND EXISTS (
                         SELECT 1 FROM special_offer_products sop
                         WHERE sop.offer_id = :offer_id
                           AND LOWER(TRIM(sop.material_guid::text)) = LOWER(TRIM(oi.material_guid::text))
                       )
                       AND o.created_at >= so.starts_at
                       AND (so.ends_at IS NULL OR o.created_at < so.ends_at)
                       AND ' . self::discountedLineSql('oi') . '
                     )
                   )
                 GROUP BY o.id, o.order_number, o.status, o.created_at, o.guest_name_ar, o.guest_phone
                 ORDER BY o.created_at DESC
                 LIMIT ' . $limit
            );
            $stmt->execute(['offer_id' => $offerId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    public static function soldPackages(string $offerId, string $materialGuid): float
    {
        $offerId = trim($offerId);
        $materialGuid = strtolower(trim($materialGuid));
        if ($offerId === '' || $materialGuid === '') {
            return 0.0;
        }
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT COALESCE(SUM(oi.quantity), 0)::float
                 FROM order_items oi
                 INNER JOIN orders o ON o.id = oi.order_id
                 WHERE oi.special_offer_id = :offer_id
                   AND LOWER(oi.material_guid::text) = :guid
                   AND o.status NOT IN (\'cancelled\', \'rejected\')'
            );
            $stmt->execute(['offer_id' => $offerId, 'guid' => $materialGuid]);

            return (float) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /** @return array<string, int|float> */
    private static function emptySummary(): array
    {
        return [
            'orders' => 0,
            'orders_linked' => 0,
            'orders_inferred' => 0,
            'lines' => 0,
            'packages' => 0.0,
            'revenue_syp' => 0.0,
            'revenue_usd' => 0.0,
            'discount_syp' => 0.0,
            'discount_usd' => 0.0,
            'materials' => 0,
            'add_to_cart' => 0,
            'page_views' => 0,
        ];
    }

    /** @param list<string> $offerIds @return list<string> */
    private static function normalizeOfferIds(array $offerIds): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($id): string => trim((string) $id), $offerIds),
            static fn (string $id): bool => $id !== ''
        )));
    }

    /**
     * @param list<string> $offerIds
     * @return array{0: string, 1: array<string, string>}
     */
    private static function placeholders(array $offerIds, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach ($offerIds as $i => $id) {
            $key = $prefix . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        return [implode(', ', $placeholders), $params];
    }

    /** @param array<string, int|float> $base @param array<string, mixed> $row @return array<string, int|float> */
    private static function applySalesRow(array $base, array $row): array
    {
        return array_merge($base, [
            'orders' => (int) ($row['orders'] ?? 0),
            'lines' => (int) ($row['lines'] ?? 0),
            'packages' => (float) ($row['packages'] ?? 0),
            'revenue_syp' => (float) ($row['revenue_syp'] ?? 0),
            'revenue_usd' => (float) ($row['revenue_usd'] ?? 0),
            'discount_syp' => (float) ($row['discount_syp'] ?? 0),
            'discount_usd' => (float) ($row['discount_usd'] ?? 0),
            'materials' => (int) ($row['materials'] ?? 0),
        ]);
    }

    /**
     * @param array<string, array<string, int|float>> $out
     * @param list<string> $offerIds
     * @param array<string, string> $params
     */
    private static function mergeInferredSummaries(array &$out, array $offerIds, array $params, string $in): void
    {
        if ($offerIds === []) {
            return;
        }

        try {
            $inferred = Database::pdo()->prepare(
                'SELECT so.id::text AS offer_id,
                        COUNT(DISTINCT oi.order_id)::int AS orders,
                        COUNT(*)::int AS lines,
                        COALESCE(SUM(oi.quantity), 0)::float AS packages,
                        COALESCE(SUM(oi.sale_price_sp * oi.quantity), 0)::float AS revenue_syp,
                        COALESCE(SUM(oi.sale_price_usd * oi.quantity), 0)::float AS revenue_usd,
                        COALESCE(SUM(GREATEST(COALESCE(oi.original_sale_price_sp, oi.sale_price_sp) - oi.sale_price_sp, 0) * oi.quantity), 0)::float AS discount_syp,
                        COALESCE(SUM(GREATEST(COALESCE(oi.original_sale_price_usd, oi.sale_price_usd) - oi.sale_price_usd, 0) * oi.quantity), 0)::float AS discount_usd,
                        COUNT(DISTINCT oi.material_guid)::int AS materials
                 FROM special_offers so
                 INNER JOIN special_offer_products sop ON sop.offer_id = so.id
                 INNER JOIN order_items oi
                    ON LOWER(TRIM(oi.material_guid::text)) = LOWER(TRIM(sop.material_guid::text))
                 INNER JOIN orders o ON o.id = oi.order_id
                 WHERE so.id IN (' . $in . ')
                   AND oi.special_offer_id IS NULL
                   AND o.status NOT IN (\'cancelled\', \'rejected\')
                   AND o.created_at >= so.starts_at
                   AND (so.ends_at IS NULL OR o.created_at < so.ends_at)
                   AND ' . self::discountedLineSql('oi') . '
                 GROUP BY so.id'
            );
            $inferred->execute($params);
            foreach ($inferred->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $id = (string) ($row['offer_id'] ?? '');
                if ($id === '' || !isset($out[$id])) {
                    continue;
                }
                $inferredOrders = (int) ($row['orders'] ?? 0);
                if ($inferredOrders === 0) {
                    continue;
                }
                $out[$id]['orders_inferred'] = $inferredOrders;
                $out[$id]['orders'] = (int) $out[$id]['orders'] + $inferredOrders;
                $out[$id]['lines'] = (int) $out[$id]['lines'] + (int) ($row['lines'] ?? 0);
                $out[$id]['packages'] = (float) $out[$id]['packages'] + (float) ($row['packages'] ?? 0);
                $out[$id]['revenue_syp'] = (float) $out[$id]['revenue_syp'] + (float) ($row['revenue_syp'] ?? 0);
                $out[$id]['revenue_usd'] = (float) $out[$id]['revenue_usd'] + (float) ($row['revenue_usd'] ?? 0);
                $out[$id]['discount_syp'] = (float) $out[$id]['discount_syp'] + (float) ($row['discount_syp'] ?? 0);
                $out[$id]['discount_usd'] = (float) $out[$id]['discount_usd'] + (float) ($row['discount_usd'] ?? 0);
                $out[$id]['materials'] = max((int) $out[$id]['materials'], (int) ($row['materials'] ?? 0));
            }
        } catch (\Throwable) {
            // optional join tables may be missing on old DBs
        }
    }

    private static function discountedLineSql(string $alias): string
    {
        $eps = self::PRICE_EPSILON;

        return '('
            . '(' . $alias . '.original_sale_price_sp IS NOT NULL AND ' . $alias . '.sale_price_sp IS NOT NULL AND '
            . $alias . '.original_sale_price_sp > ' . $alias . '.sale_price_sp + ' . $eps . ')'
            . ' OR ('
            . $alias . '.original_sale_price_usd IS NOT NULL AND ' . $alias . '.sale_price_usd IS NOT NULL AND '
            . $alias . '.original_sale_price_usd > ' . $alias . '.sale_price_usd + ' . $eps
            . '))';
    }

    /**
     * @param list<string> $actions
     */
    private static function engagementCount(string $offerId, string $slug, array $actions, int $days): int
    {
        if ($offerId === '' && $slug === '') {
            return 0;
        }

        $actionPlaceholders = [];
        $params = [
            'slug' => $slug,
            'offer_id' => $offerId,
            'slug_like' => '%"offer_slug":"' . $slug . '"%',
            'id_like' => '%"offer_id":"' . $offerId . '"%',
            'offer_q' => '%offer=' . $slug . '%',
        ];
        foreach ($actions as $i => $action) {
            $key = 'act' . $i;
            $actionPlaceholders[] = ':' . $key;
            $params[$key] = $action;
        }
        $actionIn = implode(', ', $actionPlaceholders);

        try {
            $intervalDays = max(1, min(365, $days));
            $stmt = Database::pdo()->prepare(
                "SELECT COUNT(*)::int AS total
                 FROM visitor_logs
                 WHERE created_at >= NOW() - INTERVAL '{$intervalDays} days'
                   AND action IN ({$actionIn})
                   AND (
                     (details_ar LIKE '{%' AND (
                       details_ar::jsonb->>'offer_slug' = :slug
                       OR details_ar::jsonb->>'offer_id' = :offer_id
                     ))
                     OR details_ar ILIKE :slug_like
                     OR details_ar ILIKE :id_like
                     OR referer ILIKE :offer_q
                     OR referer ILIKE '%store.php%offer=' || :slug || '%'
                   )"
            );
            $stmt->execute($params);

            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}