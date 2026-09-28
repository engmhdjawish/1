<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

final class HomeCategoryService
{
    /** @var list<string> */
    public const SUGGESTED_ICONS = [
        'storefront',
        'steps',
        'hiking',
        'footprint',
        'local_fire_department',
        'man',
        'woman',
        'child_care',
        'category',
        'sell',
        'inventory_2',
        'shopping_bag',
    ];

    /** @return list<array<string, mixed>> */
    public static function activeCategories(): array
    {
        try {
            $rows = Database::pdo()->query(
                'SELECT id::text AS id, label_ar, icon_key, link_url, sort_order
                 FROM home_categories
                 WHERE is_active = TRUE
                 ORDER BY sort_order ASC, created_at ASC'
            )->fetchAll(PDO::FETCH_ASSOC);

            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    public static function adminCategories(): array
    {
        try {
            $rows = Database::pdo()->query(
                'SELECT
                    id::text AS id,
                    label_ar,
                    icon_key,
                    link_url,
                    sort_order,
                    CASE WHEN is_active THEN 1 ELSE 0 END AS is_active,
                    updated_at
                 FROM home_categories
                 ORDER BY sort_order ASC, created_at ASC'
            )->fetchAll(PDO::FETCH_ASSOC);

            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public static function getById(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        try {
            $stmt = Database::pdo()->prepare(
                'SELECT
                    id::text AS id,
                    label_ar,
                    icon_key,
                    link_url,
                    sort_order,
                    CASE WHEN is_active THEN 1 ELSE 0 END AS is_active
                 FROM home_categories
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row === false ? null : $row;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{ok: bool, message: string, id?: string} */
    public static function saveCategory(
        ?string $id,
        string $labelAr,
        string $iconKey,
        string $linkUrl,
        int $sortOrder,
        bool $isActive
    ): array {
        $labelAr = trim($labelAr);
        if ($labelAr === '') {
            return ['ok' => false, 'message' => 'اسم الفئة مطلوب.'];
        }

        $iconKey = self::normalizeIconKey($iconKey);
        $linkUrl = self::normalizeLinkUrl($linkUrl);
        $sortOrder = max(0, $sortOrder);
        $id = $id !== null ? trim($id) : '';

        try {
            $pdo = Database::pdo();
            if ($id === '') {
                $stmt = $pdo->prepare(
                    'INSERT INTO home_categories (label_ar, icon_key, link_url, sort_order, is_active)
                     VALUES (:label_ar, :icon_key, :link_url, :sort_order, CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END)
                     RETURNING id::text'
                );
                $stmt->execute([
                    'label_ar' => $labelAr,
                    'icon_key' => $iconKey,
                    'link_url' => $linkUrl,
                    'sort_order' => $sortOrder,
                    'is_active' => $isActive ? 1 : 0,
                ]);

                return [
                    'ok' => true,
                    'message' => 'تم إنشاء الفئة.',
                    'id' => (string) $stmt->fetchColumn(),
                ];
            }

            $stmt = $pdo->prepare(
                'UPDATE home_categories SET
                    label_ar = :label_ar,
                    icon_key = :icon_key,
                    link_url = :link_url,
                    sort_order = :sort_order,
                    is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                    updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'label_ar' => $labelAr,
                'icon_key' => $iconKey,
                'link_url' => $linkUrl,
                'sort_order' => $sortOrder,
                'is_active' => $isActive ? 1 : 0,
            ]);

            if ($stmt->rowCount() === 0) {
                return ['ok' => false, 'message' => 'الفئة غير موجودة.'];
            }

            return ['ok' => true, 'message' => 'تم تحديث الفئة.', 'id' => $id];
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'تعذر حفظ الفئة.'];
        }
    }

    public static function setActive(string $id, bool $isActive): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }

        try {
            $stmt = Database::pdo()->prepare(
                'UPDATE home_categories
                 SET is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'is_active' => $isActive ? 1 : 0,
            ]);

            return $stmt->rowCount() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{ok: bool, message: string} */
    public static function deleteCategory(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return ['ok' => false, 'message' => 'المعرّف غير صالح.'];
        }

        try {
            $stmt = Database::pdo()->prepare('DELETE FROM home_categories WHERE id = :id');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() === 0) {
                return ['ok' => false, 'message' => 'الفئة غير موجودة أو تم حذفها مسبقًا.'];
            }

            return ['ok' => true, 'message' => 'تم حذف الفئة.'];
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'تعذر حذف الفئة.'];
        }
    }

    public static function normalizeIconKey(string $iconKey): string
    {
        $iconKey = strtolower(trim($iconKey));
        $iconKey = preg_replace('/[^a-z0-9_]/', '', $iconKey) ?? '';

        return $iconKey !== '' ? $iconKey : 'category';
    }

    public static function normalizeLinkUrl(string $linkUrl): string
    {
        $linkUrl = trim($linkUrl);
        if ($linkUrl === '') {
            return '/store.php';
        }

        if (preg_match('#^https?://#i', $linkUrl) === 1) {
            return $linkUrl;
        }

        return str_starts_with($linkUrl, '/') ? $linkUrl : '/' . ltrim($linkUrl, '/');
    }
}
