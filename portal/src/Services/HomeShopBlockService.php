<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use Portal\Support\ResponseCache;
use PDO;
use Throwable;

final class HomeShopBlockService
{
    /** Store request params a block can be built from. */
    public const FACETS = [
        'ageCategories' => 'الفئة العمرية',
        'materialTypes' => 'نوع المادة',
        'manufacturers' => 'الشركة',
        'sizeRanges' => 'القياس',
        'countryOfOrigins' => 'بلد المنشأ',
    ];

    public const TYPES = [
        'facet' => 'حسب خاصية واحدة',
        'combo' => 'دمج خاصيتين',
        'custom' => 'بطاقات مخصّصة',
    ];

    public const STYLES = [
        'icons' => 'أيقونات',
        'chips' => 'أزرار',
        'cards' => 'بطاقات',
    ];

    public const PLACEMENTS = [
        'tabs' => 'تبويب داخل قسم «تسوّق حسب» المجمّع',
        'band' => 'شريط مستقل بعنوان المجموعة',
    ];

    public const MAX_CUSTOM_TILES = 24;

    private const CACHE_KEY = 'home_shop_blocks_v1';

    /** @return list<array<string, mixed>> */
    public static function activeBlocks(): array
    {
        try {
            return ResponseCache::remember(self::CACHE_KEY, 600, static fn (): array => self::loadActiveBlocks());
        } catch (Throwable) {
            return self::defaultBlocks();
        }
    }

    /** @return list<array<string, mixed>> */
    private static function loadActiveBlocks(): array
    {
        try {
            $rows = Database::pdo()->query(
                'SELECT * FROM home_shop_blocks ORDER BY sort_order, created_at'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return self::defaultBlocks();
        }
        if ($rows === []) {
            return self::defaultBlocks();
        }

        $blocks = [];
        foreach ($rows as $row) {
            $block = self::normalize($row);
            if ($block['is_active']) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    public static function tableReady(): bool
    {
        try {
            Database::pdo()->query('SELECT 1 FROM home_shop_blocks LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<array<string, mixed>> */
    public static function adminBlocks(): array
    {
        try {
            $rows = Database::pdo()->query(
                'SELECT * FROM home_shop_blocks ORDER BY sort_order, created_at'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }

        return array_map([self::class, 'normalize'], $rows);
    }

    /** @return array<string, mixed>|null */
    public static function getById(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        try {
            $stmt = Database::pdo()->prepare('SELECT * FROM home_shop_blocks WHERE id::text = :id');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }

        return is_array($row) ? self::normalize($row) : null;
    }

    /** @return array<string, mixed> */
    public static function blankBlock(): array
    {
        return self::normalize([
            'block_type' => 'facet',
            'primary_param' => 'manufacturers',
            'style' => 'chips',
            'placement' => 'tabs',
            'max_items' => 12,
            'is_active' => true,
        ]);
    }

    /**
     * Built-in blocks shown until an admin saves the first block.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultBlocks(): array
    {
        $defaults = [
            ['title_ar' => 'الفئة', 'block_type' => 'facet', 'primary_param' => 'ageCategories', 'style' => 'icons', 'max_items' => 16],
            ['title_ar' => 'النوع', 'block_type' => 'facet', 'primary_param' => 'materialTypes', 'style' => 'chips', 'max_items' => 16],
            ['title_ar' => 'الشركة', 'block_type' => 'facet', 'primary_param' => 'manufacturers', 'style' => 'cards', 'max_items' => 12],
            ['title_ar' => 'الشركة والفئة', 'block_type' => 'combo', 'primary_param' => 'manufacturers', 'secondary_param' => 'ageCategories', 'style' => 'icons', 'max_items' => 12],
            ['title_ar' => 'النوع والفئة', 'block_type' => 'combo', 'primary_param' => 'materialTypes', 'secondary_param' => 'ageCategories', 'style' => 'icons', 'max_items' => 12],
        ];
        $blocks = [];
        foreach ($defaults as $i => $row) {
            $row['id'] = 'default-' . ($i + 1);
            $row['sort_order'] = ($i + 1) * 10;
            $row['placement'] = 'tabs';
            $row['is_active'] = true;
            $blocks[] = self::normalize($row);
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalize(array $row): array
    {
        $type = (string) ($row['block_type'] ?? 'facet');
        $type = array_key_exists($type, self::TYPES) ? $type : 'facet';
        $primary = (string) ($row['primary_param'] ?? '');
        $primary = array_key_exists($primary, self::FACETS) ? $primary : '';
        $secondary = (string) ($row['secondary_param'] ?? '');
        $secondary = array_key_exists($secondary, self::FACETS) && $secondary !== $primary ? $secondary : '';
        $style = (string) ($row['style'] ?? 'icons');
        $placement = (string) ($row['placement'] ?? 'tabs');
        $isActive = $row['is_active'] ?? true;
        if (is_string($isActive)) {
            $isActive = in_array(strtolower($isActive), ['1', 't', 'true', 'on', 'yes'], true);
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
            'title_ar' => trim((string) ($row['title_ar'] ?? '')),
            'block_type' => $type,
            'primary_param' => $primary,
            'secondary_param' => $type === 'combo' ? $secondary : '',
            'primary_values' => self::stringList($row['primary_values'] ?? []),
            'secondary_values' => $type === 'combo' ? self::stringList($row['secondary_values'] ?? []) : [],
            'tiles' => $type === 'custom' ? self::normalizeTiles($row['tiles'] ?? []) : [],
            'style' => array_key_exists($style, self::STYLES) ? $style : 'icons',
            'placement' => array_key_exists($placement, self::PLACEMENTS) ? $placement : 'tabs',
            'max_items' => max(2, min(60, (int) ($row['max_items'] ?? 12))),
            'sort_order' => max(0, (int) ($row['sort_order'] ?? 0)),
            'is_active' => (bool) $isActive,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, message: string, id?: string}
     */
    public static function save(?string $id, array $input, ?string $updatedByUserId): array
    {
        $block = self::normalize($input);
        if ($block['title_ar'] === '') {
            return ['ok' => false, 'message' => 'العنوان مطلوب.'];
        }
        if ($block['block_type'] !== 'custom' && $block['primary_param'] === '') {
            return ['ok' => false, 'message' => 'اختر الخاصية.'];
        }
        if ($block['block_type'] === 'combo' && $block['secondary_param'] === '') {
            return ['ok' => false, 'message' => 'اختر خاصية ثانية مختلفة عن الأولى.'];
        }
        if ($block['block_type'] === 'custom' && $block['tiles'] === []) {
            return ['ok' => false, 'message' => 'أضف بطاقة واحدة على الأقل مع اسم وفلتر.'];
        }

        $params = [
            'title_ar' => mb_substr($block['title_ar'], 0, 200),
            'block_type' => $block['block_type'],
            'primary_param' => $block['block_type'] === 'custom' ? null : $block['primary_param'],
            'secondary_param' => $block['secondary_param'] !== '' ? $block['secondary_param'] : null,
            'primary_values' => self::json($block['block_type'] === 'custom' ? [] : $block['primary_values']),
            'secondary_values' => self::json($block['secondary_values']),
            'tiles' => self::json($block['tiles']),
            'style' => $block['style'],
            'placement' => $block['placement'],
            'max_items' => $block['max_items'],
            'sort_order' => $block['sort_order'],
            'is_active' => $block['is_active'] ? 1 : 0,
            'updated_by_user_id' => self::userId($updatedByUserId),
        ];

        try {
            $pdo = Database::pdo();
            $id = $id !== null ? trim($id) : '';
            if ($id === '') {
                $stmt = $pdo->prepare(
                    'INSERT INTO home_shop_blocks (
                        title_ar, block_type, primary_param, secondary_param, primary_values, secondary_values,
                        tiles, style, placement, max_items, sort_order, is_active, updated_by_user_id
                     ) VALUES (
                        :title_ar, :block_type, :primary_param, :secondary_param, CAST(:primary_values AS jsonb), CAST(:secondary_values AS jsonb),
                        CAST(:tiles AS jsonb), :style, :placement, :max_items, :sort_order,
                        CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END, :updated_by_user_id
                     )
                     RETURNING id::text'
                );
                $stmt->execute($params);
                $id = (string) $stmt->fetchColumn();
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE home_shop_blocks SET
                        title_ar = :title_ar, block_type = :block_type, primary_param = :primary_param,
                        secondary_param = :secondary_param, primary_values = CAST(:primary_values AS jsonb),
                        secondary_values = CAST(:secondary_values AS jsonb), tiles = CAST(:tiles AS jsonb),
                        style = :style, placement = :placement, max_items = :max_items, sort_order = :sort_order,
                        is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                        updated_by_user_id = :updated_by_user_id, updated_at = NOW()
                     WHERE id::text = :id'
                );
                $stmt->execute($params + ['id' => $id]);
                if ($stmt->rowCount() === 0) {
                    return ['ok' => false, 'message' => 'المجموعة غير موجودة.'];
                }
            }
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'تعذر الحفظ. تأكد من تشغيل ترحيل قاعدة البيانات.'];
        }
        self::forgetCache();

        return ['ok' => true, 'message' => 'تم الحفظ.', 'id' => $id];
    }

    public static function setActive(string $id, bool $isActive, ?string $updatedByUserId): bool
    {
        try {
            $stmt = Database::pdo()->prepare(
                'UPDATE home_shop_blocks
                 SET is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                     updated_at = NOW(), updated_by_user_id = :updated_by_user_id
                 WHERE id::text = :id'
            );
            $stmt->execute(['id' => trim($id), 'is_active' => $isActive ? 1 : 0, 'updated_by_user_id' => self::userId($updatedByUserId)]);
        } catch (Throwable) {
            return false;
        }
        self::forgetCache();

        return $stmt->rowCount() > 0;
    }

    public static function delete(string $id): bool
    {
        try {
            $stmt = Database::pdo()->prepare('DELETE FROM home_shop_blocks WHERE id::text = :id');
            $stmt->execute(['id' => trim($id)]);
        } catch (Throwable) {
            return false;
        }
        self::forgetCache();

        return $stmt->rowCount() > 0;
    }

    /** Copies the built-in blocks into the table so they can be edited. */
    public static function seedDefaults(?string $updatedByUserId): bool
    {
        if (self::adminBlocks() !== []) {
            return false;
        }
        foreach (self::defaultBlocks() as $block) {
            $result = self::save(null, $block, $updatedByUserId);
            if (!$result['ok']) {
                return false;
            }
        }

        return true;
    }

    public static function forgetCache(): void
    {
        ResponseCache::forget(self::CACHE_KEY);
    }

    /**
     * @return list<array{label: string, image: string, filters: array<string, string>, available: bool}>
     */
    private static function normalizeTiles(mixed $tiles): array
    {
        if (is_string($tiles)) {
            $tiles = json_decode($tiles, true);
        }
        if (!is_array($tiles)) {
            return [];
        }
        $result = [];
        foreach ($tiles as $tile) {
            if (!is_array($tile)) {
                continue;
            }
            $label = trim((string) ($tile['label'] ?? ''));
            $filters = [];
            foreach (self::FACETS as $param => $_) {
                $value = trim((string) (($tile['filters'] ?? [])[$param] ?? ''));
                if ($value !== '') {
                    $filters[$param] = $value;
                }
            }
            $available = !empty($tile['available']);
            if ($label === '' || ($filters === [] && !$available)) {
                continue;
            }
            $result[] = [
                'label' => mb_substr($label, 0, 80),
                'image' => mb_substr(trim((string) ($tile['image'] ?? '')), 0, 1000),
                'filters' => $filters,
                'available' => $available,
            ];
            if (count($result) >= self::MAX_CUSTOM_TILES) {
                break;
            }
        }

        return $result;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[\n,|]+/u', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '' && !in_array($item, $result, true)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    private static function userId(?string $id): ?string
    {
        $id = $id !== null ? trim($id) : '';

        return $id !== '' ? $id : null;
    }
}
