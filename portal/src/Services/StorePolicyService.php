<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;

final class StorePolicyService
{
    /** @return array<string, mixed>|null */
    public static function guestPolicy(): ?array
    {
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

        return $row ?: null;
    }

    public static function maxPackagesPerMaterial(): ?float
    {
        $value = Database::pdo()->query(
            'SELECT max_packages_per_material FROM store_guest_settings WHERE id = 1 LIMIT 1'
        )->fetchColumn();
        if ($value === false || $value === null || !is_numeric((string) $value)) {
            return null;
        }
        $float = (float) $value;

        return $float > 0 ? $float : null;
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
    }
}
