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
        if (empty($options['skip_backfill'])) {
            VisitorLogService::backfillOrdersVisitorSessionFromLogs($days);
            self::bulkPersistOfferLinksFromLogs($days);
            self::backfillRecentOrderAttribution($days);
        }
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
        self::mergeSessionInferredSummaries($out, $offerIds, $params, $in);

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
                       AND (
                         ' . self::discountedLineSql('oi') . '
                         OR ' . self::orderOfferEvidenceSql('o', 'so') . '
                         OR so.selection_mode::text = \'filter\'
                       )
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
                       AND (
                         ' . self::discountedLineSql('oi') . '
                         OR ' . self::orderOfferEvidenceSql('o', 'so') . '
                         OR so.selection_mode::text = \'filter\'
                       )
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
            'orders_session_inferred' => 0,
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
                   AND (
                     ' . self::discountedLineSql('oi') . '
                     OR ' . self::orderOfferEvidenceSql('o', 'so') . '
                   )
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
                $out[$id]['orders_inferred'] = (int) ($out[$id]['orders_inferred'] ?? 0) + $inferredOrders;
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

    /**
     * @param array<string, array<string, int|float>> $out
     * @param list<string> $offerIds
     * @param array<string, string> $params
     */
    private static function mergeSessionInferredSummaries(array &$out, array $offerIds, array $params, string $in): void
    {
        if ($offerIds === [] || !VisitorLogService::ordersHaveVisitorSessionColumn()) {
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
                 INNER JOIN orders o
                    ON o.created_at >= so.starts_at
                   AND (so.ends_at IS NULL OR o.created_at < so.ends_at)
                 INNER JOIN order_items oi ON oi.order_id = o.id
                 WHERE so.id IN (' . $in . ')
                   AND oi.special_offer_id IS NULL
                   AND o.status NOT IN (\'cancelled\', \'rejected\')
                   AND ' . self::orderOfferEvidenceSql('o', 'so') . '
                   AND NOT EXISTS (
                     SELECT 1
                     FROM order_items oi2
                     INNER JOIN special_offer_products sop2 ON sop2.offer_id = so.id
                       AND lower(trim(sop2.material_guid::text)) = lower(trim(oi2.material_guid::text))
                     WHERE oi2.order_id = o.id
                       AND oi2.special_offer_id IS NULL
                       AND ' . self::discountedLineSql('oi2') . '
                   )
                   AND (
                     so.selection_mode::text = \'filter\'
                     OR EXISTS (
                       SELECT 1 FROM special_offer_products sop
                       WHERE sop.offer_id = so.id
                         AND lower(trim(sop.material_guid::text)) = lower(trim(oi.material_guid::text))
                     )
                     OR ' . self::discountedLineSql('oi') . '
                   )
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
                $out[$id]['orders_session_inferred'] = $inferredOrders;
                $out[$id]['orders_inferred'] = (int) ($out[$id]['orders_inferred'] ?? 0) + $inferredOrders;
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
            // visitor_logs / visitor_session_id may be absent
        }
    }

    /** SQL: order_placed log for this order names the offer slug. */
    private static function orderPlacedOfferLogSql(string $orderAlias, string $offerAlias): string
    {
        return 'EXISTS (
            SELECT 1
            FROM visitor_logs vop
            WHERE vop.action = \'order_placed\'
              AND vop.created_at <= ' . $orderAlias . '.created_at + interval \'6 hours\'
              AND vop.created_at >= ' . $orderAlias . '.created_at - interval \'1 hour\'
              AND (
                (vop.details_ar LIKE \'{%\' AND (
                  vop.details_ar::jsonb->>\'order_id\' = ' . $orderAlias . '.id::text
                  OR vop.details_ar::jsonb->>\'order_number\' = ' . $orderAlias . '.order_number
                ))
                OR vop.details_ar ILIKE \'%\' || ' . $orderAlias . '.order_number || \'%\'
              )
              AND (
                (vop.details_ar LIKE \'{%\' AND vop.details_ar::jsonb->>\'offer_slug\' = ' . $offerAlias . '.slug)
                OR vop.details_ar ILIKE \'%"offer_slug":"\' || ' . $offerAlias . '.slug || \'"%\'
              )
        )';
    }

    private static function orderOfferEvidenceSql(string $orderAlias, string $offerAlias): string
    {
        return '(' . self::orderOfferSessionSql($orderAlias, $offerAlias) . ' OR ' . self::orderPlacedOfferLogSql($orderAlias, $offerAlias) . ')';
    }

    /** SQL: order visited this offer (visitor_logs.session_id = orders.visitor_session_id). */
    private static function orderOfferSessionSql(string $orderAlias, string $offerAlias): string
    {
        if (!VisitorLogService::ordersHaveVisitorSessionColumn()) {
            return 'FALSE';
        }

        return 'EXISTS (
            SELECT 1
            FROM visitor_logs vl
            WHERE vl.session_id = ' . $orderAlias . '.visitor_session_id
              AND btrim(' . $orderAlias . '.visitor_session_id) <> \'\'
              AND vl.created_at <= ' . $orderAlias . '.created_at + interval \'5 minutes\'
              AND vl.created_at >= ' . $orderAlias . '.created_at - interval \'30 days\'
              AND vl.action IN (\'add_to_cart\', \'page_view\', \'product_view\', \'order_placed\')
              AND (
                (vl.details_ar LIKE \'{%\' AND vl.details_ar::jsonb->>\'offer_slug\' = ' . $offerAlias . '.slug)
                OR vl.details_ar ILIKE \'%"offer_slug":"\' || ' . $offerAlias . '.slug || \'"%\'
                OR vl.referer ILIKE \'%offer=\' || ' . $offerAlias . '.slug || \'%\'
              )
        )';
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

    private static bool $backfillRan = false;

    /**
     * @return array{sessions_filled: int, sql_linked: int, php_linked: int, batches: int}
     */
    public static function runFullOrderOfferBackfill(int $days = 365, int $maxBatches = 40): array
    {
        $days = max(1, min(3650, $days));
        $maxBatches = max(1, min(200, $maxBatches));
        self::$backfillRan = false;
        $sessionsFilled = VisitorLogService::backfillOrdersVisitorSessionFromLogs($days);
        $sqlLinked = self::bulkPersistOfferLinksFromLogs($days);
        $phpLinked = 0;
        $batches = 0;
        for ($i = 0; $i < $maxBatches; $i++) {
            self::$backfillRan = false;
            self::backfillRecentOrderAttribution($days, 2500);
            $batches++;
            $delta = self::$lastBackfillLinked;
            $phpLinked += $delta;
            if ($delta === 0) {
                break;
            }
        }

        return [
            'sessions_filled' => $sessionsFilled,
            'sql_linked' => $sqlLinked,
            'php_linked' => $phpLinked,
            'batches' => $batches,
        ];
    }

    private static int $lastBackfillLinked = 0;

  /** Bulk UPDATE order_items from order_placed logs + explicit offer products. */
    public static function bulkPersistOfferLinksFromLogs(int $days = 365): int
    {
        $days = max(1, min(3650, $days));
        try {
            $stmt = Database::pdo()->prepare(
                'UPDATE order_items oi
                 SET special_offer_id = so.id,
                     original_sale_price_sp = COALESCE(oi.original_sale_price_sp, oi.sale_price_sp),
                     original_sale_price_usd = COALESCE(oi.original_sale_price_usd, oi.sale_price_usd)
                 FROM orders o
                 INNER JOIN special_offers so ON o.created_at >= so.starts_at
                    AND (so.ends_at IS NULL OR o.created_at < so.ends_at)
                 INNER JOIN visitor_logs vop ON vop.action = \'order_placed\'
                    AND vop.created_at <= o.created_at + interval \'6 hours\'
                    AND vop.created_at >= o.created_at - interval \'1 hour\'
                    AND (
                      (vop.details_ar LIKE \'{%\' AND (
                        vop.details_ar::jsonb->>\'order_id\' = o.id::text
                        OR vop.details_ar::jsonb->>\'order_number\' = o.order_number
                      ))
                      OR vop.details_ar ILIKE \'%\' || o.order_number || \'%\'
                    )
                    AND (
                      (vop.details_ar LIKE \'{%\' AND vop.details_ar::jsonb->>\'offer_slug\' = so.slug)
                      OR vop.details_ar ILIKE \'%"offer_slug":"\' || so.slug || \'"%\'
                    )
                 WHERE oi.order_id = o.id
                   AND oi.special_offer_id IS NULL
                   AND o.status NOT IN (\'cancelled\', \'rejected\')
                   AND o.created_at >= NOW() - make_interval(days => :days)
                   AND EXISTS (
                     SELECT 1 FROM special_offer_products sop
                     WHERE sop.offer_id = so.id
                       AND lower(trim(sop.material_guid::text)) = lower(trim(oi.material_guid::text))
                   )'
            );
            $stmt->execute(['days' => $days]);

            return $stmt->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function backfillRecentOrderAttribution(int $days, int $batch = 2500): void
    {
        if (self::$backfillRan) {
            return;
        }
        self::$backfillRan = true;
        self::$lastBackfillLinked = 0;

        $days = max(1, min(3650, $days));
        $batch = max(100, min(5000, $batch));

        try {
            $pdo = Database::pdo();
            $sessionCol = VisitorLogService::ordersHaveVisitorSessionColumn()
                ? 'o.visitor_session_id'
                : 'NULL::text';
            $stmt = $pdo->prepare(
                'SELECT oi.id::text AS item_id,
                        o.id::text AS order_id,
                        o.order_number,
                        oi.material_guid::text AS material_guid,
                        oi.sale_price_sp, oi.sale_price_usd,
                        oi.original_sale_price_sp, oi.original_sale_price_usd,
                        oi.special_offer_id::text AS special_offer_id,
                        o.created_at::text AS ordered_at,
                        ' . $sessionCol . ' AS visitor_session_id
                 FROM order_items oi
                 INNER JOIN orders o ON o.id = oi.order_id
                 WHERE oi.special_offer_id IS NULL
                   AND o.status NOT IN (\'cancelled\', \'rejected\')
                   AND o.created_at >= NOW() - make_interval(days => :days)
                 ORDER BY o.created_at DESC
                 LIMIT ' . $batch
            );
            $stmt->execute(['days' => $days]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows === []) {
                return;
            }

            $update = $pdo->prepare(
                'UPDATE order_items SET
                    special_offer_id = :offer_id,
                    original_sale_price_sp = COALESCE(original_sale_price_sp, :orig_sp),
                    original_sale_price_usd = COALESCE(original_sale_price_usd, :orig_usd)
                 WHERE id = :id'
            );

            foreach ($rows as $row) {
                $itemId = trim((string) ($row['item_id'] ?? ''));
                $guid = trim((string) ($row['material_guid'] ?? ''));
                $orderedAt = trim((string) ($row['ordered_at'] ?? ''));
                if ($itemId === '' || $guid === '' || $orderedAt === '') {
                    continue;
                }

                $line = [
                    'sale_price_sp' => (float) ($row['sale_price_sp'] ?? 0),
                    'sale_price_usd' => (float) ($row['sale_price_usd'] ?? 0),
                    'original_sale_price_sp' => isset($row['original_sale_price_sp']) ? (float) $row['original_sale_price_sp'] : null,
                    'original_sale_price_usd' => isset($row['original_sale_price_usd']) ? (float) $row['original_sale_price_usd'] : null,
                ];
                $sessionId = trim((string) ($row['visitor_session_id'] ?? ''));
                $orderId = trim((string) ($row['order_id'] ?? ''));
                $orderNumber = trim((string) ($row['order_number'] ?? ''));
                if ($sessionId === '') {
                    $sessionId = VisitorLogService::sessionIdForOrder($orderId, $orderNumber) ?? '';
                }
                $storeSlug = VisitorLogService::offerSlugForOrder($orderId, $orderNumber, $sessionId, $orderedAt);
                $enriched = ShareCartService::attributeOfferOnLine(
                    array_merge($line, [
                        'material_guid' => $guid,
                        'quantity' => 1.0,
                    ]),
                    $storeSlug !== '' && $storeSlug !== null ? $storeSlug : null,
                    $sessionId !== '' ? $sessionId : null,
                    $orderedAt
                );
                $offerId = trim((string) ($enriched['special_offer_id'] ?? ''));
                if ($offerId === '') {
                    $offerId = SpecialOfferService::resolveOfferIdForOrderLine($guid, $line, $orderedAt) ?? '';
                }
                if ($offerId === '') {
                    continue;
                }

                $origSp = isset($enriched['original_sale_price_sp'])
                    ? (float) $enriched['original_sale_price_sp']
                    : ($line['original_sale_price_sp'] ?? null);
                $origUsd = isset($enriched['original_sale_price_usd'])
                    ? (float) $enriched['original_sale_price_usd']
                    : ($line['original_sale_price_usd'] ?? null);

                $update->execute([
                    'id' => $itemId,
                    'offer_id' => $offerId,
                    'orig_sp' => $origSp,
                    'orig_usd' => $origUsd,
                ]);
                self::$lastBackfillLinked += $update->rowCount();
            }
        } catch (\Throwable) {
            // optional columns / missing tables on older installs
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function diagnoseOrderForOffer(string $orderNumber, string $offerSlug): array
    {
        $orderNumber = trim($orderNumber);
        $offerSlug = trim($offerSlug);
        $out = [
            'ok' => false,
            'order_number' => $orderNumber,
            'offer_slug' => $offerSlug,
            'issues' => [],
            'lines' => [],
        ];
        if ($orderNumber === '' || $offerSlug === '') {
            $out['issues'][] = 'order_number or offer_slug missing';

            return $out;
        }

        try {
            $orderStmt = Database::pdo()->prepare(
                'SELECT id::text AS id, order_number, status, created_at::text AS created_at,
                        visitor_session_id
                 FROM orders
                 WHERE order_number = :num
                 LIMIT 1'
            );
            $orderStmt->execute(['num' => $orderNumber]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            if ($order === false) {
                $out['issues'][] = 'order not found';

                return $out;
            }

            $offerStmt = Database::pdo()->prepare(
                'SELECT id::text AS id, slug, title_ar, is_active, starts_at::text AS starts_at, ends_at::text AS ends_at
                 FROM special_offers
                 WHERE slug = :slug
                 LIMIT 1'
            );
            $offerStmt->execute(['slug' => $offerSlug]);
            $offer = $offerStmt->fetch(PDO::FETCH_ASSOC);
            if ($offer === false) {
                $out['issues'][] = 'offer slug not found';

                return $out;
            }

            $orderId = (string) ($order['id'] ?? '');
            $sessionId = trim((string) ($order['visitor_session_id'] ?? ''));
            $orderedAt = (string) ($order['created_at'] ?? '');
            if ($sessionId === '') {
                $sessionId = VisitorLogService::sessionIdForOrder($orderId, $orderNumber) ?? '';
                $out['issues'][] = 'orders.visitor_session_id was empty'
                    . ($sessionId !== '' ? ' (recovered from visitor_logs)' : ' (no order_placed log)');
            }

            $logSlug = VisitorLogService::offerSlugForOrder($orderId, $orderNumber, $sessionId, $orderedAt);
            $out['order'] = $order;
            $out['offer'] = $offer;
            $out['session_id'] = $sessionId;
            $out['dominant_offer_slug_in_session'] = $logSlug;

            $lines = Database::pdo()->prepare(
                'SELECT id::text AS id, material_guid::text AS material_guid,
                        special_offer_id::text AS special_offer_id,
                        sale_price_sp, sale_price_usd,
                        original_sale_price_sp, original_sale_price_usd
                 FROM order_items WHERE order_id = :oid'
            );
            $lines->execute(['oid' => $orderId]);
            foreach ($lines->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $expectedId = (string) ($offer['id'] ?? '');
                $linkedId = trim((string) ($row['special_offer_id'] ?? ''));
                $out['lines'][] = array_merge($row, [
                    'matches_offer' => $linkedId !== '' && $linkedId === $expectedId,
                    'needs_link' => $linkedId === '' || $linkedId !== $expectedId,
                ]);
            }

            $offerId = (string) ($offer['id'] ?? '');
            $linkedCount = count(array_filter(
                $out['lines'],
                static fn (array $l): bool => trim((string) ($l['special_offer_id'] ?? '')) === $offerId
            ));
            $out['linked_line_count'] = $linkedCount;
            $out['ok'] = $linkedCount > 0;

            return $out;
        } catch (\Throwable $e) {
            $out['issues'][] = $e->getMessage();

            return $out;
        }
    }

    /**
     * @return array{ok: bool, message: string, updated: int, diagnose?: array<string, mixed>}
     */
    public static function repairOrderForOfferSlug(string $orderNumber, string $offerSlug, bool $apply = true): array
    {
        $diagnose = self::diagnoseOrderForOffer($orderNumber, $offerSlug);
        if (($diagnose['order'] ?? null) === null || ($diagnose['offer'] ?? null) === null) {
            return [
                'ok' => false,
                'message' => implode('; ', $diagnose['issues'] ?? ['not found']),
                'updated' => 0,
                'diagnose' => $diagnose,
            ];
        }

        $order = $diagnose['order'];
        $offerSlug = trim($offerSlug);
        $orderedAt = (string) ($order['created_at'] ?? '');
        $orderId = (string) ($order['id'] ?? '');
        $sessionId = (string) ($diagnose['session_id'] ?? '');

        $offer = SpecialOfferService::activeOfferBySlug($offerSlug);
        if ($offer === null) {
            foreach (SpecialOfferService::offersForOrderInstant($orderedAt) as $candidate) {
                if (strcasecmp(trim((string) ($candidate['slug'] ?? '')), $offerSlug) === 0) {
                    $offer = $candidate;
                    break;
                }
            }
        }
        if ($offer === null) {
            return [
                'ok' => false,
                'message' => 'تعذر تحميل بيانات العرض النشطة في وقت الطلب.',
                'updated' => 0,
                'diagnose' => $diagnose,
            ];
        }

        if ($apply && $sessionId !== '' && trim((string) ($order['visitor_session_id'] ?? '')) === '') {
            try {
                Database::pdo()->prepare(
                    'UPDATE orders SET visitor_session_id = :sid WHERE id = :oid AND (visitor_session_id IS NULL OR btrim(visitor_session_id) = \'\')'
                )->execute(['sid' => substr($sessionId, 0, 120), 'oid' => $orderId]);
            } catch (\Throwable) {
            }
        }

        $updated = 0;
        $update = Database::pdo()->prepare(
            'UPDATE order_items SET
                special_offer_id = :offer_id,
                original_sale_price_sp = COALESCE(original_sale_price_sp, :orig_sp),
                original_sale_price_usd = COALESCE(original_sale_price_usd, :orig_usd)
             WHERE id = :id'
        );

        foreach ($diagnose['lines'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $guid = trim((string) ($row['material_guid'] ?? ''));
            $itemId = trim((string) ($row['id'] ?? ''));
            if ($guid === '' || $itemId === '') {
                continue;
            }

            $line = [
                'material_guid' => $guid,
                'sale_price_sp' => (float) ($row['sale_price_sp'] ?? 0),
                'sale_price_usd' => (float) ($row['sale_price_usd'] ?? 0),
                'original_sale_price_sp' => isset($row['original_sale_price_sp']) ? (float) $row['original_sale_price_sp'] : null,
                'original_sale_price_usd' => isset($row['original_sale_price_usd']) ? (float) $row['original_sale_price_usd'] : null,
                'quantity' => 1.0,
            ];

            $enriched = ShareCartService::attributeOfferOnLine($line, $offerSlug, $sessionId !== '' ? $sessionId : null, $orderedAt);
            $offerId = trim((string) ($enriched['special_offer_id'] ?? ''));
            if ($offerId === '') {
                $offerId = (string) ($offer['id'] ?? '');
            }
            if ($offerId === '') {
                continue;
            }

            if (!$apply) {
                $updated++;
                continue;
            }

            $update->execute([
                'id' => $itemId,
                'offer_id' => $offerId,
                'orig_sp' => $enriched['original_sale_price_sp'] ?? $line['original_sale_price_sp'],
                'orig_usd' => $enriched['original_sale_price_usd'] ?? $line['original_sale_price_usd'],
            ]);
            $updated += $update->rowCount();
        }

        return [
            'ok' => $updated > 0,
            'message' => $apply
                ? ($updated > 0 ? 'تم ربط ' . $updated . ' سطراً بالعرض.' : 'لم يُربط أي سطر — راجع التشخيص.')
                : 'معاينة: ' . $updated . ' سطراً يمكن ربطها.',
            'updated' => $updated,
            'diagnose' => $diagnose,
        ];
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