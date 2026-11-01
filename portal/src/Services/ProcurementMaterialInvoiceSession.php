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
        $materialGuid = trim($materialGuid);
        if ($materialGuid === '') {
            return;
        }

        $guids = self::guids();
        if (!in_array($materialGuid, $guids, true)) {
            $guids[] = $materialGuid;
        }

        $_SESSION[self::KEY] = $guids;
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
