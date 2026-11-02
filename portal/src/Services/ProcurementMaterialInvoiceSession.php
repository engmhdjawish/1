<?php

declare(strict_types=1);

namespace Portal\Services;

/** Working list of materials being configured in «فاتورة ضبط» (staff session). */
final class ProcurementMaterialInvoiceSession
{
    private const KEY = 'procurement_material_invoice_guids';

    /** @return list<string> */
    public static function guids(): array
    {
        $raw = $_SESSION[self::KEY] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $guid) {
            $guid = trim((string) $guid);
            if ($guid !== '') {
                $result[] = $guid;
            }
        }

        return array_values(array_unique($result));
    }

    public static function add(string $materialGuid): void
    {
        self::addMany([$materialGuid]);
    }

    /**
     * @param list<string> $materialGuids
     */
    public static function addMany(array $materialGuids): int
    {
        $guids = self::guids();
        $before = count($guids);
        $seen = array_fill_keys($guids, true);

        foreach ($materialGuids as $materialGuid) {
            $materialGuid = trim($materialGuid);
            if ($materialGuid === '' || isset($seen[$materialGuid])) {
                continue;
            }
            $guids[] = $materialGuid;
            $seen[$materialGuid] = true;
        }

        $_SESSION[self::KEY] = $guids;

        return count($guids) - $before;
    }

    public static function remove(string $materialGuid): void
    {
        $materialGuid = trim($materialGuid);
        $_SESSION[self::KEY] = array_values(array_filter(
            self::guids(),
            static fn (string $guid): bool => $guid !== $materialGuid
        ));
    }

    public static function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }

    public static function count(): int
    {
        return count(self::guids());
    }
}
