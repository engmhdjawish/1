<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

/**
 * Offer effectiveness: sales from order_items + light engagement from visitor_logs.
 */
final class SpecialOfferAnalyticsService
{
    /**
     * @param list<string> $offerIds
     * @return array<string, array{
     *   orders: int,
     *   lines: int,
     *   packages: float,
     *   revenue_syp: float,
     *   revenue_usd: float,
     *   discount_syp: float,
     *   discount_usd: float,
     *   materials: int,
     *   add_to_cart: int,
     *   page_views: int
     * }>
     */
    public static function summariesForOffers(array $offerIds): array
    {
        $offerIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): string => trim((string) $id), $offerIds),
            static fn (string $id): bool => $id !== ''
        )));
        $empty = [
            'orders' => 0,
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
        $out = [];
        foreach ($offerIds as $id) {
            $out[$id] = $empty;
        }
        if ($offerIds === []) {
            return $out;
        }

        $placeholders = [];
        $params = [];
        foreach ($offerIds as $i => $id) {
            $key = 'o' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $in = implode(', ', $placeholders);

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
            $out[$id] = array_merge($out[$id], [
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

        // Engagement: visitor_logs whose details JSON mentions offer slug/id (best-effort).
        $slugs = Database::pdo()->prepare(
            'SELECT id::text AS id, slug FROM special_offers WHERE id IN (' . $in . ')'
        );
        $slugs->execute($params);
        $slugById = [];
        foreach ($slugs->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $slugById[(string) $row['id']] = (string) $row['slug'];
        }

        foreach ($slugById as $id => $slug) {
            if ($slug === '') {
                continue;
            }
            try {
                $eng = Database::pdo()->prepare(
                    "SELECT
                        COUNT(*) FILTER (WHERE action IN ('page_view', 'product_view'))::int AS page_views,
                        COUNT(*) FILTER (WHERE action = 'add_to_cart')::int AS add_to_cart
                     FROM visitor_logs
                     WHERE created_at >= NOW() - INTERVAL '90 days'
                       AND (
                         details_ar ILIKE :slug_like
                         OR details_ar ILIKE :id_like
                         OR referer ILIKE :offer_q
                       )"
                );
                $eng->execute([
                    'slug_like' => '%"offer_slug":"' . $slug . '"%',
                    'id_like' => '%"offer_id":"' . $id . '"%',
                    'offer_q' => '%offer=' . $slug . '%',
                ]);
                $row = $eng->fetch(PDO::FETCH_ASSOC) ?: [];
                $out[$id]['page_views'] = (int) ($row['page_views'] ?? 0);
                $out[$id]['add_to_cart'] = (int) ($row['add_to_cart'] ?? 0);
            } catch (\Throwable) {
                // details_ar may be missing on older DBs — ignore engagement.
            }
        }

        return $out;
    }

    /**
     * @return array{
     *   summary: array<string, mixed>,
     *   materials: list<array<string, mixed>>
     * }
     */
    public static function detailForOffer(string $offerId): array
    {
        $offerId = trim($offerId);
        $summaries = self::summariesForOffers([$offerId]);
        $summary = $summaries[$offerId] ?? [
            'orders' => 0,
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

        $materials = [];
        if ($offerId !== '') {
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
                 WHERE oi.special_offer_id = :id
                   AND o.status NOT IN (\'cancelled\', \'rejected\')
                 GROUP BY oi.material_guid
                 ORDER BY packages DESC, revenue_syp DESC
                 LIMIT 48'
            );
            $stmt->execute(['id' => $offerId]);
            $materials = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $conversion = $summary['page_views'] > 0
            ? round(($summary['orders'] / max(1, $summary['page_views'])) * 100, 2)
            : null;
        $summary['conversion_pct'] = $conversion;
        $summary['cart_rate_pct'] = $summary['page_views'] > 0
            ? round(($summary['add_to_cart'] / max(1, $summary['page_views'])) * 100, 2)
            : null;

        return ['summary' => $summary, 'materials' => $materials];
    }

    /**
     * Packages already sold under an offer for one material (non-cancelled orders).
     */
    public static function soldPackages(string $offerId, string $materialGuid): float
    {
        $offerId = trim($offerId);
        $materialGuid = strtolower(trim($materialGuid));
        if ($offerId === '' || $materialGuid === '') {
            return 0.0;
        }
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
    }
}
