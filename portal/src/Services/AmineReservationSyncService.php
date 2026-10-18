<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

/**
 * Syncs a portal order with an Ameen reservation bill.
 *
 * Order status stays pending|confirmed|completed|cancelled. Progress is only
 * amine_sync_status (none|pending|synced|failed).
 *
 * Portal stock stays reserved until a bill guid exists or the sync is synced.
 * A failed update keeps the previous bill guid, so the portal hold is not put
 * back on top of the quantity Ameen already deducted.
 */
final class AmineReservationSyncService
{
    public const MODE_AUTOMATIC = 'automatic';
    public const MODE_MANUAL = 'manual';

    private const OWNER_SOURCE = 'orderCustomerName';

    /** @var array{syncMode: string, ownerNameSource: string}|null */
    private static ?array $settings = null;

    private static ?bool $billNumberReady = null;

    public static function normalizeMode(?string $mode): string
    {
        return $mode === self::MODE_AUTOMATIC ? self::MODE_AUTOMATIC : self::MODE_MANUAL;
    }

    public static function shouldInvokeApi(?string $mode): bool
    {
        return self::normalizeMode($mode) === self::MODE_AUTOMATIC;
    }

    /**
     * Manual and automatic edits both mark the order dirty before any API call.
     * A cancelled order that was never sent does not need a bill.
     */
    public static function localSyncStatusAfterChange(string $orderStatus, ?string $billGuid): string
    {
        $hasBill = self::guidOrNull($billGuid) !== null;
        if ($orderStatus === 'cancelled' && !$hasBill) {
            return 'none';
        }

        return 'pending';
    }

    /**
     * True while the storefront must subtract this order. Mirrors portalHoldFilter().
     * Cancelled items are excluded separately by the item-status SQL.
     */
    public static function portalReservesOrder(string $orderStatus, string $syncStatus, ?string $billGuid): bool
    {
        if (!in_array($orderStatus, ['pending', 'confirmed'], true)) {
            return false;
        }
        if ($syncStatus === 'synced') {
            return false;
        }
        if (self::guidOrNull($billGuid) !== null) {
            return false;
        }

        return true;
    }

    public static function portalHoldFilter(string $orderAlias = 'o'): string
    {
        return $orderAlias . ".status IN ('pending', 'confirmed')"
            . ' AND ' . $orderAlias . ".amine_sync_status <> 'synced'"
            . ' AND ' . $orderAlias . '.amine_bill_guid IS NULL';
    }

    /**
     * @return 'create'|'update'|'delete'|'skip'
     */
    public static function operationFor(string $orderStatus, ?string $billGuid, int $activeLineCount): string
    {
        $hasBill = self::guidOrNull($billGuid) !== null;
        $removeBill = $orderStatus === 'cancelled' || $activeLineCount <= 0;
        if ($removeBill) {
            return $hasBill ? 'delete' : 'skip';
        }

        return $hasBill ? 'update' : 'create';
    }

    /**
     * @return array{
     *   amine_sync_status: string,
     *   amine_bill_guid: ?string,
     *   amine_bill_number: ?int,
     *   amine_sync_error_ar: ?string,
     *   touch_synced_at: bool,
     *   order_status: string,
     *   portal_reserves: bool
     * }
     */
    public static function applyRemoteResult(
        string $orderStatus,
        string $operation,
        ?string $previousBillGuid,
        ?int $previousBillNumber,
        bool $ok,
        ?string $returnedBillGuid,
        ?int $returnedBillNumber,
        ?string $errorAr,
        bool $dropBillGuid = false
    ): array {
        $previousBillGuid = self::guidOrNull($previousBillGuid);
        $returnedBillGuid = self::guidOrNull($returnedBillGuid);

        if ($operation === 'skip') {
            $status = 'none';
            $guid = null;
            $number = $previousBillNumber;
            $error = null;
            $touch = false;
        } elseif ($ok && in_array($operation, ['create', 'update'], true) && $returnedBillGuid === null) {
            $status = 'failed';
            $guid = $previousBillGuid;
            $number = $previousBillNumber;
            $error = self::clip($errorAr !== null && $errorAr !== '' ? $errorAr : 'نجحت الاستجابة دون رقم فاتورة حجز.');
            $touch = false;
        } elseif ($ok && $operation === 'delete') {
            $status = 'synced';
            $guid = null;
            $number = $returnedBillNumber ?? $previousBillNumber;
            $error = null;
            $touch = true;
        } elseif ($ok) {
            $status = 'synced';
            $guid = $returnedBillGuid ?? $previousBillGuid;
            $number = $returnedBillNumber ?? $previousBillNumber;
            $error = null;
            $touch = true;
        } else {
            $status = 'failed';
            $guid = $dropBillGuid ? null : $previousBillGuid;
            $number = $previousBillNumber;
            $error = self::clip($errorAr !== null && $errorAr !== '' ? $errorAr : 'فشلت مزامنة فاتورة الحجز مع الأمين.');
            $touch = false;
        }

        return [
            'amine_sync_status' => $status,
            'amine_bill_guid' => $guid,
            'amine_bill_number' => $number,
            'amine_sync_error_ar' => $error,
            'touch_synced_at' => $touch,
            'order_status' => $orderStatus,
            'portal_reserves' => self::portalReservesOrder($orderStatus, $status, $guid),
        ];
    }

    public static function arabicFailureMessage(int $status, string $apiMessage): string
    {
        $apiMessage = trim($apiMessage);
        if ($apiMessage !== '' && self::containsArabic($apiMessage)) {
            return self::clip($apiMessage);
        }

        $fallback = match (true) {
            $status === 0 => 'تعذر الاتصال بخدمة الأمين.',
            $status === 401, $status === 403 => 'رفضت خدمة الأمين صلاحية مزامنة فاتورة الحجز.',
            $status === 404 => 'فاتورة الحجز غير موجودة في الأمين.',
            $status === 409 => 'تعذر تعديل فاتورة الحجز لأن حالتها في الأمين لا تسمح بذلك.',
            default => 'فشلت مزامنة فاتورة الحجز مع الأمين.',
        };
        if ($apiMessage !== '') {
            return self::clip($fallback . ' (' . $apiMessage . ')');
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{materialGuid: string, quantity: float, packageQuantity: float, unitPrice: float, notes: string, invalid?: bool}|null
     */
    public static function apiLine(array $item): ?array
    {
        $itemStatus = (string) ($item['status'] ?? $item['item_status'] ?? 'active');
        if (!empty($item['is_cancelled']) || $itemStatus === 'cancelled') {
            return null;
        }

        $guid = trim((string) ($item['material_guid'] ?? ''));
        $packages = (float) ($item['quantity'] ?? 0);
        // Cartons × the order's pcs_per_box only. When that factor is 1, the API
        // substitutes mt000.Unit2Fact. Do not multiply by Unit2Fact here as well.
        $pairsPerPackage = max(1, (int) ($item['pcs_per_box'] ?? $item['packaging'] ?? 1));
        if ($guid === '' || $packages <= 0) {
            return null;
        }

        $pairs = $packages * $pairsPerPackage;
        $packageUsd = max(0.0, (float) ($item['sale_price_usd'] ?? 0));
        $pairPrice = $pairsPerPackage > 0 ? $packageUsd / $pairsPerPackage : 0.0;
        $originalPackage = max(0.0, (float) ($item['original_sale_price_usd'] ?? 0));
        $notes = '';
        if ($originalPackage > $packageUsd + 0.009) {
            $originalPair = $originalPackage / $pairsPerPackage;
            $notes = 'خصم: سعر الزوج من ' . self::money($originalPair) . ' إلى ' . self::money($pairPrice);
        } elseif (!empty($item['special_offer_id'])) {
            $notes = 'خصم عرض خاص';
        }

        if (!self::isGuid($guid)) {
            return [
                'materialGuid' => $guid,
                'quantity' => $pairs,
                'packageQuantity' => $packages,
                'unitPrice' => $pairPrice,
                'notes' => $notes,
                'invalid' => true,
            ];
        }

        return [
            'materialGuid' => strtolower($guid),
            'quantity' => $pairs,
            'packageQuantity' => $packages,
            'unitPrice' => $pairPrice,
            'notes' => $notes,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{ok: bool, lines: list<array{materialGuid: string, quantity: float, packageQuantity: float, unitPrice: float, notes: string}>, message?: string}
     */
    public static function linesForApi(array $items): array
    {
        $merged = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $line = self::apiLine($item);
            if ($line === null) {
                continue;
            }
            if (!empty($line['invalid'])) {
                return [
                    'ok' => false,
                    'lines' => [],
                    'message' => 'أحد الأصناف بلا معرّف مادة صالح للمزامنة.',
                ];
            }
            $guid = $line['materialGuid'];
            if (!isset($merged[$guid])) {
                $merged[$guid] = [
                    'materialGuid' => $guid,
                    'quantity' => $line['quantity'],
                    'packageQuantity' => $line['packageQuantity'],
                    'unitPrice' => $line['unitPrice'],
                    'notes' => $line['notes'],
                ];
                continue;
            }
            $merged[$guid]['quantity'] += $line['quantity'];
            $merged[$guid]['packageQuantity'] += $line['packageQuantity'];
            if ($line['notes'] !== '' && !str_contains((string) $merged[$guid]['notes'], $line['notes'])) {
                $merged[$guid]['notes'] = trim($merged[$guid]['notes'] . '؛ ' . $line['notes'], '؛ ');
            }
        }

        $lines = array_values($merged);
        if ($lines === []) {
            return [
                'ok' => false,
                'lines' => [],
                'message' => 'لا توجد أصناف نشطة لمزامنتها.',
            ];
        }

        return ['ok' => true, 'lines' => $lines];
    }

    /** @param array<string, mixed> $order */
    public static function ownerName(array $order, string $source = self::OWNER_SOURCE): string
    {
        $registered = trim((string) ($order['customer_name_ar'] ?? ''));
        $guest = trim((string) ($order['guest_name_ar'] ?? ''));
        if (trim($source) !== self::OWNER_SOURCE && trim($source) !== '') {
            // Settings only allow orderCustomerName. Keep that name if another value is passed.
            $registered = $registered !== '' ? $registered : $guest;
        }
        $display = trim((string) ($order['display_name'] ?? ''));
        $name = $registered !== '' ? $registered : ($guest !== '' ? $guest : $display);
        if ($name === '—') {
            $name = '';
        }
        if ($name === '') {
            $name = 'مزامنة من الموقع';
        } elseif (!str_contains($name, 'مزامنة من الموقع')) {
            $name .= ' — مزامنة من الموقع';
        }

        return self::clip($name);
    }

    public static function markLocalChange(PDO $pdo, string $orderId): void
    {
        $pdo->prepare(
            "UPDATE orders
             SET amine_sync_status = CASE
                     WHEN status = 'cancelled' AND amine_bill_guid IS NULL THEN 'none'::amine_sync_status
                     ELSE 'pending'::amine_sync_status
                 END,
                 amine_sync_error_ar = NULL,
                 updated_at = NOW()
             WHERE id = :id"
        )->execute(['id' => $orderId]);
    }

    /** @return array{ok: bool, skipped?: bool, message: string} */
    public static function dispatchAfterCommit(string $orderId): array
    {
        try {
            if (!self::shouldInvokeApi(self::currentMode())) {
                return ['ok' => true, 'skipped' => true, 'message' => ''];
            }

            $result = self::syncOrder($orderId);
            $result['skipped'] = false;

            return $result;
        } catch (\Throwable) {
            return [
                'ok' => false,
                'skipped' => false,
                'message' => 'تعذر الاتصال بخدمة الأمين. الطلب محفوظ وبانتظار إعادة المحاولة.',
            ];
        }
    }

    /** @return array{ok: bool, message: string} */
    public static function syncOrder(string $orderId): array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return ['ok' => false, 'message' => 'الطلب غير موجود.'];
        }

        $order = OrderService::getOrderDetails($orderId);
        if ($order === null) {
            return ['ok' => false, 'message' => 'الطلب غير موجود.'];
        }

        $orderStatus = (string) ($order['status'] ?? '');
        $previousGuid = self::guidOrNull(isset($order['amine_bill_guid']) ? (string) $order['amine_bill_guid'] : null);
        $previousNumber = self::nullableBillNumber($order['amine_bill_number'] ?? null);
        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        $activeCount = 0;
        foreach ($items as $item) {
            if (is_array($item) && empty($item['is_cancelled'])) {
                $activeCount++;
            }
        }

        $operation = self::operationFor($orderStatus, $previousGuid, $activeCount);
        if ($operation === 'skip') {
            self::persist($orderId, self::applyRemoteResult(
                $orderStatus,
                'skip',
                $previousGuid,
                $previousNumber,
                true,
                null,
                null,
                null
            ));

            return ['ok' => true, 'message' => 'لا توجد فاتورة حجز للمزامنة.'];
        }

        $lines = ['ok' => true, 'lines' => []];
        if ($operation !== 'delete') {
            $lines = self::linesForApi($items);
            if (!($lines['ok'] ?? false)) {
                $message = (string) ($lines['message'] ?? 'لا توجد أصناف نشطة لمزامنتها.');
                self::persist($orderId, self::applyRemoteResult(
                    $orderStatus,
                    $operation,
                    $previousGuid,
                    $previousNumber,
                    false,
                    null,
                    null,
                    $message
                ));

                return ['ok' => false, 'message' => $message];
            }

            $owner = self::ownerName($order, self::currentOwnerSource());
            if ($owner === '') {
                $message = 'اسم صاحب الحجز غير موجود في الطلب.';
                self::persist($orderId, self::applyRemoteResult(
                    $orderStatus,
                    $operation,
                    $previousGuid,
                    $previousNumber,
                    false,
                    null,
                    null,
                    $message
                ));

                return ['ok' => false, 'message' => $message];
            }
        }

        $response = self::callApi(
            $operation,
            $previousGuid,
            $operation === 'delete' ? '' : self::ownerName($order, self::currentOwnerSource()),
            $lines['lines']
        );
        $httpStatus = (int) ($response['status'] ?? 0);
        $parsed = self::parseResponse($response);
        $dropBillGuid = !$parsed['ok'] && $operation === 'update' && $httpStatus === 404;
        $treatMissingDeleteAsSuccess = $operation === 'delete' && $httpStatus === 404;
        $ok = $parsed['ok'] || $treatMissingDeleteAsSuccess;
        $error = $ok ? null : self::arabicFailureMessage($httpStatus, $parsed['message']);
        $state = self::applyRemoteResult(
            $orderStatus,
            $operation,
            $previousGuid,
            $previousNumber,
            $ok,
            $parsed['billGuid'],
            $parsed['billNumber'],
            $error,
            $dropBillGuid
        );
        self::persist($orderId, $state);

        if (!$ok) {
            return ['ok' => false, 'message' => (string) ($state['amine_sync_error_ar'] ?? 'فشلت مزامنة فاتورة الحجز مع الأمين.')];
        }

        $number = $state['amine_bill_number'];
        $numberText = $number !== null ? ' رقم ' . $number : '';

        return [
            'ok' => true,
            'message' => match ($operation) {
                'delete' => 'تم حذف فاتورة الحجز من الأمين واستعادة الكمية.',
                'update' => 'تم تحديث فاتورة الحجز' . $numberText . ' في الأمين.',
                default => 'تم إنشاء فاتورة الحجز' . $numberText . ' في الأمين.',
            },
        ];
    }

    public static function currentMode(): string
    {
        return self::cachedSettings()['syncMode'];
    }

    public static function ensureBillNumberColumn(): bool
    {
        if (self::$billNumberReady !== null) {
            return self::$billNumberReady;
        }

        try {
            Database::pdo()->exec('ALTER TABLE orders ADD COLUMN IF NOT EXISTS amine_bill_number INTEGER');
            self::$billNumberReady = true;
        } catch (\Throwable) {
            try {
                $stmt = Database::pdo()->query(
                    "SELECT 1
                     FROM information_schema.columns
                     WHERE table_schema = 'public'
                       AND table_name = 'orders'
                       AND column_name = 'amine_bill_number'
                     LIMIT 1"
                );
                self::$billNumberReady = (bool) $stmt->fetchColumn();
            } catch (\Throwable) {
                self::$billNumberReady = false;
            }
        }

        return self::$billNumberReady;
    }

    public static function resetRuntimeCache(): void
    {
        self::$settings = null;
        self::$billNumberReady = null;
    }

    /** @param array{amine_sync_status: string, amine_bill_guid: ?string, amine_bill_number: ?int, amine_sync_error_ar: ?string, touch_synced_at: bool} $state */
    private static function persist(string $orderId, array $state): void
    {
        $hasNumber = self::ensureBillNumberColumn();
        $sql = 'UPDATE orders
                SET amine_sync_status = CAST(:status AS amine_sync_status),
                    amine_bill_guid = :guid,
                    amine_synced_at = CASE WHEN :touch_synced = \'yes\' THEN NOW() ELSE amine_synced_at END,
                    amine_sync_error_ar = :error,
                    updated_at = NOW()';
        if ($hasNumber) {
            $sql .= ', amine_bill_number = :bill_number';
        }
        $sql .= ' WHERE id = :id';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':status', $state['amine_sync_status']);
        $stmt->bindValue(':touch_synced', $state['touch_synced_at'] ? 'yes' : 'no');
        $stmt->bindValue(':id', $orderId);
        if ($state['amine_bill_guid'] === null) {
            $stmt->bindValue(':guid', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':guid', $state['amine_bill_guid']);
        }
        if ($state['amine_sync_error_ar'] === null) {
            $stmt->bindValue(':error', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':error', $state['amine_sync_error_ar']);
        }
        if ($hasNumber) {
            if ($state['amine_bill_number'] === null) {
                $stmt->bindValue(':bill_number', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':bill_number', $state['amine_bill_number'], PDO::PARAM_INT);
            }
        }
        $stmt->execute();
    }

    /**
     * @param list<array{materialGuid: string, quantity: float, unitPrice: float}> $lines
     * @return array{ok: bool, status: int, data: mixed, error?: string}
     */
    private static function callApi(string $operation, ?string $billGuid, string $ownerName, array $lines): array
    {
        if ($operation === 'delete') {
            return ApiClient::delete('/api/bills/reservations/' . rawurlencode((string) $billGuid), 25);
        }

        $body = [
            'ownerName' => $ownerName,
            'lines' => $lines,
        ];
        if ($operation === 'update') {
            return ApiClient::putJson('/api/bills/reservations/' . rawurlencode((string) $billGuid), $body, 25);
        }

        return ApiClient::postJson('/api/bills/reservations', $body, 25);
    }

    /**
     * @param array{ok?: bool, status?: int, data?: mixed, error?: string} $response
     * @return array{ok: bool, billGuid: ?string, billNumber: ?int, message: string}
     */
    private static function parseResponse(array $response): array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $message = trim((string) ($data['message'] ?? $data['Message'] ?? $response['error'] ?? ''));
        $guid = self::guidOrNull((string) ($data['billGuid'] ?? $data['BillGuid'] ?? ''));
        $number = self::nullableBillNumber($data['billNumber'] ?? $data['BillNumber'] ?? null);

        return [
            'ok' => (bool) ($response['ok'] ?? false),
            'billGuid' => $guid,
            'billNumber' => $number,
            'message' => $message,
        ];
    }

    /** @return array{syncMode: string, ownerNameSource: string} */
    private static function cachedSettings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $defaults = [
            'syncMode' => self::MODE_MANUAL,
            'ownerNameSource' => self::OWNER_SOURCE,
        ];
        try {
            $response = ApiClient::get('/api/bills/reservation-settings', [], 15);
            $data = is_array($response['data'] ?? null) ? $response['data'] : null;
            if (!($response['ok'] ?? false) || $data === null) {
                self::$settings = $defaults;

                return self::$settings;
            }
            $mode = $data['syncMode'] ?? $data['SyncMode'] ?? null;
            $source = trim((string) ($data['ownerNameSource'] ?? $data['OwnerNameSource'] ?? self::OWNER_SOURCE));
            self::$settings = [
                'syncMode' => self::normalizeMode(is_string($mode) ? $mode : null),
                'ownerNameSource' => $source !== '' ? $source : self::OWNER_SOURCE,
            ];
        } catch (\Throwable) {
            self::$settings = $defaults;
        }

        return self::$settings;
    }

    private static function currentOwnerSource(): string
    {
        return self::cachedSettings()['ownerNameSource'];
    }

    private static function guidOrNull(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || !self::isGuid($value)) {
            return null;
        }

        return strtolower($value);
    }

    private static function isGuid(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value) === 1;
    }

    private static function nullableBillNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_float($value) && is_finite($value) && $value >= 0) {
            return (int) round($value);
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) round((float) $value);
        }

        return null;
    }

    private static function containsArabic(string $value): bool
    {
        return preg_match('/[\x{0600}-\x{06FF}]/u', $value) === 1;
    }

    private static function money(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }

    private static function clip(string $value, int $max = 1000): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }
}
