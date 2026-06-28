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
            if ($change !== null) {
                $changes[] = $change;
            }

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

            ShareCartService::replaceLine($token, (string) $guid, $merged);
            $items[(string) $guid] = $merged;
        }

        return [
            'changes' => $changes,
            'items' => array_values($items),
        ];
    }

    /** @param array<string, mixed> $stored @param array<string, mixed> $current @return array<string, mixed>|null */
    public static function detectPriceChange(array $stored, array $current): ?array
    {
        $guid = trim((string) ($stored['material_guid'] ?? ''));
        if ($guid === '') {
            return null;
        }

        $oldSp = (float) ($stored['sale_price_sp'] ?? 0);
        $newSp = (float) ($current['sale_price_sp'] ?? 0);
        $oldUsd = (float) ($stored['sale_price_usd'] ?? 0);
        $newUsd = (float) ($current['sale_price_usd'] ?? 0);

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
