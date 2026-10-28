<?php

declare(strict_types=1);

namespace Portal\Services;

/**
 * Shared material facet options for ZIP download and dashboard filters.
 */
final class MaterialFilterOptionsService
{
    /**
     * @return array{options: array<string, mixed>, error: string|null}
     */
    public static function fetchFromApi(): array
    {
        $empty = [
            'materialTypes' => [],
            'ageCategories' => [],
            'manufacturers' => [],
            'sizeRanges' => [],
            'countryOfOrigins' => [],
            'stores' => [],
            'groups' => [],
        ];

        try {
            $filtersResponse = ApiClient::get('/api/materials/filter-options');
            if (!$filtersResponse['ok']) {
                return [
                    'options' => $empty,
                    'error' => 'تعذر جلب فلاتر المواد من API (رمز ' . (int) ($filtersResponse['status'] ?? 0) . ').',
                ];
            }

            $data = is_array($filtersResponse['data']) ? $filtersResponse['data'] : [];
            $stores = is_array($data['stores'] ?? null) ? $data['stores'] : (is_array($data['Stores'] ?? null) ? $data['Stores'] : []);
            $groups = is_array($data['groups'] ?? null) ? $data['groups'] : (is_array($data['Groups'] ?? null) ? $data['Groups'] : []);

            return [
                'options' => [
                    'materialTypes' => array_values(array_map('strval', is_array($data['materialTypes'] ?? null) ? $data['materialTypes'] : ($data['MaterialTypes'] ?? []))),
                    'ageCategories' => array_values(array_map('strval', is_array($data['ageCategories'] ?? null) ? $data['ageCategories'] : ($data['AgeCategories'] ?? []))),
                    'manufacturers' => array_values(array_map('strval', is_array($data['manufacturers'] ?? null) ? $data['manufacturers'] : ($data['Manufacturers'] ?? []))),
                    'sizeRanges' => array_values(array_map('strval', is_array($data['sizeRanges'] ?? null) ? $data['sizeRanges'] : ($data['SizeRanges'] ?? []))),
                    'countryOfOrigins' => array_values(array_map('strval', is_array($data['countryOfOrigins'] ?? null) ? $data['countryOfOrigins'] : ($data['CountryOfOrigins'] ?? []))),
                    'stores' => array_values(array_filter($stores, static fn ($row) => is_array($row))),
                    'groups' => array_values(array_filter($groups, static fn ($row) => is_array($row))),
                ],
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'options' => $empty,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
