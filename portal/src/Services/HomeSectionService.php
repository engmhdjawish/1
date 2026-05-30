<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

final class HomeSectionService
{
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
}
