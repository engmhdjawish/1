<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;

final class StorePolicyService
{
    /** @var array<string, mixed>|null */
    private static ?array $guestPolicyCache = null;

    private static bool $guestPolicyResolved = false;

    private static bool $maxPackagesResolved = false;

    private static ?float $maxPackagesCache = null;

    private static ?bool $unavailablePricesCache = null;

    /** @return array<string, mixed>|null */
    public static function guestPolicy(): ?array
    {
        if (self::$guestPolicyResolved) {
            return self::$guestPolicyCache;
        }

        self::$guestPolicyResolved = true;

        $row = Database::pdo()->query(
            'SELECT
                ap.id,
                ap.code,
                ap.name_ar,
                ap.description_ar,
                CASE WHEN ap.show_price THEN 1 ELSE 0 END AS show_price,
                CASE WHEN ap.show_quantity THEN 1 ELSE 0 END AS show_quantity,
                CASE WHEN ap.allow_cart THEN 1 ELSE 0 END AS allow_cart,
                CASE WHEN ap.allow_order THEN 1 ELSE 0 END AS allow_order,
                CASE WHEN ap.is_active THEN 1 ELSE 0 END AS is_active,
                s.max_packages_per_material
             FROM store_guest_settings s
             INNER JOIN access_policies ap ON ap.id = s.access_policy_id AND ap.is_active = TRUE
             WHERE s.id = 1
             LIMIT 1'
        )->fetch();

        self::$guestPolicyCache = $row ?: null;

        return self::$guestPolicyCache;
    }

    public static function maxPackagesPerMaterial(): ?float
    {
        if (self::$maxPackagesResolved) {
            return self::$maxPackagesCache;
        }

        $value = Database::pdo()->query(
            'SELECT max_packages_per_material FROM store_guest_settings WHERE id = 1 LIMIT 1'
        )->fetchColumn();
        self::$maxPackagesResolved = true;
        if ($value === false || $value === null || !is_numeric((string) $value)) {
            self::$maxPackagesCache = null;

            return null;
        }
        $float = (float) $value;
        self::$maxPackagesCache = $float > 0 ? $float : null;

        return self::$maxPackagesCache;
    }

    public static function setMaxPackagesPerMaterial(?float $max, ?string $updatedByUserId): void
    {
        $policyId = PortalSettingsService::guestPolicyId();
        if ($policyId === null) {
            return;
        }
        $stmt = Database::pdo()->prepare(
            'INSERT INTO store_guest_settings (id, access_policy_id, max_packages_per_material, updated_by_user_id)
             VALUES (1, :policy_id, :max_packages, :user_id)
             ON CONFLICT (id) DO UPDATE SET
                max_packages_per_material = EXCLUDED.max_packages_per_material,
                updated_at = NOW(),
                updated_by_user_id = EXCLUDED.updated_by_user_id'
        );
        $stmt->execute([
            'policy_id' => $policyId,
            'max_packages' => $max !== null && $max > 0 ? $max : null,
            'user_id' => $updatedByUserId ?: null,
        ]);
        self::$maxPackagesResolved = true;
        self::$maxPackagesCache = $max !== null && $max > 0 ? $max : null;
    }

    /** Out-of-stock materials keep their price hidden unless the store settings say otherwise. */
    public static function showUnavailablePrices(): bool
    {
        if (self::$unavailablePricesCache !== null) {
            return self::$unavailablePricesCache;
        }

        try {
            $value = Database::pdo()->query(
                'SELECT show_unavailable_prices FROM store_guest_settings WHERE id = 1 LIMIT 1'
            )->fetchColumn();
            self::$unavailablePricesCache = $value === true || $value === 't' || $value === 1 || $value === '1';
        } catch (\Throwable) {
            self::$unavailablePricesCache = false;
        }

        return self::$unavailablePricesCache;
    }

    public static function setShowUnavailablePrices(bool $show): void
    {
        try {
            Database::pdo()->prepare(
                'UPDATE store_guest_settings SET show_unavailable_prices = :show, updated_at = NOW() WHERE id = 1'
            )->execute(['show' => $show ? 'true' : 'false']);
            self::$unavailablePricesCache = $show;
        } catch (\Throwable $exception) {
            error_log('setShowUnavailablePrices: ' . $exception->getMessage());
        }
    }
}
