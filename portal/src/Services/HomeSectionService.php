<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

final class HomeSectionService
{
    private const DISPLAY_MODES = ['manual', 'filter'];

    /** @return list<array<string, mixed>> */
    public static function activeSections(): array
    {
        $pdo = Database::pdo();
        $sections = $pdo->query(
            'SELECT id, slug, title_ar, subtitle_ar, banner_image_url, display_mode, max_products
             FROM home_sections WHERE is_active = TRUE ORDER BY sort_order ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sections as &$section) {
            $section['filters'] = self::filtersForSection($section['id']);
            $section['material_guids'] = self::manualProducts($section['id']);
            $section['products'] = self::loadProducts($section);
        }

        return $sections;
    }

    /** @return list<array<string, mixed>> */
    public static function adminSections(): array
    {
        return Database::pdo()->query(
            'SELECT
                hs.id::text AS id,
                hs.slug,
                hs.title_ar,
                hs.subtitle_ar,
                hs.banner_image_url,
                hs.display_mode::text AS display_mode,
                hs.max_products,
                hs.sort_order,
                CASE WHEN hs.is_active THEN 1 ELSE 0 END AS is_active,
                hs.updated_at,
                COALESCE(filters.filters_count, 0)::int AS filters_count,
                COALESCE(products.products_count, 0)::int AS products_count
             FROM home_sections hs
             LEFT JOIN (
                SELECT section_id, COUNT(*)::int AS filters_count
                FROM home_section_filters
                GROUP BY section_id
             ) filters ON filters.section_id = hs.id
             LEFT JOIN (
                SELECT section_id, COUNT(*)::int AS products_count
                FROM home_section_products
                GROUP BY section_id
             ) products ON products.section_id = hs.id
             ORDER BY hs.sort_order ASC, hs.created_at ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{total: int, active: int, manual: int, filter: int} */
    public static function stats(): array
    {
        $row = Database::pdo()->query(
            'SELECT
                COUNT(*)::int AS total,
                COUNT(*) FILTER (WHERE is_active = TRUE)::int AS active,
                COUNT(*) FILTER (WHERE display_mode = \'manual\')::int AS manual,
                COUNT(*) FILTER (WHERE display_mode = \'filter\')::int AS filter
             FROM home_sections'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'manual' => (int) ($row['manual'] ?? 0),
            'filter' => (int) ($row['filter'] ?? 0),
        ];
    }

    public static function getSectionById(string $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                id::text AS id,
                slug,
                title_ar,
                subtitle_ar,
                banner_image_url,
                display_mode::text AS display_mode,
                max_products,
                sort_order,
                CASE WHEN is_active THEN 1 ELSE 0 END AS is_active
             FROM home_sections
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $row['filters'] = self::sectionFilters($id);
        $row['material_guids'] = self::manualProducts($id);

        return $row;
    }

    /** @return array{ok: bool, message: string, id?: string} */
    public static function saveSection(
        ?string $id,
        string $slug,
        string $titleAr,
        ?string $subtitleAr,
        ?string $bannerImageUrl,
        string $displayMode,
        int $maxProducts,
        int $sortOrder,
        bool $isActive,
        ?string $updatedByUserId
    ): array {
        $titleAr = trim($titleAr);
        if ($titleAr === '') {
            return ['ok' => false, 'message' => 'عنوان القسم مطلوب.'];
        }

        $slug = self::normalizeSlug($slug !== '' ? $slug : $titleAr);
        if ($slug === '') {
            $slug = 'section-' . date('His');
        }

        $displayMode = in_array($displayMode, self::DISPLAY_MODES, true) ? $displayMode : 'filter';
        $maxProducts = max(1, min(48, $maxProducts));
        $sortOrder = max(0, $sortOrder);
        $subtitleAr = trim((string) $subtitleAr);
        $bannerImageUrl = trim((string) $bannerImageUrl);
        $updatedByUserId = $updatedByUserId !== null ? trim($updatedByUserId) : null;
        $updatedByUserId = $updatedByUserId !== '' ? $updatedByUserId : null;

        $pdo = Database::pdo();
        $duplicate = $pdo->prepare(
            'SELECT 1
             FROM home_sections
             WHERE slug = :slug
               AND (:exclude_id_is_empty = \'\' OR id::text <> :exclude_id_value)
             LIMIT 1'
        );
        $duplicate->execute([
            'slug' => $slug,
            'exclude_id_is_empty' => $id !== null ? trim($id) : '',
            'exclude_id_value' => $id !== null ? trim($id) : '',
        ]);
        if ($duplicate->fetchColumn()) {
            return ['ok' => false, 'message' => 'Slug مستخدم مسبقًا.'];
        }

        if ($id === null || trim($id) === '') {
            $insert = $pdo->prepare(
                'INSERT INTO home_sections (
                    slug,
                    title_ar,
                    subtitle_ar,
                    banner_image_url,
                    display_mode,
                    max_products,
                    sort_order,
                    is_active,
                    updated_by_user_id
                 ) VALUES (
                    :slug,
                    :title_ar,
                    :subtitle_ar,
                    :banner_image_url,
                    :display_mode,
                    :max_products,
                    :sort_order,
                    :is_active,
                    :updated_by_user_id
                 )
                 RETURNING id::text'
            );
            $insert->execute([
                'slug' => $slug,
                'title_ar' => $titleAr,
                'subtitle_ar' => $subtitleAr !== '' ? $subtitleAr : null,
                'banner_image_url' => $bannerImageUrl !== '' ? $bannerImageUrl : null,
                'display_mode' => $displayMode,
                'max_products' => $maxProducts,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
                'updated_by_user_id' => $updatedByUserId,
            ]);

            return [
                'ok' => true,
                'message' => 'تم إنشاء القسم.',
                'id' => (string) $insert->fetchColumn(),
            ];
        }

        $update = $pdo->prepare(
            'UPDATE home_sections SET
                slug = :slug,
                title_ar = :title_ar,
                subtitle_ar = :subtitle_ar,
                banner_image_url = :banner_image_url,
                display_mode = :display_mode,
                max_products = :max_products,
                sort_order = :sort_order,
                is_active = :is_active,
                updated_by_user_id = :updated_by_user_id,
                updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute([
            'id' => $id,
            'slug' => $slug,
            'title_ar' => $titleAr,
            'subtitle_ar' => $subtitleAr !== '' ? $subtitleAr : null,
            'banner_image_url' => $bannerImageUrl !== '' ? $bannerImageUrl : null,
            'display_mode' => $displayMode,
            'max_products' => $maxProducts,
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
            'updated_by_user_id' => $updatedByUserId,
        ]);

        return ['ok' => true, 'message' => 'تم تحديث القسم.', 'id' => $id];
    }

    public static function setActive(string $id, bool $isActive, ?string $updatedByUserId): bool
    {
        $updatedByUserId = $updatedByUserId !== null ? trim($updatedByUserId) : null;
        $updatedByUserId = $updatedByUserId !== '' ? $updatedByUserId : null;

        $stmt = Database::pdo()->prepare(
            'UPDATE home_sections
             SET is_active = :is_active, updated_at = NOW(), updated_by_user_id = :updated_by_user_id
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'is_active' => $isActive,
            'updated_by_user_id' => $updatedByUserId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /** @return array{ok: bool, message: string} */
    public static function addFilter(string $sectionId, string $filterType, string $valueAr): array
    {
        $filterType = trim($filterType);
        $valueAr = trim($valueAr);
        if ($filterType === '' || $valueAr === '') {
            return ['ok' => false, 'message' => 'نوع الفلتر وقيمته مطلوبان.'];
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO home_section_filters (section_id, filter_type, value_ar)
             VALUES (:section_id, :filter_type, :value_ar)
             ON CONFLICT (section_id, filter_type, value_ar) DO NOTHING'
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'filter_type' => $filterType,
            'value_ar' => $valueAr,
        ]);

        return ['ok' => true, 'message' => 'تمت إضافة الفلتر.'];
    }

    public static function removeFilter(string $filterId): bool
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM home_section_filters WHERE id = :id'
        );
        $stmt->execute(['id' => $filterId]);

        return $stmt->rowCount() > 0;
    }

    /** @return list<array{id: string, filter_type: string, value_ar: string}> */
    public static function sectionFilters(string $sectionId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id::text AS id, filter_type, value_ar
             FROM home_section_filters
             WHERE section_id = :section_id
             ORDER BY filter_type, value_ar'
        );
        $stmt->execute(['section_id' => $sectionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{filter_type: string, value_ar: string}> */
    private static function filtersForSection(string $sectionId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT filter_type, value_ar FROM home_section_filters WHERE section_id = :id'
        );
        $stmt->execute(['id' => $sectionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<string> */
    private static function manualProducts(string $sectionId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT material_guid::text FROM home_section_products
             WHERE section_id = :id ORDER BY sort_order ASC'
        );
        $stmt->execute(['id' => $sectionId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param array<string, mixed> $section */
    private static function loadProducts(array $section): array
    {
        $query = ['page' => 1, 'pageSize' => (int) $section['max_products']];

        if ($section['display_mode'] === 'manual' && !empty($section['material_guids'])) {
            $query['keyword'] = implode(' ', $section['material_guids']);
        } else {
            foreach ($section['filters'] as $filter) {
                $type = $filter['filter_type'];
                $value = $filter['value_ar'];
                match ($type) {
                    'keyword' => $query['keyword'] = $value,
                    'material_type' => $query['materialTypes'] = self::appendCsv($query['materialTypes'] ?? null, $value),
                    'manufacturer' => $query['manufacturers'] = self::appendCsv($query['manufacturers'] ?? null, $value),
                    'target_category', 'age_category' => $query['ageCategories'] = self::appendCsv($query['ageCategories'] ?? null, $value),
                    default => null,
                };
            }
        }

        try {
            $result = ApiClient::get('/api/materials', $query);
            if (!$result['ok']) {
                return [];
            }

            return $result['data']['items'] ?? $result['data']['data'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private static function appendCsv(?string $existing, string $value): string
    {
        if ($existing === null || $existing === '') {
            return $value;
        }

        return $existing . ',' . $value;
    }

    private static function normalizeSlug(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $value = preg_replace('/[\s_]+/u', '-', $value) ?? '';
        $value = preg_replace('/[^a-z0-9\-]/u', '', $value) ?? '';
        $value = trim($value, '-');

        return $value;
    }
}
