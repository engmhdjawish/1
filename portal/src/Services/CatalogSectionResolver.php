<?php

declare(strict_types=1);

namespace Portal\Services;

/** Resolves homepage / offer sections for store deep-links. */
final class CatalogSectionResolver
{
    public static function resolve(?string $homeSlug, ?string $offerSlug): ?array
    {
        $homeSlug = trim((string) $homeSlug);
        $offerSlug = trim((string) $offerSlug);

        if ($homeSlug !== '') {
            $section = HomeSectionService::storeContextBySlug($homeSlug);
            if ($section !== null) {
                return $section;
            }
        }

        if ($offerSlug !== '') {
            return SpecialOfferService::storeContextBySlug($offerSlug);
        }

        return null;
    }

    /** @param array<string, mixed> $section @return array<string, string> */
    public static function storeLinkParams(array $section): array
    {
        $slug = trim((string) ($section['slug'] ?? ''));
        if ($slug === '') {
            return [];
        }

        if (!empty($section['is_offer_section'])) {
            return ['offer' => $slug];
        }

        return ['section' => $slug];
    }

    /** Human-readable chips for section filter rules shown in the store UI. */
    public static function filterSummaryLabels(array $rules): array
    {
        $items = [];
        $keyword = trim((string) ($rules['keyword'] ?? ''));
        if ($keyword !== '') {
            $items[] = ['label' => 'بحث', 'value' => $keyword];
        }

        $appendList = static function (string $label, mixed $values) use (&$items): void {
            if (!is_array($values)) {
                return;
            }
            foreach ($values as $value) {
                $text = trim((string) $value);
                if ($text !== '') {
                    $items[] = ['label' => $label, 'value' => $text];
                }
            }
        };

        $appendList('نوع المادة', $rules['material_types'] ?? []);
        $appendList('فئة عمرية', $rules['age_categories'] ?? []);
        $appendList('الشركة', $rules['manufacturers'] ?? []);
        $appendList('القياس', $rules['size_ranges'] ?? []);
        $appendList('بلد المنشأ', $rules['country_origins'] ?? []);

        if (($rules['is_available'] ?? null) === true) {
            $items[] = ['label' => 'التوفر', 'value' => 'متوفر فقط'];
        } elseif (($rules['is_available'] ?? null) === false) {
            $items[] = ['label' => 'التوفر', 'value' => 'غير متوفر فقط'];
        }

        if (($rules['has_image'] ?? null) === true) {
            $items[] = ['label' => 'الصورة', 'value' => 'مع صورة فقط'];
        }

        foreach ([
            'min_warehouse_quantity' => 'حد أدنى للمخزون',
            'max_warehouse_quantity' => 'حد أقصى للمخزون',
            'min_unit_sale_price_syp' => 'حد أدنى سعر (ل.س)',
            'max_unit_sale_price_syp' => 'حد أقصى سعر (ل.س)',
            'min_unit_sale_price_usd' => 'حد أدنى سعر ($)',
            'max_unit_sale_price_usd' => 'حد أقصى سعر ($)',
        ] as $key => $label) {
            $value = $rules[$key] ?? null;
            if ($value !== null && $value !== '') {
                $items[] = ['label' => $label, 'value' => (string) $value];
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, string|int>
     */
    public static function apiQueryFromRules(array $rules, int $page, int $pageSize, string $sort): array
    {
        $query = [
            'page' => max(1, $page),
            'pageSize' => max(1, $pageSize),
            'sort' => $sort,
        ];

        $keyword = trim((string) ($rules['keyword'] ?? ''));
        if ($keyword !== '') {
            $query['search'] = $keyword;
        }

        $appendCsv = static function (?string $existing, string $value): string {
            return $existing === null || $existing === '' ? $value : $existing . ',' . $value;
        };

        foreach ($rules['material_types'] ?? [] as $value) {
            $query['materialTypes'] = $appendCsv(isset($query['materialTypes']) ? (string) $query['materialTypes'] : null, (string) $value);
        }
        foreach ($rules['age_categories'] ?? [] as $value) {
            $query['ageCategories'] = $appendCsv(isset($query['ageCategories']) ? (string) $query['ageCategories'] : null, (string) $value);
        }
        foreach ($rules['manufacturers'] ?? [] as $value) {
            $query['manufacturers'] = $appendCsv(isset($query['manufacturers']) ? (string) $query['manufacturers'] : null, (string) $value);
        }
        foreach ($rules['size_ranges'] ?? [] as $value) {
            $query['sizeRanges'] = $appendCsv(isset($query['sizeRanges']) ? (string) $query['sizeRanges'] : null, (string) $value);
        }
        foreach ($rules['country_origins'] ?? [] as $value) {
            $query['countryOfOrigins'] = $appendCsv(isset($query['countryOfOrigins']) ? (string) $query['countryOfOrigins'] : null, (string) $value);
        }
        foreach ($rules['store_guids'] ?? [] as $value) {
            $query['storeGuids'] = $appendCsv(isset($query['storeGuids']) ? (string) $query['storeGuids'] : null, (string) $value);
        }
        foreach ($rules['group_guids'] ?? [] as $value) {
            $query['groupGuids'] = $appendCsv(isset($query['groupGuids']) ? (string) $query['groupGuids'] : null, (string) $value);
        }

        if (($rules['is_available'] ?? null) === true) {
            $query['isAvailable'] = 'true';
        } elseif (($rules['is_available'] ?? null) === false) {
            $query['isAvailable'] = 'false';
        }

        if (($rules['has_image'] ?? null) === true) {
            $query['hasImage'] = 'true';
        } elseif (($rules['has_image'] ?? null) === false) {
            $query['hasImage'] = 'false';
        }

        foreach ([
            'min_warehouse_quantity' => 'minWarehouseQuantity',
            'max_warehouse_quantity' => 'maxWarehouseQuantity',
            'min_unit_sale_price_syp' => 'minUnitSalePriceSyp',
            'max_unit_sale_price_syp' => 'maxUnitSalePriceSyp',
            'min_unit_sale_price_usd' => 'minUnitSalePriceUsd',
            'max_unit_sale_price_usd' => 'maxUnitSalePriceUsd',
            'min_unit_purchase_price_usd' => 'minUnitPurchasePriceUsd',
            'max_unit_purchase_price_usd' => 'maxUnitPurchasePriceUsd',
        ] as $ruleKey => $apiKey) {
            $value = $rules[$ruleKey] ?? null;
            if ($value !== null && $value !== '') {
                $query[$apiKey] = (string) $value;
            }
        }

        return $query;
    }
}
