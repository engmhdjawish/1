<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

/**
 * Portal-side stock holds: pending/confirmed orders reduce sellable quantity
 * until the order is completed (synced to Amine) or cancelled.
 */
final class StockReservationService
{
    /** @param array<string, mixed> $material */
    public static function warehousePrimaryUnits(array $material): float
    {
        return max(0.0, (float) ($material['warehouseQuantity'] ?? $material['WarehouseQuantity'] ?? 0));
    }

    public static function fetchWarehousePrimary(string $materialGuid): ?float
    {
        $materialGuid = trim($materialGuid);
        if ($materialGuid === '') {
            return null;
        }

        try {
            $response = ApiClient::get('/api/materials/' . rawurlencode($materialGuid));
            if (!$response['ok'] || !is_array($response['data'] ?? null)) {
                return null;
            }

            return self::warehousePrimaryUnits($response['data']);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<string> $materialGuids @return array<string, float> */
    public static function reservedPrimaryByMaterial(array $materialGuids = []): array
    {
        $materialGuids = array_values(array_unique(array_filter(array_map('strval', $materialGuids), static fn (string $g): bool => trim($g) !== '')));

        $sql = "SELECT oi.material_guid::text AS material_guid,
                       COALESCE(SUM(oi.quantity * oi.pcs_per_box), 0)::float8 AS reserved_primary
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                WHERE o.status IN ('pending', 'confirmed')";
        $params = [];
        if ($materialGuids !== []) {
            $placeholders = [];
            foreach ($materialGuids as $index => $guid) {
                $key = 'g' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $guid;
            }
            $sql .= ' AND oi.material_guid::text IN (' . implode(', ', $placeholders) . ')';
        }
        $sql .= ' GROUP BY oi.material_guid';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $guid = trim((string) ($row['material_guid'] ?? ''));
            if ($guid === '') {
                continue;
            }
            $result[$guid] = max(0.0, (float) ($row['reserved_primary'] ?? 0));
        }

        return $result;
    }

    public static function reservedPrimaryFor(string $materialGuid): float
    {
        $materialGuid = trim($materialGuid);
        if ($materialGuid === '') {
            return 0.0;
        }

        return self::reservedPrimaryByMaterial([$materialGuid])[$materialGuid] ?? 0.0;
    }

    public static function availablePrimaryUnits(
        string $materialGuid,
        float $warehousePrimary,
        float $packaging = 1.0
    ): float {
        $materialGuid = trim($materialGuid);
        if ($materialGuid === '') {
            return 0.0;
        }

        $reserved = self::reservedPrimaryFor($materialGuid);

        return max(0.0, $warehousePrimary - $reserved);
    }

    public static function availablePackages(
        string $materialGuid,
        float $warehousePrimary,
        float $packaging
    ): float {
        return self::availablePackagesExact($materialGuid, $warehousePrimary, $packaging);
    }

    public static function availablePackagesExact(
        string $materialGuid,
        float $warehousePrimary,
        float $packaging
    ): float {
        $packaging = max(0.0001, $packaging);
        $primary = self::availablePrimaryUnits($materialGuid, $warehousePrimary, $packaging);

        return max(0.0, round($primary / $packaging, 4));
    }

    /** @param array<string, mixed> $material */
    public static function displayPackagesAvailable(array $material): float
    {
        $guid = trim((string) ($material['materialGuid'] ?? $material['MaterialGuid'] ?? $material['material_guid'] ?? ''));
        if ($guid === '') {
            return 0.0;
        }

        $packaging = ShareCartService::packaging($material);
        $warehouse = self::warehousePrimaryUnits($material);

        return self::availablePackagesExact($guid, $warehouse, $packaging);
    }

    /**
     * @param array<string, mixed> $line
     * @return array{
     *   ok: bool,
     *   available_packages: float,
     *   requested_packages: float,
     *   capped_packages: float,
     *   message: string,
     *   warehouse_primary: float,
     *   reserved_primary: float
     * }
     */
    public static function validateCartLine(
        array $line,
        ?float $warehousePrimary = null
    ): array {
        $materialGuid = trim((string) ($line['material_guid'] ?? ''));
        $packaging = max(1.0, (float) ($line['packaging'] ?? $line['pcs_per_box'] ?? 1));
        $requested = max(0.0, (float) ($line['quantity'] ?? 0));
        $name = trim((string) ($line['material_name_ar'] ?? 'المادة'));
        $packageUnit = trim((string) ($line['package_unit'] ?? '')) ?: 'طرد';

        if ($materialGuid === '' || $requested <= 0) {
            return [
                'ok' => false,
                'available_packages' => 0.0,
                'requested_packages' => $requested,
                'capped_packages' => 0.0,
                'message' => 'بيانات المادة غير صالحة.',
                'warehouse_primary' => 0.0,
                'reserved_primary' => 0.0,
            ];
        }

        if ($warehousePrimary === null) {
            $warehousePrimary = self::fetchWarehousePrimary($materialGuid);
        }
        if ($warehousePrimary === null) {
            return [
                'ok' => false,
                'available_packages' => 0.0,
                'requested_packages' => $requested,
                'capped_packages' => 0.0,
                'message' => 'تعذر التحقق من كمية «' . $name . '».',
                'warehouse_primary' => 0.0,
                'reserved_primary' => self::reservedPrimaryFor($materialGuid),
            ];
        }

        $reserved = self::reservedPrimaryFor($materialGuid);
        $availablePackages = self::availablePackages($materialGuid, $warehousePrimary, $packaging);
        $capped = min($requested, $availablePackages);

        if ($availablePackages <= 0) {
            return [
                'ok' => false,
                'available_packages' => 0.0,
                'requested_packages' => $requested,
                'capped_packages' => 0.0,
                'message' => 'نفدت كمية «' . $name . '» المتاحة للطلب حالياً.',
                'warehouse_primary' => $warehousePrimary,
                'reserved_primary' => $reserved,
            ];
        }

        if ($capped < $requested) {
            return [
                'ok' => false,
                'available_packages' => $availablePackages,
                'requested_packages' => $requested,
                'capped_packages' => $capped,
                'message' => 'الكمية المتاحة لـ «' . $name . '» هي ' . self::formatPackages($capped) . ' ' . $packageUnit . ' فقط.',
                'warehouse_primary' => $warehousePrimary,
                'reserved_primary' => $reserved,
            ];
        }

        return [
            'ok' => true,
            'available_packages' => $availablePackages,
            'requested_packages' => $requested,
            'capped_packages' => $capped,
            'message' => '',
            'warehouse_primary' => $warehousePrimary,
            'reserved_primary' => $reserved,
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{
     *   available: list<array<string, mixed>>,
     *   unavailable: list<array<string, mixed>>,
     *   notices: list<string>
     * }
     */
    public static function splitCartByAvailability(array $lines, bool $fetchFreshWarehouse = true): array
    {
        $guids = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $guid = trim((string) ($line['material_guid'] ?? ''));
            if ($guid !== '') {
                $guids[] = $guid;
            }
        }
        $reservedMap = self::reservedPrimaryByMaterial($guids);
        $warehouseMap = [];
        if ($fetchFreshWarehouse) {
            foreach (array_unique($guids) as $guid) {
                $warehouseMap[$guid] = self::fetchWarehousePrimary($guid);
            }
        }

        $available = [];
        $unavailable = [];
        $notices = [];

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $guid = trim((string) ($line['material_guid'] ?? ''));
            $packaging = max(1.0, (float) ($line['packaging'] ?? $line['pcs_per_box'] ?? 1));
            $requested = max(0.0, (float) ($line['quantity'] ?? 0));
            if ($guid === '' || $requested <= 0) {
                continue;
            }

            $warehouse = $warehouseMap[$guid] ?? null;
            if ($warehouse === null && !$fetchFreshWarehouse) {
                $warehouse = self::fetchWarehousePrimary($guid);
            }
            if ($warehouse === null) {
                $line['stock_message'] = 'تعذر التحقق من الكمية المتاحة.';
                $unavailable[] = $line;
                $notices[] = (string) $line['stock_message'];
                continue;
            }

            $reserved = $reservedMap[$guid] ?? 0.0;
            $availablePackages = self::availablePackages($guid, $warehouse, $packaging);
            if ($availablePackages <= 0) {
                $line['stock_message'] = 'نفدت كمية هذا الصنف المتاحة للطلب حالياً.';
                $unavailable[] = $line;
                $notices[] = '«' . trim((string) ($line['material_name_ar'] ?? 'مادة')) . '»: ' . $line['stock_message'];
                continue;
            }

            if ($availablePackages < $requested) {
                $line['quantity'] = $availablePackages;
                $notices[] = 'تم تعديل كمية «' . trim((string) ($line['material_name_ar'] ?? 'مادة')) . '» إلى ' . self::formatPackages($availablePackages) . ' طرد حسب المتوفر.';
            }

            $available[] = ShareCartService::normalizeLine($line);
        }

        return [
            'available' => $available,
            'unavailable' => $unavailable,
            'notices' => array_values(array_unique(array_filter($notices, static fn (string $n): bool => trim($n) !== ''))),
        ];
    }

    public static function lockMaterialsForOrder(\PDO $pdo, array $materialGuids): void
    {
        $materialGuids = array_values(array_unique(array_filter(array_map('strval', $materialGuids), static fn (string $g): bool => trim($g) !== '')));
        sort($materialGuids, SORT_STRING);
        $stmt = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:guid)::bigint)');
        foreach ($materialGuids as $guid) {
            $stmt->execute(['guid' => $guid]);
        }
    }

    public static function formatPackages(float $qty): string
    {
        $formatted = number_format($qty, 2, '.', ',');
        return rtrim(rtrim($formatted, '0'), '.');
    }
}
