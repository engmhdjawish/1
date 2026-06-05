<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

final class AccessPolicyService
{
    /** @return list<array<string, mixed>> */
    public static function list(bool $includeInactive = true): array
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

        return Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public static function getById(string $id): ?array
    {
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

        return is_array($row) ? $row : null;
    }

    /**
     * @return array{ok: bool, message: string, id?: string}
     */
    public static function save(
        ?string $id,
        string $code,
        string $nameAr,
        string $descriptionAr,
        bool $showPrice,
        bool $showQuantity,
        bool $allowCart,
        bool $allowOrder,
        bool $isActive
    ): array {
        $code = strtolower(trim($code));
        $nameAr = trim($nameAr);
        $descriptionAr = trim($descriptionAr);

        if ($code === '' || !preg_match('/^[a-z][a-z0-9_]{1,78}$/', $code)) {
            return ['ok' => false, 'message' => 'رمز السياسة غير صالح (حروف إنجليزية صغيرة وأرقام و _).'];
        }
        if ($nameAr === '') {
            return ['ok' => false, 'message' => 'اسم السياسة مطلوب.'];
        }

        $pdo = Database::pdo();

        if ($id !== null && $id !== '') {
            $duplicate = $pdo->prepare(
                'SELECT id::text FROM access_policies WHERE code = :code AND id <> :id LIMIT 1'
            );
            $duplicate->execute(['code' => $code, 'id' => $id]);
        } else {
            $duplicate = $pdo->prepare(
                'SELECT id::text FROM access_policies WHERE code = :code LIMIT 1'
            );
            $duplicate->execute(['code' => $code]);
        }
        if ($duplicate->fetchColumn()) {
            return ['ok' => false, 'message' => 'رمز السياسة مستخدم مسبقًا.'];
        }

        if ($id !== null && $id !== '') {
            $existing = self::getById($id);
            if ($existing === null) {
                return ['ok' => false, 'message' => 'السياسة غير موجودة.'];
            }

            $stmt = $pdo->prepare(
                'UPDATE access_policies
                 SET code = :code,
                     name_ar = :name_ar,
                     description_ar = :description_ar,
                     show_price = :show_price,
                     show_quantity = :show_quantity,
                     allow_cart = :allow_cart,
                     allow_order = :allow_order,
                     is_active = :is_active,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'code' => $code,
                'name_ar' => $nameAr,
                'description_ar' => $descriptionAr !== '' ? $descriptionAr : null,
                'show_price' => $showPrice,
                'show_quantity' => $showQuantity,
                'allow_cart' => $allowCart,
                'allow_order' => $allowOrder,
                'is_active' => $isActive,
            ]);

            return ['ok' => true, 'message' => 'تم تحديث السياسة.', 'id' => $id];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO access_policies (
                code, name_ar, description_ar, show_price, show_quantity, allow_cart, allow_order, is_active
             ) VALUES (
                :code, :name_ar, :description_ar, :show_price, :show_quantity, :allow_cart, :allow_order, :is_active
             )
             RETURNING id::text'
        );
        $stmt->execute([
            'code' => $code,
            'name_ar' => $nameAr,
            'description_ar' => $descriptionAr !== '' ? $descriptionAr : null,
            'show_price' => $showPrice,
            'show_quantity' => $showQuantity,
            'allow_cart' => $allowCart,
            'allow_order' => $allowOrder,
            'is_active' => $isActive,
        ]);
        $newId = (string) $stmt->fetchColumn();

        return ['ok' => true, 'message' => 'تم إنشاء السياسة.', 'id' => $newId];
    }

    public static function setActive(string $id, bool $active): bool
    {
        if (!$active) {
            $usage = self::usageSummary($id);
            if (($usage['guest_default'] ?? false) === true) {
                return false;
            }
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE access_policies SET is_active = :is_active, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'is_active' => $active]);

        return $stmt->rowCount() > 0;
    }

    /** @return array{ok: bool, message: string} */
    public static function delete(string $id): array
    {
        $usage = self::usageSummary($id);
        $total = (int) ($usage['share_links'] ?? 0) + (int) ($usage['customers'] ?? 0);
        if (($usage['guest_default'] ?? false) === true) {
            return ['ok' => false, 'message' => 'لا يمكن حذف السياسة الافتراضية للزائر. غيّر السياسة الافتراضية أولًا.'];
        }
        if ($total > 0) {
            return ['ok' => false, 'message' => 'السياسة مستخدمة في ' . $total . ' سجل (عملاء أو روابط مشاركة). عطّلها بدل الحذف.'];
        }

        $stmt = Database::pdo()->prepare('DELETE FROM access_policies WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'message' => 'السياسة غير موجودة.'];
        }

        return ['ok' => true, 'message' => 'تم حذف السياسة.'];
    }

    /** @return array{guest_default: bool, share_links: int, customers: int} */
    public static function usageSummary(string $id): array
    {
        $pdo = Database::pdo();

        $guestDefault = $pdo->prepare(
            'SELECT COUNT(*) FROM store_guest_settings WHERE id = 1 AND access_policy_id = :id'
        );
        $guestDefault->execute(['id' => $id]);

        $shareLinks = $pdo->prepare('SELECT COUNT(*) FROM share_links WHERE access_policy_id = :id');
        $shareLinks->execute(['id' => $id]);

        $customers = $pdo->prepare('SELECT COUNT(*) FROM web_customers WHERE access_policy_id = :id');
        $customers->execute(['id' => $id]);

        return [
            'guest_default' => (int) $guestDefault->fetchColumn() > 0,
            'share_links' => (int) $shareLinks->fetchColumn(),
            'customers' => (int) $customers->fetchColumn(),
        ];
    }
}
