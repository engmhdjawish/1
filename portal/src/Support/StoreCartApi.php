<?php

declare(strict_types=1);

namespace Portal\Support;

use Portal\Auth\CustomerSession;
use Portal\Services\AmineAvailabilityService;
use Portal\Services\OrderService;
use Portal\Services\ShareCartService;
use Portal\Services\SpecialOfferService;
use Portal\Services\StockReservationService;
use Portal\Services\StoreCartPricingService;
use Portal\Services\StoreCartService;
use Portal\Services\StoreCatalogService;
use Portal\Services\StorePolicyService;

final class StoreCartApi
{
    /** @return array<string, mixed> */
    public static function state(bool $reconcileStock = true): array
    {
        if ($reconcileStock) {
            $reconcile = ShareCartService::reconcileStock(StoreCartService::TOKEN);
            $notices = is_array($reconcile['notices'] ?? null) ? $reconcile['notices'] : [];
        } else {
            $notices = [];
        }

        return self::payload(
            null,
            true,
            $notices,
            'info',
            StoreCatalogService::displayOptionsForCartContext([], false),
            reprice: $reconcileStock
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function dispatch(string $action, array $input): array
    {
        $display = StoreCatalogService::displayOptionsForCartContext($input, false);
        if (!($display['allow_cart'] ?? false) && $action !== 'submit_order') {
            return self::payload('سياسة المتجر لا تسمح باستخدام السلة.', false, [], 'info', $display);
        }

        return match ($action) {
            'add', 'add_to_cart' => self::add($input, $display),
            'update' => self::update($input),
            'bump' => self::bump($input),
            'remove' => self::remove($input),
            'remove_unavailable' => self::removeUnavailable($input),
            'clear_unavailable' => self::clearUnavailable(),
            'clear' => self::clear(),
            'submit_order' => self::submitOrder($input, $display),
            default => self::payload('إجراء غير معروف.', false, [], 'info', $display),
        };
    }

    /** @param array<string, mixed> $display @param array<string, mixed> $input */
    private static function add(array $input, array $display): array
    {
        $quantity = max(0.0, round((float) ($input['quantity'] ?? 1), 4));
        if ($quantity <= 0) {
            return self::payload('الكمية غير صالحة.', false);
        }
        $materialGuid = trim((string) ($input['material_guid'] ?? ''));
        $product = null;
        $warehousePrimary = null;
        if ($materialGuid !== '') {
            $product = StoreCatalogService::findMaterial($materialGuid);
            if ($product !== null) {
                $warehousePrimary = StockReservationService::warehousePrimaryUnits($product);
            }
            $cartItems = StoreCartService::items();
            $currentQty = (float) ($cartItems[$materialGuid]['quantity'] ?? 0);
            $clientCheck = self::clientQuantityCheck($materialGuid, $quantity, $currentQty, $product, $warehousePrimary);
            if (!$clientCheck['ok']) {
                return self::payload($clientCheck['message'], false);
            }
        }

        StoreCartPricingService::rememberCartDisplayContext($display, $input);
        $line = StoreCartPricingService::lineFromRequest($input, $product);
        if ($line['material_guid'] === '') {
            return self::payload('تعذر تحديد المادة.', false);
        }
        $section = trim((string) ($input['store_section'] ?? ''));
        $offer = trim((string) ($input['store_offer'] ?? ''));
        $line['customer_show_price'] = ($section !== '' || $offer !== '')
            ? StoreCartPricingService::contextShowsPrices($display)
            : (bool) ($display['show_price'] ?? false);
        if ($section !== '') {
            $line['added_store_section'] = $section;
        } else {
            unset($line['added_store_section']);
        }
        if ($offer !== '') {
            $line['added_store_offer'] = $offer;
        } else {
            unset($line['added_store_offer']);
        }

        $result = StoreCartService::add($line, (float) $quantity, $warehousePrimary, $product !== null);
        if ($result['ok']) {
            $message = $result['message'] !== '' ? $result['message'] : 'تمت إضافة الطرد إلى السلة.';

            return self::payload($message, true, reprice: false);
        }

        if (!empty($result['moved_unavailable'])) {
            return self::payload(
                $result['message'] !== '' ? $result['message'] : 'نُقل الصنف إلى قائمة غير المتوفرة.',
                true,
                [],
                'warning',
                reprice: false
            );
        }

        return self::payload(
            $result['message'] !== '' ? $result['message'] : 'تعذر الإضافة إلى السلة.',
            false,
            reprice: false
        );
    }

    /** @param array<string, mixed> $input */
    private static function update(array $input, ?float $warehousePrimary = null): array
    {
        $materialGuid = trim((string) ($input['material_guid'] ?? ''));
        $quantity = max(0.0, round((float) ($input['quantity'] ?? 0), 4));
        if ($materialGuid === '') {
            return self::payload('تعذر تحديد المادة.', false);
        }

        $result = StoreCartService::updateQuantity($materialGuid, (float) $quantity, $warehousePrimary);
        if ($result['ok']) {
            $message = $quantity > 0 ? 'تم تحديث الكمية.' : 'تم حذف الصنف من السلة.';
            if ($result['message'] !== '') {
                $message = $result['message'];
            }

            return self::payload($message, true, reprice: false);
        }

        if (!empty($result['moved_unavailable'])) {
            return self::payload(
                $result['message'] !== '' ? $result['message'] : 'نُقل الصنف إلى قائمة غير المتوفرة.',
                true,
                [],
                'warning',
                reprice: false
            );
        }

        return self::payload(
            $result['message'] !== '' ? $result['message'] : 'تعذر تحديث الكمية.',
            false,
            reprice: false
        );
    }

    /** @param array<string, mixed> $input */
    private static function bump(array $input): array
    {
        $materialGuid = trim((string) ($input['material_guid'] ?? ''));
        $delta = (float) ($input['delta'] ?? 0);
        if ($materialGuid === '' || abs($delta) < 0.0001) {
            return self::payload('تعذر تحديث الكمية.', false);
        }

        $items = StoreCartService::items();
        $current = max(0.0, round((float) ($items[$materialGuid]['quantity'] ?? 0), 4));
        $next = max(0.0, round($current + $delta, 4));

        $product = StoreCatalogService::findMaterial($materialGuid);
        $warehousePrimary = $product !== null
            ? StockReservationService::warehousePrimaryUnits($product)
            : null;

        if ($delta > 0) {
            $clientCheck = self::clientQuantityCheck($materialGuid, $delta, $current, $product, $warehousePrimary);
            if (!$clientCheck['ok']) {
                return self::payload($clientCheck['message'], false);
            }
        }

        return self::update([
            'material_guid' => $materialGuid,
            'quantity' => $next,
        ], $warehousePrimary);
    }

    /** @param array<string, mixed> $input */
    private static function remove(array $input): array
    {
        $materialGuid = trim((string) ($input['material_guid'] ?? ''));
        if ($materialGuid === '' || !StoreCartService::remove($materialGuid)) {
            return self::payload('تعذر حذف الصنف.', false);
        }

        return self::payload('تم حذف الصنف من السلة.', true, reprice: false);
    }

    private static function clear(): array
    {
        StoreCartService::clear();

        return self::payload('تم تفريغ السلة.', true, reprice: false);
    }

    /** @param array<string, mixed> $input */
    private static function removeUnavailable(array $input): array
    {
        $materialGuid = trim((string) ($input['material_guid'] ?? ''));
        if ($materialGuid === '' || !StoreCartService::removeUnavailable($materialGuid)) {
            return self::payload('تعذر إزالة الصنف.', false);
        }

        return self::payload('تمت إزالة الصنف من قائمة غير المتوفرة.', true, reprice: false);
    }

    private static function clearUnavailable(): array
    {
        StoreCartService::clearUnavailable();

        return self::payload('تمت إزالة الأصناف غير المتوفرة.', true, reprice: false);
    }

    /** @param array<string, mixed> $display @param array<string, mixed> $input */
    private static function submitOrder(array $input, array $display): array
    {
        $reprice = StoreCartPricingService::repriceCart(StoreCartService::TOKEN);
        $confirm = (string) ($input['confirm_price_changes'] ?? '') === '1';
        if ($reprice['changes'] !== [] && !$confirm) {
            $payload = self::payload(
                'تغيّرت أسعار بعض الأصناف — راجع السلة ثم أكّد الإرسال.',
                false,
                [],
                'warning',
                $display
            );
            $payload['requires_price_confirmation'] = true;
            $payload['price_changes'] = $reprice['changes'];

            return $payload;
        }

        $result = StoreCartRequest::handleSubmitOrderPostFromInput($input, $display);
        if (!($result['ok'] ?? false)) {
            if (!empty($result['requires_price_confirmation'])) {
                $payload = self::payload((string) ($result['message'] ?? 'تغيّرت الأسعار.'), false, [], 'warning', $display);
                $payload['requires_price_confirmation'] = true;
                $payload['price_changes'] = is_array($result['price_changes'] ?? null) ? $result['price_changes'] : [];

                return $payload;
            }

            return self::payload((string) ($result['message'] ?? 'تعذر حفظ الطلب.'), false, [], 'error', $display);
        }

        $payload = self::payload((string) ($result['message'] ?? 'تم إرسال الطلب بنجاح.'), true, [], 'success', $display);
        if (!empty($result['redirect'])) {
            $payload['redirect'] = (string) $result['redirect'];
        }
        if (!empty($result['tracking_url'])) {
            $payload['tracking_url'] = (string) $result['tracking_url'];
        }
        if (!empty($result['order_number'])) {
            $payload['order_number'] = (string) $result['order_number'];
        }

        return $payload;
    }

    /**
     * @param list<string> $notices
     * @param array<string, mixed>|null $displayOverride
     * @return array<string, mixed>
     */
    private static function payload(
        ?string $message,
        bool $ok,
        array $notices = [],
        string $level = 'info',
        ?array $displayOverride = null,
        bool $reprice = true
    ): array {
        $display = $displayOverride ?? StoreCatalogService::displayOptionsForCartContext([], false);
        if ($reprice) {
            StoreCartPricingService::repriceCart(StoreCartService::TOKEN);
        }
        $pendingChanges = StoreCartPricingService::pendingPriceChanges(StoreCartService::TOKEN);
        $changesByGuid = [];
        foreach ($pendingChanges as $change) {
            $guid = trim((string) ($change['material_guid'] ?? ''));
            if ($guid !== '') {
                $changesByGuid[$guid] = $change;
            }
        }

        $maxPackages = StorePolicyService::maxPackagesPerMaterial();
        $items = array_values(array_map(
            static function (array $line) use ($changesByGuid, $reprice): array {
                $enriched = $reprice ? ShareCartService::enrichLineWithOffer($line) : $line;
                $guid = trim((string) ($enriched['material_guid'] ?? ''));
                if ($guid !== '' && isset($changesByGuid[$guid])) {
                    $enriched['price_change'] = $changesByGuid[$guid];
                } else {
                    unset($enriched['price_change']);
                }
                $enriched['customer_show_price'] = StoreCartPricingService::resolveLineCustomerShowPrice($enriched);
                $enriched['display_has_price'] = StoreCartPricingService::lineHasDisplayPrice($enriched);

                return $enriched;
            },
            StoreCartService::items()
        ));
        $showPrice = StoreCartPricingService::cartShowsAnyLinePrices($items);
        $partition = StoreCartPricingService::partitionItems($items);
        $unavailable = array_values(StoreCartService::unavailableItems());
        $totals = StoreCartService::totals();
        $displayTotals = StoreCartPricingService::displayTotals(StoreCartService::TOKEN);
        $pendingChanges = array_values(array_filter(
            $pendingChanges,
            static function (array $change) use ($items): bool {
                $guid = trim((string) ($change['material_guid'] ?? ''));
                if ($guid === '') {
                    return false;
                }
                foreach ($items as $line) {
                    if (trim((string) ($line['material_guid'] ?? '')) !== $guid) {
                        continue;
                    }

                    return StoreCartPricingService::lineHasDisplayPrice($line);
                }

                return false;
            }
        ));
        $cartQtyByGuid = [];
        foreach (StoreCartService::items() as $guid => $line) {
            $cartQtyByGuid[$guid] = max(0.0, round((float) ($line['quantity'] ?? 0), 4));
        }

        $payload = [
            'ok' => $ok,
            'level' => $ok ? ($level === 'warning' ? 'warning' : 'success') : 'error',
            'message' => $message ?? '',
            'cart_count' => StoreCartService::itemCount(),
            'cart_package_count' => StoreCartService::packageCount(),
            'items' => $items,
            'unavailable' => $unavailable,
            'totals' => $totals,
            'display_totals' => $displayTotals,
            'has_mixed_pricing' => $partition['has_mixed'],
            'priced_items_count' => count($partition['priced']),
            'unpriced_items_count' => count($partition['unpriced']),
            'cart_qty_by_guid' => $cartQtyByGuid,
            'max_packages_per_material' => $maxPackages,
            'max_packages_label' => $maxPackages !== null
                ? SpecialOfferService::formatQuantityLabel($maxPackages)
                : null,
            'allow_cart' => (bool) ($display['allow_cart'] ?? false),
            'allow_order' => (bool) ($display['allow_order'] ?? false),
            'show_price' => $showPrice,
            'price_mode' => (string) ($display['price_mode'] ?? 'syp'),
            'stock_notices' => $notices,
            'price_changes' => $pendingChanges,
            'logged_in' => CustomerSession::check(),
            'amine_online' => AmineAvailabilityService::isAvailable(),
            'amine_notice' => AmineAvailabilityService::isAvailable() ? '' : AmineAvailabilityService::userMessage(),
        ];

        return $payload;
    }

    /** @param array<string, mixed>|null $product @return array{ok: bool, message: string} */
    private static function clientQuantityCheck(
        string $materialGuid,
        float $addQty,
        float $currentQty,
        ?array $product = null,
        ?float $warehousePrimary = null
    ): array {
        $max = StorePolicyService::maxPackagesPerMaterial();
        $target = $currentQty + $addQty;

        if ($product === null) {
            $product = StoreCatalogService::findMaterial($materialGuid);
        }
        if ($product !== null) {
            $packaging = ShareCartService::packaging($product);
            if ($warehousePrimary === null) {
                $warehousePrimary = StockReservationService::warehousePrimaryUnits($product);
            }
            $available = StockReservationService::availablePackagesExact($materialGuid, $warehousePrimary, $packaging);
            $packageUnit = ShareCartService::packageUnitLabel($product);
            $name = trim((string) ($product['name'] ?? $product['Name'] ?? 'المادة'));

            if ($available <= 0) {
                if ($currentQty > 0) {
                    return [
                        'ok' => false,
                        'message' => 'لا يمكن زيادة الكمية — نفد المخزون المتاح حالياً. لديك '
                            . StockReservationService::formatPackages($currentQty) . ' ' . $packageUnit . ' في السلة.',
                    ];
                }

                return [
                    'ok' => false,
                    'message' => 'نفدت كمية «' . $name . '» المتاحة للطلب حالياً.',
                ];
            }

            if ($target > $available + 0.0001) {
                $remaining = max(0.0, round($available - $currentQty, 4));
                if ($currentQty > 0) {
                    return [
                        'ok' => false,
                        'message' => $remaining > 0
                            ? 'يمكنك إضافة ' . StockReservationService::formatPackages($remaining) . ' ' . $packageUnit
                                . ' فقط — لديك ' . StockReservationService::formatPackages($currentQty)
                                . ' والحد المتاح ' . StockReservationService::formatPackages($available) . '.'
                            : 'لا يمكن إضافة المزيد — لديك ' . StockReservationService::formatPackages($currentQty)
                                . ' ' . $packageUnit . ' والحد المتاح ' . StockReservationService::formatPackages($available) . '.',
                    ];
                }

                return [
                    'ok' => false,
                    'message' => 'الحد المتاح لـ «' . $name . '» هو '
                        . StockReservationService::formatPackages($available) . ' ' . $packageUnit . '.',
                ];
            }
        }

        if ($max === null) {
            return ['ok' => true, 'message' => ''];
        }

        if ($target <= $max + 0.0001) {
            return ['ok' => true, 'message' => ''];
        }

        $maxLabel = SpecialOfferService::formatQuantityLabel($max);
        if ($currentQty > 0) {
            $remaining = max(0.0, round($max - $currentQty, 4));
            $remainingLabel = StockReservationService::formatPackages($remaining);

            return [
                'ok' => false,
                'message' => 'الحد الأقصى ' . $maxLabel . ' طرد لهذه المادة. لديك '
                    . StockReservationService::formatPackages($currentQty) . ' في السلة'
                    . ($remaining > 0 ? ' — يمكنك إضافة ' . $remainingLabel . ' فقط.' : ' — لا يمكن إضافة المزيد.'),
            ];
        }

        return [
            'ok' => false,
            'message' => 'الحد الأقصى للطلب هو ' . $maxLabel . ' طرد لهذه المادة.',
        ];
    }
}
