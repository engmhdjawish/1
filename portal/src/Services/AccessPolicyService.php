<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;
use PDOException;

final class AccessPolicyService
{
    /** @return list<array<string, mixed>> */
    public static function listPolicies(bool $includeInactive = true): array
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
                    CASE WHEN is_active THEN 1 ELSE 0 END AS is_active,
                    created_at,
                    updated_at
                FROM access_policies';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = TRUE';
        }
        $sql .= ' ORDER BY name_ar';

        $rows = Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['usage'] = self::usageSummary((string) ($row['id'] ?? ''));
        }
        unset($row);

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public static function getById(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT
                id::text AS id,
                code,
                name_ar,
                description_ar,
                CASE WHEN show_price THEN 1 ELSE 0 END AS show_price,
                CASE WHEN show_quantity THEN 1 ELSE 0 END AS show_quantity,
                CASE WHEN allow_cart THEN 1 ELSE 0 END AS allow_cart,
                CASE WHEN allow_order THEN 1 ELSE 0 END AS allow_order,
                CASE WHEN is_active THEN 1 ELSE 0 END AS is_active
             FROM access_policies
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, message: string, id?: string}
     */
    public static function save(?string $id, array $input): array
    {
        $code = self::normalizeCode((string) ($input['code'] ?? ''));
        $nameAr = trim((string) ($input['name_ar'] ?? ''));
        $descriptionAr = trim((string) ($input['description_ar'] ?? ''));
        $showPrice = self::boolFromInput($input['show_price'] ?? null);
        $showQuantity = self::boolFromInput($input['show_quantity'] ?? null);
        $allowCart = self::boolFromInput($input['allow_cart'] ?? null);
        $allowOrder = self::boolFromInput($input['allow_order'] ?? null);
        $isActive = self::boolFromInput($input['is_active'] ?? null, true);

        if ($code === '') {
            return ['ok' => false, 'message' => 'رمز السياسة مطلوب (حروف إنجليزية وأرقام وشرطة سفلية).'];
        }
        if ($nameAr === '') {
            return ['ok' => false, 'message' => 'اسم السياسة بالعربية مطلوب.'];
        }

        $pdo = Database::pdo();
        $isUpdate = $id !== null && trim($id) !== '';

        try {
            if ($isUpdate) {
                $stmt = $pdo->prepare(
                    'UPDATE access_policies SET
                        code = :code,
                        name_ar = :name_ar,
                        description_ar = :description_ar,
                        show_price = CASE WHEN :show_price = 1 THEN TRUE ELSE FALSE END,
                        show_quantity = CASE WHEN :show_quantity = 1 THEN TRUE ELSE FALSE END,
                        allow_cart = CASE WHEN :allow_cart = 1 THEN TRUE ELSE FALSE END,
                        allow_order = CASE WHEN :allow_order = 1 THEN TRUE ELSE FALSE END,
                        is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                        updated_at = NOW()
                     WHERE id = :id'
                );
                $stmt->execute([
                    'id' => trim($id),
                    'code' => $code,
                    'name_ar' => $nameAr,
                    'description_ar' => $descriptionAr !== '' ? $descriptionAr : null,
                    'show_price' => $showPrice ? 1 : 0,
                    'show_quantity' => $showQuantity ? 1 : 0,
                    'allow_cart' => $allowCart ? 1 : 0,
                    'allow_order' => $allowOrder ? 1 : 0,
                    'is_active' => $isActive ? 1 : 0,
                ]);

                return ['ok' => true, 'message' => 'تم تحديث سياسة الوصول.', 'id' => trim($id)];
            }

            $stmt = $pdo->prepare(
                'INSERT INTO access_policies (
                    code, name_ar, description_ar,
                    show_price, show_quantity, allow_cart, allow_order, is_active
                 ) VALUES (
                    :code, :name_ar, :description_ar,
                    CASE WHEN :show_price = 1 THEN TRUE ELSE FALSE END,
                    CASE WHEN :show_quantity = 1 THEN TRUE ELSE FALSE END,
                    CASE WHEN :allow_cart = 1 THEN TRUE ELSE FALSE END,
                    CASE WHEN :allow_order = 1 THEN TRUE ELSE FALSE END,
                    CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END
                 )
                 RETURNING id::text'
            );
            $stmt->execute([
                'code' => $code,
                'name_ar' => $nameAr,
                'description_ar' => $descriptionAr !== '' ? $descriptionAr : null,
                'show_price' => $showPrice ? 1 : 0,
                'show_quantity' => $showQuantity ? 1 : 0,
                'allow_cart' => $allowCart ? 1 : 0,
                'allow_order' => $allowOrder ? 1 : 0,
                'is_active' => $isActive ? 1 : 0,
            ]);
            $newId = (string) $stmt->fetchColumn();

            return ['ok' => true, 'message' => 'تم إنشاء سياسة الوصول.', 'id' => $newId];
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'uq_access_policies') || str_contains($exception->getMessage(), 'unique')) {
                return ['ok' => false, 'message' => 'رمز السياسة مستخدم مسبقاً. اختر رمزاً آخر.'];
            }

            return ['ok' => false, 'message' => 'تعذر حفظ السياسة: ' . $exception->getMessage()];
        }
    }

    /** @return array{ok: bool, message: string} */
    public static function delete(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return ['ok' => false, 'message' => 'معرّف السياسة غير صالح.'];
        }

        $usage = self::usageSummary($id);
        if ($usage['total'] > 0) {
            return [
                'ok' => false,
                'message' => 'لا يمكن حذف السياسة لأنها مستخدمة '
                    . self::formatUsageMessage($usage)
                    . '. عطّلها بدلاً من الحذف أو غيّر الربط أولاً.',
            ];
        }

        $stmt = Database::pdo()->prepare('DELETE FROM access_policies WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() < 1) {
            return ['ok' => false, 'message' => 'السياسة غير موجودة.'];
        }

        return ['ok' => true, 'message' => 'تم حذف سياسة الوصول.'];
    }

    /** @return array{share_links: int, web_customers: int, guest_store: int, total: int} */
    public static function usageSummary(string $policyId): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*)::int FROM share_links WHERE access_policy_id = :id) AS share_links,
                (SELECT COUNT(*)::int FROM web_customers WHERE access_policy_id = :id) AS web_customers,
                (SELECT COUNT(*)::int FROM store_guest_settings WHERE access_policy_id = :id) AS guest_store'
        );
        $stmt->execute(['id' => $policyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $shareLinks = (int) ($row['share_links'] ?? 0);
        $webCustomers = (int) ($row['web_customers'] ?? 0);
        $guestStore = (int) ($row['guest_store'] ?? 0);

        return [
            'share_links' => $shareLinks,
            'web_customers' => $webCustomers,
            'guest_store' => $guestStore,
            'total' => $shareLinks + $webCustomers + $guestStore,
        ];
    }

    /** @param array{share_links: int, web_customers: int, guest_store: int, total: int} $usage */
    private static function formatUsageMessage(array $usage): string
    {
        $parts = [];
        if ($usage['guest_store'] > 0) {
            $parts[] = 'في سياسة المتجر العام';
        }
        if ($usage['share_links'] > 0) {
            $parts[] = 'في ' . $usage['share_links'] . ' رابط مشاركة';
        }
        if ($usage['web_customers'] > 0) {
            $parts[] = 'في ' . $usage['web_customers'] . ' عميل ويب';
        }

        return implode('، ', $parts);
    }

    private static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';
        $code = trim($code, '_');

        return $code;
    }

    private static function boolFromInput(mixed $value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }
}
