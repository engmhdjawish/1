<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;
use Portal\Database;
use PDO;

final class PortalSettingsService
{
    /** @return array<string, string> */
    public static function companySettings(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT key, value_ar
             FROM company_settings'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [
            'company_name' => '',
            'company_phone' => '',
            'company_mobile' => '',
            'company_whatsapp' => '',
            'company_address' => '',
            'company_logo' => '',
        ];

        foreach ($rows as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $map[$key] = (string) ($row['value_ar'] ?? '');
        }

        return $map;
    }

    /** @param array<string, string> $values */
    public static function saveCompanySettings(array $values, ?string $updatedByUserId): void
    {
        $allowedKeys = [
            'company_name',
            'company_phone',
            'company_mobile',
            'company_whatsapp',
            'company_address',
            'company_logo',
        ];

        $stmt = Database::pdo()->prepare(
            'INSERT INTO company_settings (key, value_ar, updated_by_user_id)
             VALUES (:key, :value_ar, :updated_by_user_id)
             ON CONFLICT (key)
             DO UPDATE SET
                value_ar = EXCLUDED.value_ar,
                updated_at = NOW(),
                updated_by_user_id = EXCLUDED.updated_by_user_id'
        );

        foreach ($allowedKeys as $key) {
            $stmt->execute([
                'key' => $key,
                'value_ar' => trim((string) ($values[$key] ?? '')),
                'updated_by_user_id' => $updatedByUserId ?: null,
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    public static function accessPolicies(bool $onlyActive = true): array
    {
        $sql = 'SELECT
                    id::text AS id,
                    code,
                    name_ar,
                    description_ar,
                    CASE WHEN show_price THEN 1 ELSE 0 END AS show_price,
                    CASE WHEN show_quantity THEN 1 ELSE 0 END AS show_quantity,
                    CASE WHEN allow_cart THEN 1 ELSE 0 END AS allow_cart,
                    CASE WHEN allow_order THEN 1 ELSE 0 END AS allow_order,
                    CASE WHEN is_active THEN 1 ELSE 0 END AS is_active
                FROM access_policies';
        if ($onlyActive) {
            $sql .= ' WHERE is_active = TRUE';
        }
        $sql .= ' ORDER BY name_ar';

        return Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function guestPolicyId(): ?string
    {
        $value = Database::pdo()->query(
            'SELECT access_policy_id::text
             FROM store_guest_settings
             WHERE id = 1
             LIMIT 1'
        )->fetchColumn();

        return $value ? (string) $value : null;
    }

    public static function setGuestPolicy(string $policyId, ?string $updatedByUserId): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO store_guest_settings (id, access_policy_id, updated_by_user_id)
             VALUES (1, :access_policy_id, :updated_by_user_id)
             ON CONFLICT (id)
             DO UPDATE SET
                access_policy_id = EXCLUDED.access_policy_id,
                updated_at = NOW(),
                updated_by_user_id = EXCLUDED.updated_by_user_id'
        );
        $stmt->execute([
            'access_policy_id' => $policyId,
            'updated_by_user_id' => $updatedByUserId ?: null,
        ]);
    }

    /** @return array{base_url: string, ok: bool, status: int, message: string} */
    public static function apiHealth(): array
    {
        $base = rtrim(Config::get('AMINE_API_BASE_URL', 'http://127.0.0.1:5000') ?? '', '/');
        $url = $base . '/api/health';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'base_url' => $base,
                'ok' => false,
                'status' => 0,
                'message' => $error !== '' ? $error : 'تعذر الاتصال بالـ API.',
            ];
        }

        if ($status >= 200 && $status < 300) {
            return [
                'base_url' => $base,
                'ok' => true,
                'status' => $status,
                'message' => 'الاتصال بالـ API يعمل بشكل طبيعي.',
            ];
        }

        return [
            'base_url' => $base,
            'ok' => false,
            'status' => $status,
            'message' => 'الـ API أعاد رمزًا غير متوقع: ' . $status,
        ];
    }
}
