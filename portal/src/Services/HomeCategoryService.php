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

    /** @var array{version: int, count: int, groups: array<string, string>, icons: list<array{key: string, label_ar: string, group: string, tags?: list<string>}>}|null */
    private static ?array $iconLibraryPayload = null;

    public static function iconLibraryAssetUrl(): string
    {
        return '/assets/home-category-icons.json';
    }

    public static function iconLibraryCount(): int
    {
        $path = self::iconLibraryPath();
        if (!is_file($path)) {
            return count(self::SUGGESTED_ICONS);
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return count(self::SUGGESTED_ICONS);
        }

        if (preg_match('/"count"\s*:\s*(\d+)/', $raw, $matches) === 1) {
            return max(0, (int) $matches[1]);
        }

        return count(self::SUGGESTED_ICONS);
    }

    public static function iconLibraryPath(): string
    {
        return dirname(__DIR__, 2) . '/public/assets/home-category-icons.json';
    }

    /** @return array{version: int, count: int, groups: array<string, string>, icons: list<array{key: string, label_ar: string, group: string, tags?: list<string>}>} */
    public static function iconLibraryPayload(): array
    {
        if (self::$iconLibraryPayload !== null) {
            return self::$iconLibraryPayload;
        }

        $path = self::iconLibraryPath();
        if (!is_file($path)) {
            self::$iconLibraryPayload = [
                'version' => 1,
                'count' => count(self::fallbackIconLibrary()),
                'groups' => self::fallbackIconGroupLabels(),
                'icons' => self::fallbackIconLibrary(),
            ];

            return self::$iconLibraryPayload;
        }

        try {
            $raw = file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
            if (!is_array($decoded) || !is_array($decoded['icons'] ?? null)) {
                throw new \JsonException('Invalid icon library payload.');
            }

            $icons = [];
            foreach ($decoded['icons'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $rawKey = trim((string) ($item['key'] ?? ''));
                if ($rawKey === '') {
                    continue;
                }
                $key = self::normalizeIconKey($rawKey);
                $icons[] = [
                    'key' => $key,
                    'label_ar' => trim((string) ($item['label_ar'] ?? '')) ?: $key,
                    'group' => trim((string) ($item['group'] ?? 'general')) ?: 'general',
                    'tags' => is_array($item['tags'] ?? null) ? array_values($item['tags']) : [],
                ];
            }

            self::$iconLibraryPayload = [
                'version' => (int) ($decoded['version'] ?? 1),
                'count' => count($icons),
                'groups' => is_array($decoded['groups'] ?? null) ? $decoded['groups'] : self::fallbackIconGroupLabels(),
                'icons' => $icons,
            ];
        } catch (\Throwable) {
            self::$iconLibraryPayload = [
                'version' => 1,
                'count' => count(self::fallbackIconLibrary()),
                'groups' => self::fallbackIconGroupLabels(),
                'icons' => self::fallbackIconLibrary(),
            ];
        }

        return self::$iconLibraryPayload;
    }

    /**
     * @return list<array{key: string, label_ar: string, group: string, tags?: list<string>}>
     */
    public static function iconLibrary(): array
    {
        return self::iconLibraryPayload()['icons'];
    }

    /** @return array<string, string> */
    public static function iconGroupLabels(): array
    {
        return self::iconLibraryPayload()['groups'];
    }

    public static function iconLabel(string $iconKey): string
    {
        $iconKey = self::normalizeIconKey($iconKey);
        foreach (self::iconLibrary() as $item) {
            if (($item['key'] ?? '') === $iconKey) {
                return (string) ($item['label_ar'] ?? $iconKey);
            }
        }

        return 'أيقونة';
    }

    /** @return list<array{key: string, label_ar: string, group: string}> */
    private static function fallbackIconLibrary(): array
    {
        $icons = [];
        foreach (self::SUGGESTED_ICONS as $key) {
            $icons[] = [
                'key' => $key,
                'label_ar' => self::iconLabelFromKey($key),
                'group' => 'general',
            ];
        }

        return $icons;
    }

    /** @return array<string, string> */
    private static function fallbackIconGroupLabels(): array
    {
        return [
            'footwear' => 'أنواع الأحذية',
            'age' => 'الفئات العمرية',
            'season' => 'الفصول',
            'promo' => 'عروض وتسوّق',
            'origin' => 'منشأ وتصنيف',
            'general' => 'أخرى مفيدة',
        ];
    }

    private static function iconLabelFromKey(string $key): string
    {
        return 'أيقونة';
    }

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
