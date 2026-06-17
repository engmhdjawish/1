<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Auth\CustomerSession;

final class StoreCatalogService
{
    /** @return array{show_price: bool, show_quantity: bool, allow_cart: bool, allow_order: bool, show_images: bool, price_mode: string} */
    public static function displayOptions(): array
    {
        $policy = self::activePolicy();
        if ($policy === null) {
            return [
                'show_price' => false,
                'show_quantity' => false,
                'allow_cart' => false,
                'allow_order' => false,
                'show_images' => true,
                'price_mode' => 'none',
            ];
        }

        $showPrice = (bool) ($policy['show_price'] ?? false);

        return [
            'show_price' => $showPrice,
            'show_quantity' => (bool) ($policy['show_quantity'] ?? false),
            'allow_cart' => (bool) ($policy['allow_cart'] ?? false),
            'allow_order' => (bool) ($policy['allow_order'] ?? false),
            'show_images' => true,
            'price_mode' => $showPrice ? 'both' : 'none',
        ];
    }

    /** @return array<string, mixed>|null */
    public static function activePolicy(): ?array
    {
        if (CustomerSession::check()) {
            $customer = CustomerSession::customer();

            return [
                'show_price' => (bool) ($customer['show_price'] ?? false),
                'show_quantity' => (bool) ($customer['show_quantity'] ?? false),
                'allow_cart' => (bool) ($customer['allow_cart'] ?? false),
                'allow_order' => (bool) ($customer['allow_order'] ?? false),
                'name_ar' => 'عميل مسجّل',
            ];
        }

        return StorePolicyService::guestPolicy();
    }

    /** @param array<string, mixed> $query */
    public static function catalogFromRequest(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $pageSize = 24;
        $search = trim((string) ($query['q'] ?? $query['search'] ?? ''));
        $sort = self::normalizeSort($query['sort'] ?? 'number:asc');
        $sectionContext = CatalogSectionResolver::resolve(
            trim((string) ($query['section'] ?? '')),
            trim((string) ($query['offer'] ?? ''))
        );

        $materialTypes = self::parseList($query['materialTypes'] ?? []);
        $manufacturers = self::parseList($query['manufacturers'] ?? []);
        $ageCategories = self::parseList($query['ageCategories'] ?? []);
        $sizeRanges = self::parseList($query['sizeRanges'] ?? []);
        $countryOfOrigins = self::parseList($query['countryOfOrigins'] ?? []);
        $groupGuids = self::parseList($query['groupGuids'] ?? []);
        $storeGuids = self::parseList($query['storeGuids'] ?? []);
        $isAvailable = self::parseNullableBool($query['isAvailable'] ?? null);
        $hasImage = self::parseNullableBool($query['hasImage'] ?? null);

        if ($sectionContext !== null && $materialTypes === [] && $manufacturers === [] && $search === '') {
            if ((string) ($sectionContext['selection_mode'] ?? '') === 'manual') {
                return self::catalogFromManualSection($sectionContext, $page, $pageSize, $sort, $search);
            }

            $rules = is_array($sectionContext['filter_rules'] ?? null) ? $sectionContext['filter_rules'] : [];
            $materialTypes = self::parseList($rules['material_types'] ?? []);
            $manufacturers = self::parseList($rules['manufacturers'] ?? []);
            $ageCategories = self::parseList($rules['age_categories'] ?? []);
            $sizeRanges = self::parseList($rules['size_ranges'] ?? []);
            $countryOfOrigins = self::parseList($rules['country_origins'] ?? []);
            $groupGuids = self::parseList($rules['group_guids'] ?? []);
            $storeGuids = self::parseList($rules['store_guids'] ?? []);
            if ($search === '' && trim((string) ($rules['keyword'] ?? '')) !== '') {
                $search = trim((string) $rules['keyword']);
            }
            if ($isAvailable === null && array_key_exists('is_available', $rules)) {
                $isAvailable = $rules['is_available'];
            }
            if ($hasImage === null && array_key_exists('has_image', $rules)) {
                $hasImage = $rules['has_image'];
            }
        }

        $products = [];
        $totalCount = 0;
        $resultFilters = [];
        $apiError = null;

        if (self::activePolicy() === null) {
            return self::emptyCatalogResult($page, $pageSize, 'لم تُضبط سياسة عرض المتجر بعد.');
        }

        try {
            $materials = self::fetchMaterialsExtended(
                $page,
                $pageSize,
                $search,
                $sort,
                $materialTypes,
                $manufacturers,
                $ageCategories,
                $sizeRanges,
                $countryOfOrigins,
                $groupGuids,
                $storeGuids,
                $isAvailable,
                $hasImage
            );
            if ($materials['ok']) {
                $data = is_array($materials['data'] ?? null) ? $materials['data'] : [];
                $products = self::withOfferPricing(
                    is_array($data['items'] ?? null) ? $data['items'] : []
                );
                $totalCount = max(0, (int) ($data['totalCount'] ?? 0));
                $page = max(1, (int) ($data['page'] ?? $page));
                $pageSize = max(1, (int) ($data['pageSize'] ?? $pageSize));
                $resultFilters = is_array($data['resultFilters'] ?? null) ? $data['resultFilters'] : [];
            } else {
                $apiError = self::extractApiError($materials);
            }

            if ($resultFilters === []) {
                $resultFilters = self::loadStaticResultFilters();
            }
        } catch (\Throwable $exception) {
            $apiError = $exception->getMessage();
        }

        $totalPages = max(1, (int) ceil($totalCount / max(1, $pageSize)));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        return [
            'products' => $products,
            'totalCount' => $totalCount,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => $totalPages,
            'rangeStart' => $totalCount === 0 ? 0 : (($page - 1) * $pageSize + 1),
            'rangeEnd' => min($totalCount, $page * $pageSize),
            'resultFilters' => $resultFilters,
            'apiError' => $apiError,
            'section_context' => $sectionContext,
            'filters' => self::buildFiltersState(
                $search,
                $sort,
                $materialTypes,
                $manufacturers,
                $ageCategories,
                $sizeRanges,
                $countryOfOrigins,
                $groupGuids,
                $storeGuids,
                $isAvailable,
                $hasImage,
                $sectionContext
            ),
        ];
    }

    /** @param array<string, mixed> $sectionContext */
    private static function catalogFromManualSection(
        array $sectionContext,
        int $page,
        int $pageSize,
        string $sort,
        string $search
    ): array {
        if (self::activePolicy() === null) {
            return self::emptyCatalogResult($page, $pageSize, 'لم تُضبط سياسة عرض المتجر بعد.');
        }

        $guids = is_array($sectionContext['material_guids'] ?? null) ? $sectionContext['material_guids'] : [];
        $guids = array_values(array_unique(array_filter(array_map('strval', $guids), static fn (string $g): bool => trim($g) !== '')));

        $products = [];
        foreach ($guids as $guid) {
            try {
                $material = self::findMaterial($guid);
                if ($material === null) {
                    continue;
                }
                if ($search !== '') {
                    $hay = strtolower(
                        trim((string) ($material['name'] ?? '')) . ' '
                        . trim((string) ($material['materialCode'] ?? ''))
                    );
                    if (!str_contains($hay, strtolower($search))) {
                        continue;
                    }
                }
                $products[] = $material;
            } catch (\Throwable) {
                continue;
            }
        }

        $totalCount = count($products);
        $totalPages = max(1, (int) ceil($totalCount / max(1, $pageSize)));
        $page = min(max(1, $page), $totalPages);
        $offset = ($page - 1) * $pageSize;
        $products = array_slice($products, $offset, $pageSize);

        return [
            'products' => $products,
            'totalCount' => $totalCount,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => $totalPages,
            'rangeStart' => $totalCount === 0 ? 0 : ($offset + 1),
            'rangeEnd' => min($totalCount, $page * $pageSize),
            'resultFilters' => self::loadStaticResultFilters(),
            'apiError' => null,
            'section_context' => $sectionContext,
            'filters' => self::buildFiltersState(
                $search,
                $sort,
                [],
                [],
                [],
                [],
                [],
                [],
                [],
                null,
                null,
                $sectionContext
            ),
        ];
    }

    /**
     * @param list<string> $materialTypes
     * @param list<string> $manufacturers
     * @param list<string> $ageCategories
     * @param list<string> $sizeRanges
     * @param list<string> $countryOfOrigins
     * @param list<string> $groupGuids
     * @param list<string> $storeGuids
     * @return array<string, mixed>
     */
    private static function buildFiltersState(
        string $search,
        string $sort,
        array $materialTypes,
        array $manufacturers,
        array $ageCategories,
        array $sizeRanges,
        array $countryOfOrigins,
        array $groupGuids,
        array $storeGuids,
        ?bool $isAvailable,
        ?bool $hasImage,
        ?array $sectionContext
    ): array {
        $state = [
            'q' => $search,
            'sort' => $sort,
            'materialTypes' => $materialTypes,
            'manufacturers' => $manufacturers,
            'ageCategories' => $ageCategories,
            'sizeRanges' => $sizeRanges,
            'countryOfOrigins' => $countryOfOrigins,
            'groupGuids' => $groupGuids,
            'storeGuids' => $storeGuids,
            'isAvailable' => $isAvailable,
            'hasImage' => $hasImage,
        ];

        if ($sectionContext !== null) {
            $state = array_merge(CatalogSectionResolver::storeLinkParams($sectionContext), $state);
        }

        return $state;
    }

    public static function findMaterial(string $guid): ?array
    {
        $guid = trim($guid);
        if ($guid === '') {
            return null;
        }

        try {
            $result = ApiClient::get('/api/materials/' . rawurlencode($guid));
            if (!$result['ok']) {
                return null;
            }

            $data = $result['data'] ?? null;
            if (!is_array($data)) {
                return null;
            }

            return self::withOfferPricing([$data])[0] ?? $data;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<array<string, mixed>> $products @return list<array<string, mixed>> */
    public static function withOfferPricing(array $products): array
    {
        $result = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $overlay = SpecialOfferService::pricingOverlay($product);
            $result[] = !empty($overlay['has_offer']) ? array_merge($product, $overlay) : $product;
        }

        return $result;
    }

    /** @return array{products: list<array<string, mixed>>, totalCount: int, page: int, pageSize: int, totalPages: int, rangeStart: int, rangeEnd: int, resultFilters: array<string, mixed>, apiError: string|null, filters: array<string, mixed>} */
    private static function emptyCatalogResult(int $page, int $pageSize, string $error): array
    {
        return [
            'products' => [],
            'totalCount' => 0,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => 1,
            'rangeStart' => 0,
            'rangeEnd' => 0,
            'resultFilters' => [],
            'apiError' => $error,
            'filters' => [
                'q' => '',
                'sort' => 'number:asc',
                'materialTypes' => [],
                'manufacturers' => [],
                'ageCategories' => [],
                'sizeRanges' => [],
                'countryOfOrigins' => [],
                'groupGuids' => [],
                'storeGuids' => [],
                'isAvailable' => null,
                'hasImage' => null,
            ],
            'section_context' => null,
        ];
    }

    /** @param list<string> $materialTypes @param list<string> $manufacturers */
    private static function fetchMaterialsExtended(
        int $page,
        int $pageSize,
        string $search,
        string $sort,
        array $materialTypes,
        array $manufacturers,
        array $ageCategories,
        array $sizeRanges,
        array $countryOfOrigins,
        array $groupGuids,
        array $storeGuids,
        ?bool $isAvailable,
        ?bool $hasImage
    ): array {
        $primaryQuery = self::buildExtendedApiQuery(
            $page,
            $pageSize,
            $search,
            $sort,
            $materialTypes,
            $manufacturers,
            $ageCategories,
            $sizeRanges,
            $countryOfOrigins,
            $groupGuids,
            $storeGuids,
            $isAvailable,
            $hasImage,
            false
        );
        $materials = ApiClient::get('/api/materials', $primaryQuery);
        if ($materials['ok'] || (int) ($materials['status'] ?? 0) !== 400) {
            return $materials;
        }

        $fallbackQuery = self::buildExtendedApiQuery(
            $page,
            $pageSize,
            $search,
            'number:asc',
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            null,
            null,
            false
        );
        $retry = ApiClient::get('/api/materials', $fallbackQuery);
        if ($retry['ok']) {
            return $retry;
        }

        return $materials;
    }

    /** @param list<string> $materialTypes @param list<string> $manufacturers */
    private static function buildExtendedApiQuery(
        int $page,
        int $pageSize,
        string $search,
        string $sort,
        array $materialTypes,
        array $manufacturers,
        array $ageCategories,
        array $sizeRanges,
        array $countryOfOrigins,
        array $groupGuids,
        array $storeGuids,
        ?bool $isAvailable,
        ?bool $hasImage,
        bool $includeResultFilters
    ): array {
        return array_filter([
            'page' => $page,
            'pageSize' => $pageSize,
            'search' => $search !== '' ? $search : null,
            'sort' => $sort,
            'materialTypes' => $materialTypes !== [] ? implode(',', $materialTypes) : null,
            'manufacturers' => $manufacturers !== [] ? implode(',', $manufacturers) : null,
            'ageCategories' => $ageCategories !== [] ? implode(',', $ageCategories) : null,
            'sizeRanges' => $sizeRanges !== [] ? implode(',', $sizeRanges) : null,
            'countryOfOrigins' => $countryOfOrigins !== [] ? implode(',', $countryOfOrigins) : null,
            'groupGuids' => $groupGuids !== [] ? implode(',', $groupGuids) : null,
            'storeGuids' => $storeGuids !== [] ? implode(',', $storeGuids) : null,
            'isAvailable' => $isAvailable === null ? null : ($isAvailable ? 'true' : 'false'),
            'hasImage' => $hasImage === null ? null : ($hasImage ? 'true' : 'false'),
            'includeResultFilters' => $includeResultFilters ? 'true' : null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /** @return list<string> */
    private static function parseList(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : explode(',', (string) $raw);
        $result = [];
        foreach ($values as $value) {
            $item = trim((string) $value);
            if ($item !== '') {
                $result[] = $item;
            }
        }

        return array_values(array_unique($result));
    }

    private static function parseNullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = trim(strtolower((string) $value));

        return match ($value) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    private static function normalizeSort(mixed $sort): string
    {
        $sort = trim(is_array($sort) ? '' : (string) $sort);
        $allowed = [
            'number:asc',
            'number:desc',
            'name:asc',
            'name:desc',
            '-unitSalePriceSyp',
            'unitSalePriceSyp:desc',
            '-unitSalePriceUsd',
            'unitSalePriceUsd:desc',
        ];

        if (in_array($sort, $allowed, true)) {
            return $sort;
        }

        return 'number:asc';
    }

    /** @return array<string, list<array{value: string, count: int|null}>> */
    private static function loadStaticResultFilters(): array
    {
        try {
            $response = ApiClient::get('/api/materials/filter-options');
            if (!$response['ok'] || !is_array($response['data'] ?? null)) {
                return [];
            }

            $data = $response['data'];
            $toFacetValues = static function (mixed $values): array {
                if (!is_array($values)) {
                    return [];
                }
                $items = [];
                foreach ($values as $value) {
                    $item = trim((string) $value);
                    if ($item === '') {
                        continue;
                    }
                    $items[] = ['value' => $item, 'count' => null];
                }

                return $items;
            };

            return [
                'materialTypes' => $toFacetValues($data['materialTypes'] ?? $data['MaterialTypes'] ?? []),
                'manufacturers' => $toFacetValues($data['manufacturers'] ?? $data['Manufacturers'] ?? []),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $response */
    private static function extractApiError(array $response): string
    {
        $status = (int) ($response['status'] ?? 0);
        $data = $response['data'] ?? null;
        if (is_array($data)) {
            $messages = [];
            if (!empty($data['title']) && is_string($data['title'])) {
                $messages[] = trim($data['title']);
            }
            if (!empty($data['detail']) && is_string($data['detail'])) {
                $messages[] = trim($data['detail']);
            }
            if (isset($data['errors']) && is_array($data['errors'])) {
                foreach ($data['errors'] as $field => $fieldErrors) {
                    if (!is_array($fieldErrors)) {
                        continue;
                    }
                    foreach ($fieldErrors as $fieldError) {
                        $errorText = trim((string) $fieldError);
                        if ($errorText !== '') {
                            $messages[] = $field . ': ' . $errorText;
                        }
                    }
                }
            }
            $messages = array_values(array_unique(array_filter($messages, static fn ($value) => trim((string) $value) !== '')));
            if ($messages !== []) {
                return 'تعذر جلب المواد من API (رمز ' . $status . '): ' . implode(' | ', $messages);
            }
        }

        return 'تعذر جلب المواد من API (رمز ' . $status . ')';
    }
}
