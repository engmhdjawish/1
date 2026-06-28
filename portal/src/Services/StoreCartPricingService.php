<?php

declare(strict_types=1);

namespace Portal\Services;

final class StoreCartPricingService
{
    private const PRICE_EPSILON = 0.009;

    /** @param array<string, mixed> $display */
    public static function rememberCartDisplayContext(array $display, array $input = []): void
    {
        $section = trim((string) ($input['store_section'] ?? ''));
        $offer = trim((string) ($input['store_offer'] ?? ''));
        if ($section === '' && $offer === '') {
            return;
        }

        if (!isset($_SESSION['store_cart_context']) || !is_array($_SESSION['store_cart_context'])) {
            $_SESSION['store_cart_context'] = [];
        }

        $_SESSION['store_cart_context']['section'] = $section;
        $_SESSION['store_cart_context']['offer'] = $offer;
        $_SESSION['store_cart_context']['show_price'] = (bool) ($display['show_price'] ?? false);
    }

    /** @param array<string, mixed> $display */
    public static function customerShowsPrices(array $display): bool
    {
        if ((bool) ($display['show_price'] ?? false)) {
            return true;
        }

        $ctx = $_SESSION['store_cart_context'] ?? null;

        return is_array($ctx) && !empty($ctx['show_price']);
    }

    /** @param list<array<string, mixed>> $items */
    public static function cartShowsAnyLinePrices(array $items, array $display): bool
    {
        $fallback = self::customerShowsPrices($display);
        foreach ($items as $line) {
            if (!is_array($line)) {
                continue;
            }
            if (self::lineHasDisplayPrice($line, $fallback)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $display */
    public static function contextShowsPrices(array $display): bool
    {
        return (bool) ($display['show_price'] ?? false);
    }

    /** @param array<string, mixed> $line */
    public static function lineHasDisplayPrice(array $line, bool $customerShowsPrices): bool
    {
        $lineAllows = array_key_exists('customer_show_price', $line)
            ? (bool) $line['customer_show_price']
            : $customerShowsPrices;
        if (!$lineAllows) {
            return false;
        }

        $norm = ShareCartService::normalizeLine($line);
        $packSp = (float) ($norm['sale_price_sp'] ?? 0);
        $packUsd = (float) ($norm['sale_price_usd'] ?? 0);
        if ($packSp > self::PRICE_EPSILON || $packUsd > self::PRICE_EPSILON) {
            return true;
        }

        $packaging = max(1.0, (float) ($norm['packaging'] ?? $norm['pcs_per_box'] ?? 1));
        $unitSp = (float) ($norm['unit_sale_price_sp'] ?? 0);
        $unitUsd = (float) ($norm['unit_sale_price_usd'] ?? 0);

        return $unitSp > self::PRICE_EPSILON || $unitUsd > self::PRICE_EPSILON;
    }

    /** @return array{total_sp: float, total_usd: float} */
    public static function displayTotals(string $token, bool $customerShowsPrices): array
    {
        $totalSp = 0.0;
        $totalUsd = 0.0;
        foreach (ShareCartService::items($token) as $line) {
            if (!self::lineHasDisplayPrice($line, $customerShowsPrices)) {
                continue;
            }
            $norm = ShareCartService::normalizeLine($line);
            $qty = max(0.0, (float) ($norm['quantity'] ?? 0));
            $totalSp += $qty * (float) ($norm['sale_price_sp'] ?? 0);
            $totalUsd += $qty * (float) ($norm['sale_price_usd'] ?? 0);
        }

        return ['total_sp' => $totalSp, 'total_usd' => $totalUsd];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{
     *   priced: list<array<string, mixed>>,
     *   unpriced: list<array<string, mixed>>,
     *   has_mixed: bool
     * }
     */
    public static function partitionItems(array $items, bool $customerShowsPrices): array
    {
        $priced = [];
        $unpriced = [];
        foreach ($items as $line) {
            if (!is_array($line)) {
                continue;
            }
            if (self::lineHasDisplayPrice($line, $customerShowsPrices)) {
                $priced[] = $line;
            } else {
                $unpriced[] = $line;
            }
        }

        return [
            'priced' => $priced,
            'unpriced' => $unpriced,
            'has_mixed' => $priced !== [] && $unpriced !== [],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public static function lineFromRequest(array $input): array
    {
        $guid = trim((string) ($input['material_guid'] ?? ''));
        if ($guid === '') {
            return ShareCartService::lineFromForm($input, false);
        }

        $authoritative = self::authoritativeLineForGuid($guid);
        if ($authoritative === null) {
            return ShareCartService::lineFromForm($input, false);
        }

        $line = $authoritative;
        $name = trim((string) ($input['material_name_ar'] ?? ''));
        if ($name !== '') {
            $line['material_name_ar'] = $name;
        }
        $code = trim((string) ($input['material_code'] ?? ''));
        if ($code !== '') {
            $line['material_code'] = $code;
        }
        $imageUrl = trim((string) ($input['image_url'] ?? ''));
        if ($imageUrl !== '') {
            $line['image_url'] = $imageUrl;
        }

        return ShareCartService::normalizeLine($line);
    }

    /**
     * @return array{
     *   changes: list<array<string, mixed>>,
     *   items: list<array<string, mixed>>
     * }
     */
    public static function repriceCart(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['changes' => [], 'items' => []];
        }

        $changes = [];
        $items = ShareCartService::items($token);
        foreach ($items as $guid => $line) {
            $current = self::authoritativeLineForGuid((string) $guid);
            if ($current === null) {
                continue;
            }

            $change = self::detectPriceChange($line, $current);

            $merged = ShareCartService::normalizeLine(array_merge($line, [
                'unit_sale_price_sp' => $current['unit_sale_price_sp'] ?? 0,
                'unit_sale_price_usd' => $current['unit_sale_price_usd'] ?? 0,
                'sale_price_sp' => $current['sale_price_sp'] ?? 0,
                'sale_price_usd' => $current['sale_price_usd'] ?? 0,
                'original_unit_sale_price_sp' => $current['original_unit_sale_price_sp'] ?? null,
                'original_unit_sale_price_usd' => $current['original_unit_sale_price_usd'] ?? null,
                'original_sale_price_sp' => $current['original_sale_price_sp'] ?? null,
                'original_sale_price_usd' => $current['original_sale_price_usd'] ?? null,
                'has_offer' => $current['has_offer'] ?? false,
                'offer_badge' => $current['offer_badge'] ?? null,
                'offer_title_ar' => $current['offer_title_ar'] ?? null,
                'special_offer_id' => $current['special_offer_id'] ?? null,
            ]));

            if ($change !== null) {
                $changes[] = $change;
                $merged['price_change'] = $change;
            } else {
                unset($merged['price_change']);
            }

            $merged['price_snapshot_sp'] = (float) ($line['price_snapshot_sp'] ?? $merged['sale_price_sp'] ?? 0);
            $merged['price_snapshot_usd'] = (float) ($line['price_snapshot_usd'] ?? $merged['sale_price_usd'] ?? 0);

            ShareCartService::replaceLine($token, (string) $guid, $merged);
            $items[(string) $guid] = $merged;
        }

        return [
            'changes' => $changes,
            'items' => array_values($items),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function pendingPriceChanges(string $token): array
    {
        $changes = [];
        foreach (ShareCartService::items($token) as $line) {
            if (!is_array($line['price_change'] ?? null)) {
                continue;
            }
            $changes[] = $line['price_change'];
        }

        return $changes;
    }

    public static function clearPriceChangeNotices(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }

        foreach (ShareCartService::items($token) as $guid => $line) {
            if (!isset($line['price_change'])) {
                continue;
            }
            unset($line['price_change']);
            ShareCartService::replaceLine($token, (string) $guid, $line);
        }
    }

    /** @param array<string, mixed> $stored @param array<string, mixed> $current @return array<string, mixed>|null */
    public static function detectPriceChange(array $stored, array $current): ?array
    {
        $guid = trim((string) ($stored['material_guid'] ?? ''));
        if ($guid === '') {
            return null;
        }

        $storedNorm = ShareCartService::normalizeLine($stored);
        $currentNorm = ShareCartService::normalizeLine($current);
        $snapshotSp = (float) ($stored['price_snapshot_sp'] ?? 0);
        $snapshotUsd = (float) ($stored['price_snapshot_usd'] ?? 0);
        $oldSp = $snapshotSp > 0 ? $snapshotSp : (float) ($storedNorm['sale_price_sp'] ?? 0);
        $oldUsd = $snapshotUsd > 0 ? $snapshotUsd : (float) ($storedNorm['sale_price_usd'] ?? 0);
        $newSp = (float) ($currentNorm['sale_price_sp'] ?? 0);
        $newUsd = (float) ($currentNorm['sale_price_usd'] ?? 0);

        if ($oldSp <= 0 && $newSp <= 0 && $oldUsd <= 0 && $newUsd <= 0) {
            return null;
        }

        $directionSp = self::priceDirection($oldSp, $newSp);
        $directionUsd = self::priceDirection($oldUsd, $newUsd);
        if ($directionSp === null && $directionUsd === null) {
            return null;
        }

        return [
            'material_guid' => $guid,
            'material_name_ar' => (string) ($stored['material_name_ar'] ?? ''),
            'direction_sp' => $directionSp,
            'direction_usd' => $directionUsd,
            'old_sale_price_sp' => $oldSp,
            'new_sale_price_sp' => $newSp,
            'old_sale_price_usd' => $oldUsd,
            'new_sale_price_usd' => $newUsd,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function authoritativeLineForGuid(string $guid): ?array
    {
        $guid = trim($guid);
        if ($guid === '') {
            return null;
        }

        $product = StoreCatalogService::findMaterial($guid);
        if ($product === null) {
            return null;
        }

        $line = ShareCartService::lineFromApiItem($product, true);

        return ShareCartService::enrichLineWithOffer($line);
    }

    private static function priceDirection(float $old, float $new): ?string
    {
        if ($old <= 0 && $new <= 0) {
            return null;
        }
        if ($old <= 0 && $new > 0) {
            return 'up';
        }
        if ($new <= 0 && $old > 0) {
            return 'down';
        }
        if (abs($new - $old) <= self::PRICE_EPSILON) {
            return null;
        }

        return $new > $old ? 'up' : 'down';
    }
}
