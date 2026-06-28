<?php

declare(strict_types=1);

namespace Portal\Services;

final class ShareCartService
{
    private const SESSION_KEY = 'share_carts';
    private const UNAVAILABLE_KEY = 'unavailable_items';

    /** @return array<string, array<string, mixed>> */
    public static function items(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return [];
        }

        $items = $_SESSION[self::SESSION_KEY][$token]['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $guid => $line) {
            if (!is_array($line)) {
                continue;
            }
            $normalized[(string) $guid] = self::normalizeLine($line);
        }

        return $normalized;
    }

    public static function itemCount(string $token): int
    {
        return count(self::items($token));
    }

    public static function packageCount(string $token): float
    {
        $total = 0.0;
        foreach (self::items($token) as $line) {
            $total += max(0.0, (float) ($line['quantity'] ?? 0));
        }

        return round($total, 4);
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
    public static function add(string $token, string $shareLinkId, array $line, float $quantity = 1.0): array
    {
        $token = trim($token);
        $shareLinkId = trim($shareLinkId);
        $line = self::normalizeLine($line);
        $materialGuid = trim((string) ($line['material_guid'] ?? ''));
        $isStoreCart = $token === StoreCartService::TOKEN;
        if ($token === '' || $materialGuid === '' || (!$isStoreCart && $shareLinkId === '')) {
            return ['ok' => false, 'message' => 'بيانات غير صالحة.', 'quantity' => 0.0];
        }

        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        $existingQty = 0.0;
        if (isset($_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid])) {
            $existingQty = (float) ($_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid]['quantity'] ?? 0);
        }
        $quantity = max(0.0, round((float) $quantity, 4));
        if ($quantity <= 0) {
            return ['ok' => false, 'message' => 'الكمية غير صالحة.', 'quantity' => 0.0];
        }
        $targetQty = $existingQty > 0 ? $existingQty + $quantity : $quantity;
        $validation = SpecialOfferService::validatePackageQuantity($materialGuid, $targetQty, null);
        if (!$validation['ok']) {
            return $validation;
        }
        $targetQty = $validation['quantity'];

        $stockCheck = StockReservationService::validateCartLine(
            array_merge($line, ['quantity' => $targetQty])
        );
        if (!$stockCheck['ok']) {
            if ($stockCheck['capped_packages'] > 0) {
                $targetQty = $stockCheck['capped_packages'];
            } elseif ($existingQty > 0) {
                self::moveToUnavailable($token, $materialGuid, $stockCheck['message']);

                return [
                    'ok' => false,
                    'message' => $stockCheck['message'],
                    'quantity' => 0.0,
                    'moved_unavailable' => true,
                ];
            } else {
                return [
                    'ok' => false,
                    'message' => $stockCheck['message'],
                    'quantity' => 0.0,
                ];
            }
        }

        $quantity = $existingQty > 0 ? ($targetQty - $existingQty) : $targetQty;
        if ($quantity <= 0) {
            return $validation;
        }

        if (!isset($_SESSION[self::SESSION_KEY][$token]) || !is_array($_SESSION[self::SESSION_KEY][$token])) {
            $_SESSION[self::SESSION_KEY][$token] = [
                'share_link_id' => $shareLinkId !== '' ? $shareLinkId : null,
                'items' => [],
            ];
        }

        if ($shareLinkId !== '') {
            $_SESSION[self::SESSION_KEY][$token]['share_link_id'] = $shareLinkId;
        }
        $items = &$_SESSION[self::SESSION_KEY][$token]['items'];
        if (!is_array($items)) {
            $items = [];
        }

        if (isset($items[$materialGuid]) && is_array($items[$materialGuid])) {
            $items[$materialGuid] = self::normalizeLine(array_merge($items[$materialGuid], $line));
            $items[$materialGuid]['quantity'] = (float) ($items[$materialGuid]['quantity'] ?? 0) + $quantity;

            return ['ok' => true, 'message' => '', 'quantity' => (float) $items[$materialGuid]['quantity']];
        }

        $line['material_guid'] = $materialGuid;
        $line['quantity'] = $quantity;
        if (!isset($line['price_snapshot_sp']) && !isset($line['price_snapshot_usd'])) {
            $line['price_snapshot_sp'] = (float) ($line['sale_price_sp'] ?? 0);
            $line['price_snapshot_usd'] = (float) ($line['sale_price_usd'] ?? 0);
        }
        $items[$materialGuid] = $line;

        return ['ok' => true, 'message' => '', 'quantity' => $quantity];
    }

    /** @param array<string, mixed> $line */
    public static function replaceLine(string $token, string $materialGuid, array $line): void
    {
        $token = trim($token);
        $materialGuid = trim($materialGuid);
        if ($token === '' || $materialGuid === '') {
            return;
        }

        if (!isset($_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid])) {
            return;
        }

        $line = self::normalizeLine($line);
        $line['material_guid'] = $materialGuid;
        $line['quantity'] = (float) ($_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid]['quantity'] ?? $line['quantity'] ?? 0);
        $_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid] = $line;
    }

    /** @return array{ok: bool, message: string, quantity: float} */
    public static function updateQuantity(string $token, string $materialGuid, float $quantity): array
    {
        $token = trim($token);
        $materialGuid = trim($materialGuid);
        if ($token === '' || $materialGuid === '') {
            return ['ok' => false, 'message' => 'بيانات غير صالحة.', 'quantity' => 0.0];
        }

        $items = self::items($token);
        if (!isset($items[$materialGuid])) {
            return ['ok' => false, 'message' => 'المادة غير موجودة في السلة.', 'quantity' => 0.0];
        }

        if ($quantity <= 0) {
            unset($_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid]);

            return ['ok' => true, 'message' => '', 'quantity' => 0.0];
        }

        $validation = SpecialOfferService::validatePackageQuantity($materialGuid, $quantity, null);
        if (!$validation['ok']) {
            return $validation;
        }
        $quantity = $validation['quantity'];

        $stockCheck = StockReservationService::validateCartLine(
            array_merge($items[$materialGuid], ['quantity' => $quantity])
        );
        if (!$stockCheck['ok']) {
            if ($stockCheck['capped_packages'] <= 0) {
                self::moveToUnavailable($token, $materialGuid, $stockCheck['message']);

                return [
                    'ok' => false,
                    'message' => $stockCheck['message'],
                    'quantity' => 0.0,
                    'moved_unavailable' => true,
                ];
            }
            $quantity = $stockCheck['capped_packages'];
        }

        $_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid]['quantity'] = $quantity;
        $_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid] = self::normalizeLine(
            $_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid]
        );

        return [
            'ok' => true,
            'message' => $stockCheck['message'] !== '' ? $stockCheck['message'] : '',
            'quantity' => $quantity,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function unavailableItems(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return [];
        }

        $items = $_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $guid => $line) {
            if (!is_array($line)) {
                continue;
            }
            $normalized[(string) $guid] = self::normalizeLine($line);
        }

        return $normalized;
    }

    public static function moveToUnavailable(string $token, string $materialGuid, string $message): bool
    {
        $token = trim($token);
        $materialGuid = trim($materialGuid);
        if ($token === '' || $materialGuid === '' || !isset($_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid])) {
            return false;
        }

        $line = self::normalizeLine((array) $_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid]);
        $line['stock_message'] = trim($message) !== '' ? trim($message) : 'نفدت الكمية المتاحة لهذا الصنف.';
        $line['stock_unavailable_at'] = date('c');

        if (!isset($_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY]) || !is_array($_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY])) {
            $_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY] = [];
        }

        $_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY][$materialGuid] = $line;
        unset($_SESSION[self::SESSION_KEY][$token]['items'][$materialGuid]);

        return true;
    }

    public static function removeUnavailable(string $token, string $materialGuid): bool
    {
        $token = trim($token);
        $materialGuid = trim($materialGuid);
        if ($token === '' || $materialGuid === '' || !isset($_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY][$materialGuid])) {
            return false;
        }

        unset($_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY][$materialGuid]);

        return true;
    }

    /**
     * Re-check cart lines against warehouse minus open orders.
     *
     * @return array{moved: list<string>, notices: list<string>}
     */
    public static function reconcileStock(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['moved' => [], 'notices' => []];
        }

        $moved = [];
        $notices = [];
        foreach (self::items($token) as $guid => $line) {
            $check = StockReservationService::validateCartLine($line);
            if ($check['available_packages'] <= 0) {
                if (self::moveToUnavailable($token, $guid, $check['message'])) {
                    $moved[] = $guid;
                    $notices[] = $check['message'];
                }
                continue;
            }

            if ($check['capped_packages'] < (float) ($line['quantity'] ?? 0)) {
                $_SESSION[self::SESSION_KEY][$token]['items'][$guid]['quantity'] = $check['capped_packages'];
                $_SESSION[self::SESSION_KEY][$token]['items'][$guid] = self::normalizeLine(
                    $_SESSION[self::SESSION_KEY][$token]['items'][$guid]
                );
                $notices[] = $check['message'];
            }
        }

        return [
            'moved' => $moved,
            'notices' => array_values(array_unique(array_filter($notices, static fn (string $n): bool => trim($n) !== ''))),
        ];
    }

    /** @param list<string> $submittedGuids @param list<array<string, mixed>> $unavailableLines */
    public static function finalizeAfterSuccessfulOrder(string $token, array $submittedGuids, array $unavailableLines): void
    {
        $token = trim($token);
        if ($token === '' || !isset($_SESSION[self::SESSION_KEY][$token])) {
            return;
        }

        foreach ($submittedGuids as $guid) {
            $guid = trim((string) $guid);
            if ($guid !== '') {
                unset($_SESSION[self::SESSION_KEY][$token]['items'][$guid]);
            }
        }

        if ($unavailableLines !== []) {
            if (!isset($_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY]) || !is_array($_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY])) {
                $_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY] = [];
            }
            foreach ($unavailableLines as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $guid = trim((string) ($line['material_guid'] ?? ''));
                if ($guid === '') {
                    continue;
                }
                $line['stock_message'] = trim((string) ($line['stock_message'] ?? '')) ?: 'نفدت الكمية المتاحة لهذا الصنف.';
                $_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY][$guid] = self::normalizeLine($line);
                unset($_SESSION[self::SESSION_KEY][$token]['items'][$guid]);
            }
        }
    }

    /** @param list<array<string, mixed>> $unavailableLines */
    public static function stashUnavailableLines(string $token, array $unavailableLines): void
    {
        $token = trim($token);
        if ($token === '' || $unavailableLines === []) {
            return;
        }

        if (!isset($_SESSION[self::SESSION_KEY][$token])) {
            $_SESSION[self::SESSION_KEY][$token] = ['items' => []];
        }
        if (!isset($_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY]) || !is_array($_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY])) {
            $_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY] = [];
        }

        foreach ($unavailableLines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $guid = trim((string) ($line['material_guid'] ?? ''));
            if ($guid === '') {
                continue;
            }
            $line['stock_message'] = trim((string) ($line['stock_message'] ?? '')) ?: 'نفدت الكمية المتاحة لهذا الصنف.';
            $_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY][$guid] = self::normalizeLine($line);
            unset($_SESSION[self::SESSION_KEY][$token]['items'][$guid]);
        }
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

    public static function clearUnavailable(string $token): void
    {
        $token = trim($token);
        if ($token === '' || !isset($_SESSION[self::SESSION_KEY][$token])) {
            return;
        }

        unset($_SESSION[self::SESSION_KEY][$token][self::UNAVAILABLE_KEY]);
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

    /** التعبئة (Unit2Fact) — عدد وحدات الوحدة الأولى في الطرد */
    public static function packaging(array $material): float
    {
        foreach ([
            'packageConversionFactor',
            'PackageConversionFactor',
            'unit2Fact',
            'Unit2Fact',
            'packaging',
            'pcsPerBox',
            'PcsPerBox',
        ] as $key) {
            if (!array_key_exists($key, $material)) {
                continue;
            }
            $value = self::parseAmount($material[$key]);
            if ($value > 0) {
                return $value;
            }
        }

        return 1.0;
    }

    /** @deprecated Use packaging() */
    public static function packageFactor(array $material): float
    {
        return self::packaging($material);
    }

    public static function primaryUnitLabel(array $material): string
    {
        $label = trim((string) (
            $material['primaryUnit']
                ?? $material['PrimaryUnit']
                ?? $material['unity']
                ?? $material['Unity']
                ?? ''
        ));

        return $label !== '' ? $label : 'قطعة';
    }

    public static function packageUnitLabel(array $material): string
    {
        $label = trim((string) (
            $material['packageUnit']
                ?? $material['PackageUnit']
                ?? $material['unit2']
                ?? $material['Unit2']
                ?? ''
        ));

        return $label !== '' ? $label : 'طرد';
    }

    public static function unitSalePriceSp(array $material): float
    {
        return self::parseAmount($material['unitSalePriceSyp'] ?? $material['UnitSalePriceSyp'] ?? 0);
    }

    public static function unitSalePriceUsd(array $material): float
    {
        return self::parseAmount($material['unitSalePriceUsd'] ?? $material['UnitSalePriceUsd'] ?? 0);
    }

    public static function packageSalePriceSp(array $material): float
    {
        return self::unitSalePriceSp($material) * self::packaging($material);
    }

    public static function packageSalePriceUsd(array $material): float
    {
        return self::unitSalePriceUsd($material) * self::packaging($material);
    }

    /** @param array<string, mixed> $line */
    public static function normalizeLine(array $line): array
    {
        $packaging = (float) ($line['packaging'] ?? $line['package_factor'] ?? 0);
        if ($packaging <= 0) {
            $packaging = (float) max(1, (int) ($line['pcs_per_box'] ?? 0));
        }
        if ($packaging <= 0) {
            $packaging = 1.0;
        }

        $unitSp = (float) ($line['unit_sale_price_sp'] ?? 0);
        $unitUsd = (float) ($line['unit_sale_price_usd'] ?? 0);
        $saleSp = (float) ($line['sale_price_sp'] ?? 0);
        $saleUsd = (float) ($line['sale_price_usd'] ?? 0);

        if ($unitSp <= 0 && $saleSp > 0) {
            $unitSp = $saleSp / $packaging;
        }
        if ($unitUsd <= 0 && $saleUsd > 0) {
            $unitUsd = $saleUsd / $packaging;
        }

        $line['packaging'] = $packaging;
        $line['package_factor'] = $packaging;
        $line['pcs_per_box'] = max(1, (int) round($packaging));
        $line['unit_sale_price_sp'] = $unitSp;
        $line['unit_sale_price_usd'] = $unitUsd;
        $line['sale_price_sp'] = $unitSp * $packaging;
        $line['sale_price_usd'] = $unitUsd * $packaging;
        $line['package_unit'] = trim((string) ($line['package_unit'] ?? '')) !== '' ? (string) $line['package_unit'] : 'طرد';
        $line['primary_unit'] = trim((string) ($line['primary_unit'] ?? '')) !== '' ? (string) $line['primary_unit'] : 'قطعة';

        return $line;
    }

    /** @param array<string, mixed> $line */
    public static function enrichLineWithOffer(array $line): array
    {
        if (!empty($line['has_offer']) && trim((string) ($line['offer_badge'] ?? '')) !== '') {
            return $line;
        }

        $guid = trim((string) ($line['material_guid'] ?? ''));
        if ($guid === '') {
            return $line;
        }

        $product = StoreCatalogService::findMaterial($guid);
        if ($product === null) {
            return $line;
        }

        $overlay = SpecialOfferService::pricingOverlay($product);
        if (empty($overlay['has_offer']) || !is_array($overlay['offer'] ?? null)) {
            return $line;
        }

        $apiLine = self::lineFromApiItem($product, true);
        $enriched = SpecialOfferService::applyToCartLine($apiLine, $overlay['offer']);
        $enriched['quantity'] = (float) ($line['quantity'] ?? 1);
        if (!empty($line['image_url'])) {
            $enriched['image_url'] = (string) $line['image_url'];
        }
        if (trim((string) ($line['material_name_ar'] ?? '')) !== '') {
            $enriched['material_name_ar'] = (string) $line['material_name_ar'];
        }

        foreach (['customer_show_price', 'added_store_section', 'added_store_offer', 'price_snapshot_sp', 'price_snapshot_usd', 'price_change'] as $policyKey) {
            if (array_key_exists($policyKey, $line)) {
                $enriched[$policyKey] = $line[$policyKey];
            }
        }

        return $enriched;
    }

    /** @param array<string, mixed> $apiItem */
    public static function lineFromApiItem(array $apiItem, bool $capturePrices): array
    {
        $materialGuid = trim((string) ($apiItem['materialGuid'] ?? $apiItem['MaterialGuid'] ?? ''));
        $imageGuid = trim((string) ($apiItem['productImageGuid'] ?? $apiItem['ProductImageGuid'] ?? ''));
        $imageUrl = $imageGuid !== '' ? '/api/image.php?id=' . rawurlencode($imageGuid) . '&thumb=1' : null;
        $packaging = self::packaging($apiItem);
        $unitSp = self::unitSalePriceSp($apiItem);
        $unitUsd = self::unitSalePriceUsd($apiItem);

        return self::normalizeLine([
            'material_guid' => $materialGuid,
            'material_code' => trim((string) ($apiItem['materialCode'] ?? $apiItem['MaterialCode'] ?? '')),
            'material_name_ar' => trim((string) ($apiItem['name'] ?? $apiItem['Name'] ?? 'مادة')),
            'primary_unit' => self::primaryUnitLabel($apiItem),
            'package_unit' => self::packageUnitLabel($apiItem),
            'packaging' => $packaging,
            'unit_sale_price_sp' => $capturePrices ? $unitSp : 0.0,
            'unit_sale_price_usd' => $capturePrices ? $unitUsd : 0.0,
            'image_url' => $imageUrl,
        ]);
    }

    /** @param array<string, mixed> $post */
    public static function lineFromForm(array $post, bool $capturePrices): array
    {
        $packaging = max(1.0, (float) ($post['packaging'] ?? $post['package_factor'] ?? $post['pcs_per_box'] ?? 1));
        $unitSp = self::parseAmount($post['unit_sale_price_sp'] ?? 0);
        $unitUsd = self::parseAmount($post['unit_sale_price_usd'] ?? 0);

        return self::normalizeLine([
            'material_guid' => trim((string) ($post['material_guid'] ?? '')),
            'material_code' => trim((string) ($post['material_code'] ?? '')),
            'material_name_ar' => trim((string) ($post['material_name_ar'] ?? 'مادة')) ?: 'مادة',
            'primary_unit' => trim((string) ($post['primary_unit'] ?? '')) ?: 'قطعة',
            'package_unit' => trim((string) ($post['package_unit'] ?? '')) ?: 'طرد',
            'packaging' => $packaging,
            'unit_sale_price_sp' => $capturePrices ? $unitSp : 0.0,
            'unit_sale_price_usd' => $capturePrices ? $unitUsd : 0.0,
            'image_url' => trim((string) ($post['image_url'] ?? '')) ?: null,
        ]);
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
