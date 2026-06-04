<?php

declare(strict_types=1);

namespace Portal\Services;

final class ShareCartService
{
    private const SESSION_KEY = 'share_carts';

    /** @return array<string, array<string, mixed>> */
    public static function items(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return [];
        }

        $items = $_SESSION[self::SESSION_KEY][$token]['items'] ?? [];
        return is_array($items) ? $items : [];
    }

    public static function itemCount(string $token): int
    {
        return count(self::items($token));
    }

    /** @return array{total_sp: float, total_usd: float} */
    public static function totals(string $token): array
    {
        $totalSp = 0.0;
        $totalUsd = 0.0;
        foreach (self::items($token) as $line) {
            $qty = max(0.0, (float) ($line['quantity'] ?? 0));
            $totalSp += $qty * (float) ($line['sale_price_sp'] ?? 0);
            $totalUsd += $qty * (float) ($line['sale_price_usd'] ?? 0);
        }

        return ['total_sp' => $totalSp, 'total_usd' => $totalUsd];
    }

    /** @param array<string, mixed> $line */
    public static function add(string $token, string $shareLinkId, array $line, float $quantity = 1.0): void
    {
        $token = trim($token);
        $shareLinkId = trim($shareLinkId);
        $materialGuid = trim((string) ($line['material_guid'] ?? ''));
        if ($token === '' || $shareLinkId === '' || $materialGuid === '') {
            return;
        }

        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        $quantity = max(0.01, $quantity);
        if (!isset($_SESSION[self::SESSION_KEY][$token]) || !is_array($_SESSION[self::SESSION_KEY][$token])) {
            $_SESSION[self::SESSION_KEY][$token] = [
                'share_link_id' => $shareLinkId,
                'items' => [],
            ];
        }

        $_SESSION[self::SESSION_KEY][$token]['share_link_id'] = $shareLinkId;
        $items = &$_SESSION[self::SESSION_KEY][$token]['items'];
        if (!is_array($items)) {
            $items = [];
        }

        if (isset($items[$materialGuid]) && is_array($items[$materialGuid])) {
            $items[$materialGuid]['quantity'] = (float) ($items[$materialGuid]['quantity'] ?? 0) + $quantity;
            return;
        }

        $line['material_guid'] = $materialGuid;
        $line['quantity'] = $quantity;
        $items[$materialGuid] = $line;
    }

    public static function updateQuantity(string $token, string $materialGuid, float $quantity): bool
    {
        $token = trim($token);
        $materialGuid = trim($materialGuid);
        if ($token === '' || $materialGuid === '') {
            return false;
        }

        $items = self::items($token);
        if (!isset($items[$materialGuid])) {
            return false;
        }

        if ($quantity <= 0) {
            unset($_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid]);
            return true;
        }

        $_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid]['quantity'] = $quantity;
        return true;
    }

    public static function remove(string $token, string $materialGuid): bool
    {
        $token = trim($token);
        $materialGuid = trim($materialGuid);
        if ($token === '' || $materialGuid === '' || !isset($_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid])) {
            return false;
        }

        unset($_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid]);
        return true;
    }

    public static function clear(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }

        unset($_SESSION[self::SESSION_KEY][$token]);
    }

    public static function shareLinkId(string $token): ?string
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $id = $_SESSION[self::SESSION_KEY][$token]['share_link_id'] ?? null;
        $id = is_string($id) ? trim($id) : '';

        return $id !== '' ? $id : null;
    }

    /** @param array<string, mixed> $apiItem */
    public static function lineFromApiItem(array $apiItem, bool $capturePrices): array
    {
        $materialGuid = trim((string) ($apiItem['materialGuid'] ?? $apiItem['MaterialGuid'] ?? ''));
        $imageGuid = trim((string) ($apiItem['productImageGuid'] ?? $apiItem['ProductImageGuid'] ?? ''));
        $imageUrl = $imageGuid !== '' ? '/api/image.php?id=' . rawurlencode($imageGuid) . '&thumb=1' : null;

        return [
            'material_guid' => $materialGuid,
            'material_code' => trim((string) ($apiItem['materialCode'] ?? $apiItem['MaterialCode'] ?? '')),
            'material_name_ar' => trim((string) ($apiItem['name'] ?? $apiItem['Name'] ?? 'مادة')),
            'pcs_per_box' => max(1, (int) ($apiItem['pcsPerBox'] ?? $apiItem['PcsPerBox'] ?? 1)),
            'sale_price_sp' => $capturePrices ? self::parseAmount($apiItem['unitSalePriceSyp'] ?? $apiItem['UnitSalePriceSyp'] ?? 0) : 0.0,
            'sale_price_usd' => $capturePrices ? self::parseAmount($apiItem['unitSalePriceUsd'] ?? $apiItem['UnitSalePriceUsd'] ?? 0) : 0.0,
            'image_url' => $imageUrl,
        ];
    }

    public static function parseAmount(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        if (is_array($value)) {
            foreach (['amount', 'value', 'raw'] as $key) {
                if (isset($value[$key]) && is_numeric((string) $value[$key])) {
                    return (float) $value[$key];
                }
            }
        }

        return 0.0;
    }
}
