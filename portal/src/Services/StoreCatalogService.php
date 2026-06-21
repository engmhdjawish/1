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
            $policyId = trim((string) ($customer['access_policy_id'] ?? ''));

            return [
                'id' => $policyId,
                'show_price' => (bool) ($customer['show_price'] ?? false),
                'show_quantity' => (bool) ($customer['show_quantity'] ?? false),
                'allow_cart' => (bool) ($customer['allow_cart'] ?? false),
                'allow_order' => (bool) ($customer['allow_order'] ?? false),
                'name_ar' => 'عميل مسجّل',
                'filter_rules' => $policyId !== ''
                    ? AccessPolicyService::filterRulesForPolicyId($policyId)
                    : AccessPolicyService::defaultFilterRules(),
                'store_options' => $policyId !== ''
                    ? AccessPolicyService::storeOptionsForPolicyId($policyId)
                    : AccessPolicyService::defaultStoreOptions(),
            ];
        }

        $guestPolicy = StorePolicyService::guestPolicy();
        if ($guestPolicy === null) {
            return null;
        }

        $policyId = trim((string) ($guestPolicy['id'] ?? ''));
        $guestPolicy['filter_rules'] = $policyId !== ''
            ? AccessPolicyService::filterRulesForPolicyId($policyId)
            : AccessPolicyService::defaultFilterRules();
        $guestPolicy['store_options'] = $policyId !== ''
            ? AccessPolicyService::storeOptionsForPolicyId($policyId)
            : AccessPolicyService::defaultStoreOptions();

        return $guestPolicy;
    }

    /** @param array<string, mixed> $query */
    public static function catalogFromRequest(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $pageSize = 24;
        $sectionContext = CatalogSectionResolver::resolve(
            trim((string) ($query['section'] ?? '')),
            trim((string) ($query['offer'] ?? ''))
        );

        if ($sectionContext !== null) {
            $search = trim((string) ($query['q'] ?? $query['search'] ?? ''));
            $sort = self::normalizeSort($query['sort'] ?? 'number:asc');
            $isAvailable = self::parseNullableBool($query['isAvailable'] ?? null);
            if ((string) ($sectionContext['selection_mode'] ?? '') === 'manual') {
                return self::catalogFromManualSection($sectionContext, $page, $pageSize, $sort, $search);
            }

            return self::catalogFromFilterSection($sectionContext, $page, $pageSize, $sort, $search, $isAvailable);
        }

        $policy = self::activePolicy();
        if ($policy === null) {
            return self::emptyCatalogResult($page, $pageSize, 'لم تُضبط سياسة عرض المتجر بعد.');
        }

        $storeOptions = is_array($policy['store_options'] ?? null)
            ? $policy['store_options']
            : AccessPolicyService::defaultStoreOptions();
        $visibleClientFilters = array_map('strval', $storeOptions['visible_client_filters'] ?? []);
        $allowClientFilters = $visibleClientFilters !== [];
        $isClientFilterVisible = static fn (string $code): bool => in_array($code, $visibleClientFilters, true);

        $requestFilters = self::parseRequestFilters($query, $storeOptions, $isClientFilterVisible);
        $search = $requestFilters['search'];
        $sort = $requestFilters['sort'];
        $materialTypes = $requestFilters['materialTypes'];
        $manufacturers = $requestFilters['manufacturers'];
        $ageCategories = $requestFilters['ageCategories'];
        $sizeRanges = $requestFilters['sizeRanges'];
        $countryOfOrigins = $requestFilters['countryOfOrigins'];
        $groupGuids = $requestFilters['groupGuids'];
        $storeGuids = $requestFilters['storeGuids'];
        $isAvailable = $requestFilters['isAvailable'];
        $hasImage = $requestFilters['hasImage'];

        $contextOfferSlug = self::contextOfferSlug($sectionContext);

        $products = [];
        $totalCount = 0;
        $resultFilters = [];
        $apiError = null;

        $policyRules = is_array($policy['filter_rules'] ?? null) ? $policy['filter_rules'] : [];
        $mergedFilters = self::mergeCatalogFilters($policyRules, $requestFilters);
        if ($mergedFilters['has_conflict']) {
            return self::emptyCatalogResult(
                $page,
                $pageSize,
                'لا توجد مواد مطابقة لسياسة الوصول والفلاتر المحددة.',
                $storeOptions,
                $requestFilters,
                $sectionContext,
                self::lockedClientFilters($policyRules)
            );
        }

        $search = $mergedFilters['search'];
        $materialTypes = $mergedFilters['materialTypes'];
        $manufacturers = $mergedFilters['manufacturers'];
        $ageCategories = $mergedFilters['ageCategories'];
        $sizeRanges = $mergedFilters['sizeRanges'];
        $countryOfOrigins = $mergedFilters['countryOfOrigins'];
        $groupGuids = $mergedFilters['groupGuids'];
        $storeGuids = $mergedFilters['storeGuids'];
        $isAvailable = $mergedFilters['isAvailable'];
        $hasImage = $mergedFilters['hasImage'];
        $lockedClientFilters = self::lockedClientFilters($policyRules);

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
                $hasImage,
                false,
                true,
                $mergedFilters['minWarehouseQuantity'],
                $mergedFilters['maxWarehouseQuantity'],
                $mergedFilters['minUnitSalePriceSyp'],
                $mergedFilters['maxUnitSalePriceSyp'],
                $mergedFilters['minUnitSalePriceUsd'],
                $mergedFilters['maxUnitSalePriceUsd'],
                $mergedFilters['minUnitPurchasePriceUsd'],
                $mergedFilters['maxUnitPurchasePriceUsd']
            );
            if ($materials['ok']) {
                $data = is_array($materials['data'] ?? null) ? $materials['data'] : [];
                $rawItems = $data['items'] ?? $data['Items'] ?? [];
                $products = self::withOfferPricing(
                    is_array($rawItems) ? $rawItems : [],
                    $contextOfferSlug
                );
                $totalCount = max(0, (int) ($data['totalCount'] ?? $data['TotalCount'] ?? 0));
                $page = max(1, (int) ($data['page'] ?? $page));
                $pageSize = max(1, (int) ($data['pageSize'] ?? $pageSize));
                $resultFilters = is_array($data['resultFilters'] ?? null) ? $data['resultFilters'] : [];
                $resultFilters = self::scopeResultFiltersForPolicy($resultFilters, $policyRules);
            } else {
                $apiError = self::extractApiError($materials);
            }
        } catch (\Throwable $exception) {
            $apiError = $exception->getMessage();
        }

        $filterOptions = self::loadFilterOptions();
        if ($storeGuids !== [] || self::parseList($policyRules['store_guids'] ?? []) !== []) {
            $forcedStoreGuids = self::parseList($policyRules['store_guids'] ?? []);
            if ($forcedStoreGuids !== []) {
                $forcedStoreMap = array_flip(array_map('strtolower', $forcedStoreGuids));
                $filterOptions['stores'] = array_values(array_filter(
                    $filterOptions['stores'],
                    static fn (array $store): bool => isset($forcedStoreMap[strtolower((string) ($store['guid'] ?? ''))])
                ));
            }
        }

        $totalPages = max(1, (int) ceil($totalCount / max(1, $pageSize)));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $displayFilters = self::buildFiltersState(
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
            $sectionContext,
            $requestFilters
        );

        return [
            'products' => $products,
            'totalCount' => $totalCount,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => $totalPages,
            'rangeStart' => $totalCount === 0 ? 0 : (($page - 1) * $pageSize + 1),
            'rangeEnd' => min($totalCount, $page * $pageSize),
            'resultFilters' => $resultFilters,
            'filterOptions' => $filterOptions,
            'apiError' => $apiError,
            'section_context' => $sectionContext,
            'locked_client_filters' => $lockedClientFilters,
            'store_options' => $storeOptions,
            'allow_client_filters' => $allowClientFilters,
            'filters' => $displayFilters,
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
        $contextOfferSlug = self::contextOfferSlug($sectionContext);

        return [
            'products' => self::withOfferPricing($products, $contextOfferSlug),
            'totalCount' => $totalCount,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => $totalPages,
            'rangeStart' => $totalCount === 0 ? 0 : ($offset + 1),
            'rangeEnd' => min($totalCount, $page * $pageSize),
            'resultFilters' => self::loadStaticResultFilters(),
            'apiError' => null,
            'section_context' => $sectionContext,
            'section_filter_summary' => [['label' => 'العرض', 'value' => 'منتجات محددة يدوياً']],
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

    /** @param array<string, mixed> $sectionContext */
    private static function catalogFromFilterSection(
        array $sectionContext,
        int $page,
        int $pageSize,
        string $sort,
        string $userSearch,
        ?bool $userIsAvailable
    ): array {
        if (self::activePolicy() === null) {
            return self::emptyCatalogResult($page, $pageSize, 'لم تُضبط سياسة عرض المتجر بعد.');
        }

        $rules = is_array($sectionContext['filter_rules'] ?? null) ? $sectionContext['filter_rules'] : [];
        $policy = self::activePolicy();
        $policyRules = is_array($policy['filter_rules'] ?? null) ? $policy['filter_rules'] : [];
        $rules = self::mergeFilterRuleSets($policyRules, $rules);
        if (trim((string) ($rules['keyword'] ?? '')) === '__conflict__') {
            return self::emptyCatalogResult($page, $pageSize, 'لا توجد مواد مطابقة لسياسة الوصول وفلاتر القسم.');
        }
        $contextOfferSlug = self::contextOfferSlug($sectionContext);
        $apiQuery = CatalogSectionResolver::apiQueryFromRules($rules, $page, $pageSize, $sort);
        $apiQuery['includeResultFilters'] = 'true';

        if ($userSearch !== '') {
            $apiQuery['search'] = $userSearch;
        }
        if ($userIsAvailable !== null) {
            $apiQuery['isAvailable'] = $userIsAvailable ? 'true' : 'false';
        }

        $products = [];
        $totalCount = 0;
        $resultFilters = [];
        $apiError = null;

        try {
            $materials = self::requestMaterialsQuery($apiQuery);
            if ($materials['ok']) {
                $data = is_array($materials['data'] ?? null) ? $materials['data'] : [];
                $rawItems = $data['items'] ?? $data['Items'] ?? [];
                $products = self::withOfferPricing(
                    is_array($rawItems) ? $rawItems : [],
                    $contextOfferSlug
                );
                $totalCount = max(0, (int) ($data['totalCount'] ?? $data['TotalCount'] ?? 0));
                $page = max(1, (int) ($data['page'] ?? $page));
                $pageSize = max(1, (int) ($data['pageSize'] ?? $pageSize));
                $resultFilters = is_array($data['resultFilters'] ?? null) ? $data['resultFilters'] : [];
            } else {
                $apiError = self::extractApiError($materials);
            }
        } catch (\Throwable $exception) {
            $apiError = $exception->getMessage();
        }

        $displaySearch = $userSearch !== '' ? $userSearch : trim((string) ($rules['keyword'] ?? ''));
        $displayIsAvailable = $userIsAvailable;
        if ($displayIsAvailable === null && array_key_exists('is_available', $rules)) {
            $displayIsAvailable = $rules['is_available'];
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
            'section_filter_summary' => CatalogSectionResolver::filterSummaryLabels($rules),
            'filters' => self::buildFiltersState(
                $displaySearch,
                $sort,
                self::parseList($rules['material_types'] ?? []),
                self::parseList($rules['manufacturers'] ?? []),
                self::parseList($rules['age_categories'] ?? []),
                self::parseList($rules['size_ranges'] ?? []),
                self::parseList($rules['country_origins'] ?? []),
                self::parseList($rules['group_guids'] ?? []),
                self::parseList($rules['store_guids'] ?? []),
                $displayIsAvailable,
                array_key_exists('has_image', $rules) ? $rules['has_image'] : null,
                $sectionContext
            ),
        ];
    }

    /**
     * @param array<string, string|int> $apiQuery
     * @return array<string, mixed>
     */
    private static function requestMaterialsQuery(array $apiQuery): array
    {
        $materials = ApiClient::get('/api/materials', $apiQuery);
        if ($materials['ok'] || (int) ($materials['status'] ?? 0) !== 400) {
            return $materials;
        }

        $retryQuery = $apiQuery;
        $retryQuery['sort'] = 'number:asc';

        return ApiClient::get('/api/materials', $retryQuery);
    }

    /**
     * @param array<string, mixed> $sectionContext
     * @param array<string, mixed> $requestFilters
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
        ?array $sectionContext,
        array $requestFilters = []
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
            'minWarehouseQuantity' => $requestFilters['minWarehouseQuantity'] ?? null,
            'maxWarehouseQuantity' => $requestFilters['maxWarehouseQuantity'] ?? null,
            'minUnitSalePriceSyp' => $requestFilters['minUnitSalePriceSyp'] ?? null,
            'maxUnitSalePriceSyp' => $requestFilters['maxUnitSalePriceSyp'] ?? null,
            'minUnitSalePriceUsd' => $requestFilters['minUnitSalePriceUsd'] ?? null,
            'maxUnitSalePriceUsd' => $requestFilters['maxUnitSalePriceUsd'] ?? null,
            'minUnitPurchasePriceUsd' => $requestFilters['minUnitPurchasePriceUsd'] ?? null,
            'maxUnitPurchasePriceUsd' => $requestFilters['maxUnitPurchasePriceUsd'] ?? null,
            'groupBy' => (string) ($requestFilters['groupBy'] ?? 'none'),
        ];

        if ($sectionContext !== null) {
            $state = array_merge(CatalogSectionResolver::storeLinkParams($sectionContext), $state);
        }

        return $state;
    }

    public static function findMaterial(string $guid, ?string $offerSlug = null): ?array
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

            return self::withOfferPricing([$data], $offerSlug)[0] ?? $data;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<array<string, mixed>> $products @return list<array<string, mixed>> */
    public static function withOfferPricing(array $products, ?string $offerSlug = null): array
    {
        $contextOffer = null;
        if ($offerSlug !== null && trim($offerSlug) !== '') {
            $contextOffer = SpecialOfferService::activeOfferBySlug($offerSlug);
        }

        $result = [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $overlay = SpecialOfferService::pricingOverlay($product, $contextOffer);
            $result[] = !empty($overlay['has_offer']) ? array_merge($product, $overlay) : $product;
        }

        return $result;
    }

    /** @param array<string, mixed>|null $sectionContext */
    private static function contextOfferSlug(?array $sectionContext): ?string
    {
        if ($sectionContext === null || empty($sectionContext['is_offer_section'])) {
            return null;
        }

        $slug = trim((string) ($sectionContext['slug'] ?? ''));

        return $slug !== '' ? $slug : null;
    }

    /** @return array{products: list<array<string, mixed>>, totalCount: int, page: int, pageSize: int, totalPages: int, rangeStart: int, rangeEnd: int, resultFilters: array<string, mixed>, apiError: string|null, filters: array<string, mixed>, store_options?: array<string, mixed>, allow_client_filters?: bool} */
    private static function emptyCatalogResult(
        int $page,
        int $pageSize,
        string $error,
        ?array $storeOptions = null,
        array $requestFilters = [],
        ?array $sectionContext = null,
        array $lockedClientFilters = []
    ): array {
        return [
            'products' => [],
            'totalCount' => 0,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => 1,
            'rangeStart' => 0,
            'rangeEnd' => 0,
            'resultFilters' => [],
            'filterOptions' => ['stores' => [], 'groups' => []],
            'apiError' => $error,
            'section_context' => $sectionContext,
            'locked_client_filters' => $lockedClientFilters,
            'store_options' => $storeOptions ?? AccessPolicyService::defaultStoreOptions(),
            'allow_client_filters' => $storeOptions !== null && ($storeOptions['visible_client_filters'] ?? []) !== [],
            'filters' => [
                'q' => (string) ($requestFilters['search'] ?? ''),
                'sort' => (string) ($requestFilters['sort'] ?? 'number:asc'),
                'materialTypes' => self::parseList($requestFilters['materialTypes'] ?? []),
                'manufacturers' => self::parseList($requestFilters['manufacturers'] ?? []),
                'ageCategories' => self::parseList($requestFilters['ageCategories'] ?? []),
                'sizeRanges' => self::parseList($requestFilters['sizeRanges'] ?? []),
                'countryOfOrigins' => self::parseList($requestFilters['countryOfOrigins'] ?? []),
                'groupGuids' => self::parseList($requestFilters['groupGuids'] ?? []),
                'storeGuids' => self::parseList($requestFilters['storeGuids'] ?? []),
                'isAvailable' => $requestFilters['isAvailable'] ?? null,
                'hasImage' => $requestFilters['hasImage'] ?? null,
                'groupBy' => (string) ($requestFilters['groupBy'] ?? 'none'),
            ],
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
        ?bool $hasImage,
        bool $preserveFiltersOnRetry = false,
        bool $includeResultFilters = true,
        ?float $minWarehouseQuantity = null,
        ?float $maxWarehouseQuantity = null,
        ?float $minUnitSalePriceSyp = null,
        ?float $maxUnitSalePriceSyp = null,
        ?float $minUnitSalePriceUsd = null,
        ?float $maxUnitSalePriceUsd = null,
        ?float $minUnitPurchasePriceUsd = null,
        ?float $maxUnitPurchasePriceUsd = null
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
            $includeResultFilters,
            $minWarehouseQuantity,
            $maxWarehouseQuantity,
            $minUnitSalePriceSyp,
            $maxUnitSalePriceSyp,
            $minUnitSalePriceUsd,
            $maxUnitSalePriceUsd,
            $minUnitPurchasePriceUsd,
            $maxUnitPurchasePriceUsd
        );
        $materials = ApiClient::get('/api/materials', $primaryQuery);
        if ($materials['ok'] || (int) ($materials['status'] ?? 0) !== 400) {
            return $materials;
        }

        if (!$preserveFiltersOnRetry) {
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
                $includeResultFilters,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null
            );
            $retry = ApiClient::get('/api/materials', $fallbackQuery);
            if ($retry['ok']) {
                return $retry;
            }
        } else {
            $retryQuery = self::buildExtendedApiQuery(
                $page,
                $pageSize,
                $search,
                'number:asc',
                $materialTypes,
                $manufacturers,
                $ageCategories,
                $sizeRanges,
                $countryOfOrigins,
                $groupGuids,
                $storeGuids,
                $isAvailable,
                $hasImage,
                $includeResultFilters,
                $minWarehouseQuantity,
                $maxWarehouseQuantity,
                $minUnitSalePriceSyp,
                $maxUnitSalePriceSyp,
                $minUnitSalePriceUsd,
                $maxUnitSalePriceUsd,
                $minUnitPurchasePriceUsd,
                $maxUnitPurchasePriceUsd
            );
            $retry = ApiClient::get('/api/materials', $retryQuery);
            if ($retry['ok']) {
                return $retry;
            }
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
        bool $includeResultFilters,
        ?float $minWarehouseQuantity = null,
        ?float $maxWarehouseQuantity = null,
        ?float $minUnitSalePriceSyp = null,
        ?float $maxUnitSalePriceSyp = null,
        ?float $minUnitSalePriceUsd = null,
        ?float $maxUnitSalePriceUsd = null,
        ?float $minUnitPurchasePriceUsd = null,
        ?float $maxUnitPurchasePriceUsd = null
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
            'minWarehouseQuantity' => $minWarehouseQuantity,
            'maxWarehouseQuantity' => $maxWarehouseQuantity,
            'minUnitSalePriceSyp' => $minUnitSalePriceSyp,
            'maxUnitSalePriceSyp' => $maxUnitSalePriceSyp,
            'minUnitSalePriceUsd' => $minUnitSalePriceUsd,
            'maxUnitSalePriceUsd' => $maxUnitSalePriceUsd,
            'minUnitPurchasePriceUsd' => $minUnitPurchasePriceUsd,
            'maxUnitPurchasePriceUsd' => $maxUnitPurchasePriceUsd,
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

    /**
     * @param array<string, mixed> $policyRules
     * @param array<string, mixed> $overlayRules
     * @return array<string, mixed>
     */
    private static function mergeFilterRuleSets(array $policyRules, array $overlayRules): array
    {
        $hasConflict = false;
        $merged = AccessPolicyService::defaultFilterRules();

        $policyKeyword = trim((string) ($policyRules['keyword'] ?? ''));
        $overlayKeyword = trim((string) ($overlayRules['keyword'] ?? ''));
        if ($policyKeyword !== '' && $overlayKeyword !== '') {
            $merged['keyword'] = trim($policyKeyword . ' ' . $overlayKeyword);
        } elseif ($overlayKeyword !== '') {
            $merged['keyword'] = $overlayKeyword;
        } else {
            $merged['keyword'] = $policyKeyword;
        }

        foreach ([
            'material_types',
            'age_categories',
            'manufacturers',
            'size_ranges',
            'country_origins',
            'store_guids',
            'group_guids',
        ] as $key) {
            $merged[$key] = self::mergeConstrainedValues(
                self::parseList($policyRules[$key] ?? []),
                self::parseList($overlayRules[$key] ?? []),
                $hasConflict
            );
        }

        $merged['is_available'] = self::mergeNullableBool(
            array_key_exists('is_available', $policyRules) ? $policyRules['is_available'] : null,
            array_key_exists('is_available', $overlayRules) ? $overlayRules['is_available'] : null,
            $hasConflict
        );
        $merged['has_image'] = self::mergeNullableBool(
            array_key_exists('has_image', $policyRules) ? $policyRules['has_image'] : null,
            array_key_exists('has_image', $overlayRules) ? $overlayRules['has_image'] : null,
            $hasConflict
        );

        foreach ([
            'min_warehouse_quantity',
            'min_unit_sale_price_syp',
            'min_unit_sale_price_usd',
            'min_unit_purchase_price_usd',
        ] as $key) {
            $merged[$key] = self::mergeMinFloat(
                self::nullableFloat($policyRules[$key] ?? null),
                self::nullableFloat($overlayRules[$key] ?? null)
            );
        }
        foreach ([
            'max_warehouse_quantity',
            'max_unit_sale_price_syp',
            'max_unit_sale_price_usd',
            'max_unit_purchase_price_usd',
        ] as $key) {
            $merged[$key] = self::mergeMaxFloat(
                self::nullableFloat($policyRules[$key] ?? null),
                self::nullableFloat($overlayRules[$key] ?? null)
            );
        }

        self::validateNumericRanges($merged, $hasConflict);

        if ($hasConflict) {
            $merged['keyword'] = '__conflict__';
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $policyRules
     * @param array<string, mixed> $userFilters
     * @return array{
     *   has_conflict: bool,
     *   search: string,
     *   materialTypes: list<string>,
     *   manufacturers: list<string>,
     *   ageCategories: list<string>,
     *   sizeRanges: list<string>,
     *   countryOfOrigins: list<string>,
     *   groupGuids: list<string>,
     *   storeGuids: list<string>,
     *   isAvailable: bool|null,
     *   hasImage: bool|null,
     *   minWarehouseQuantity: float|null,
     *   maxWarehouseQuantity: float|null,
     *   minUnitSalePriceSyp: float|null,
     *   maxUnitSalePriceSyp: float|null,
     *   minUnitSalePriceUsd: float|null,
     *   maxUnitSalePriceUsd: float|null,
     *   minUnitPurchasePriceUsd: float|null,
     *   maxUnitPurchasePriceUsd: float|null
     * }
     */
    private static function mergeCatalogFilters(array $policyRules, array $userFilters): array
    {
        $overlayRules = AccessPolicyService::defaultFilterRules();
        $overlayRules['keyword'] = trim((string) ($userFilters['search'] ?? ''));
        $overlayRules['material_types'] = $userFilters['materialTypes'] ?? [];
        $overlayRules['manufacturers'] = $userFilters['manufacturers'] ?? [];
        $overlayRules['age_categories'] = $userFilters['ageCategories'] ?? [];
        $overlayRules['size_ranges'] = $userFilters['sizeRanges'] ?? [];
        $overlayRules['country_origins'] = $userFilters['countryOfOrigins'] ?? [];
        $overlayRules['group_guids'] = $userFilters['groupGuids'] ?? [];
        $overlayRules['store_guids'] = $userFilters['storeGuids'] ?? [];
        $overlayRules['is_available'] = $userFilters['isAvailable'] ?? null;
        $overlayRules['has_image'] = $userFilters['hasImage'] ?? null;
        $overlayRules['min_warehouse_quantity'] = $userFilters['minWarehouseQuantity'] ?? null;
        $overlayRules['max_warehouse_quantity'] = $userFilters['maxWarehouseQuantity'] ?? null;
        $overlayRules['min_unit_sale_price_syp'] = $userFilters['minUnitSalePriceSyp'] ?? null;
        $overlayRules['max_unit_sale_price_syp'] = $userFilters['maxUnitSalePriceSyp'] ?? null;
        $overlayRules['min_unit_sale_price_usd'] = $userFilters['minUnitSalePriceUsd'] ?? null;
        $overlayRules['max_unit_sale_price_usd'] = $userFilters['maxUnitSalePriceUsd'] ?? null;
        $overlayRules['min_unit_purchase_price_usd'] = $userFilters['minUnitPurchasePriceUsd'] ?? null;
        $overlayRules['max_unit_purchase_price_usd'] = $userFilters['maxUnitPurchasePriceUsd'] ?? null;

        $merged = self::mergeFilterRuleSets($policyRules, $overlayRules);
        $hasConflict = trim((string) ($merged['keyword'] ?? '')) === '__conflict__';

        return [
            'has_conflict' => $hasConflict,
            'search' => $hasConflict ? '' : trim((string) ($merged['keyword'] ?? '')),
            'materialTypes' => self::parseList($merged['material_types'] ?? []),
            'manufacturers' => self::parseList($merged['manufacturers'] ?? []),
            'ageCategories' => self::parseList($merged['age_categories'] ?? []),
            'sizeRanges' => self::parseList($merged['size_ranges'] ?? []),
            'countryOfOrigins' => self::parseList($merged['country_origins'] ?? []),
            'groupGuids' => self::parseList($merged['group_guids'] ?? []),
            'storeGuids' => self::parseList($merged['store_guids'] ?? []),
            'isAvailable' => array_key_exists('is_available', $merged) ? $merged['is_available'] : null,
            'hasImage' => array_key_exists('has_image', $merged) ? $merged['has_image'] : null,
            'minWarehouseQuantity' => self::nullableFloat($merged['min_warehouse_quantity'] ?? null),
            'maxWarehouseQuantity' => self::nullableFloat($merged['max_warehouse_quantity'] ?? null),
            'minUnitSalePriceSyp' => self::nullableFloat($merged['min_unit_sale_price_syp'] ?? null),
            'maxUnitSalePriceSyp' => self::nullableFloat($merged['max_unit_sale_price_syp'] ?? null),
            'minUnitSalePriceUsd' => self::nullableFloat($merged['min_unit_sale_price_usd'] ?? null),
            'maxUnitSalePriceUsd' => self::nullableFloat($merged['max_unit_sale_price_usd'] ?? null),
            'minUnitPurchasePriceUsd' => self::nullableFloat($merged['min_unit_purchase_price_usd'] ?? null),
            'maxUnitPurchasePriceUsd' => self::nullableFloat($merged['max_unit_purchase_price_usd'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $storeOptions
     * @param callable(string): bool $isClientFilterVisible
     * @return array<string, mixed>
     */
    private static function parseRequestFilters(array $query, array $storeOptions, callable $isClientFilterVisible): array
    {
        $defaultSort = (string) ($storeOptions['default_sort'] ?? 'number:asc');
        $allowSorting = (bool) ($storeOptions['allow_sorting'] ?? true);

        return [
            'search' => $isClientFilterVisible('search') ? trim((string) ($query['q'] ?? $query['search'] ?? '')) : '',
            'sort' => ($isClientFilterVisible('sort') && $allowSorting)
                ? self::normalizeSort($query['sort'] ?? $defaultSort)
                : self::normalizeSort($defaultSort),
            'materialTypes' => $isClientFilterVisible('materialTypes') ? self::parseList($query['materialTypes'] ?? []) : [],
            'manufacturers' => $isClientFilterVisible('manufacturers') ? self::parseList($query['manufacturers'] ?? []) : [],
            'ageCategories' => $isClientFilterVisible('ageCategories') ? self::parseList($query['ageCategories'] ?? []) : [],
            'sizeRanges' => $isClientFilterVisible('sizeRanges') ? self::parseList($query['sizeRanges'] ?? []) : [],
            'countryOfOrigins' => $isClientFilterVisible('countryOfOrigins') ? self::parseList($query['countryOfOrigins'] ?? []) : [],
            'groupGuids' => $isClientFilterVisible('groups') ? self::parseList($query['groupGuids'] ?? []) : [],
            'storeGuids' => $isClientFilterVisible('stores') ? self::parseList($query['storeGuids'] ?? []) : [],
            'isAvailable' => $isClientFilterVisible('availability') ? self::parseNullableBool($query['isAvailable'] ?? null) : null,
            'hasImage' => null,
            'minWarehouseQuantity' => $isClientFilterVisible('warehouseRange') ? self::parseNullableFloat($query['minWarehouseQuantity'] ?? null) : null,
            'maxWarehouseQuantity' => $isClientFilterVisible('warehouseRange') ? self::parseNullableFloat($query['maxWarehouseQuantity'] ?? null) : null,
            'minUnitSalePriceSyp' => $isClientFilterVisible('priceSaleSyp') ? self::parseNullableFloat($query['minUnitSalePriceSyp'] ?? null) : null,
            'maxUnitSalePriceSyp' => $isClientFilterVisible('priceSaleSyp') ? self::parseNullableFloat($query['maxUnitSalePriceSyp'] ?? null) : null,
            'minUnitSalePriceUsd' => $isClientFilterVisible('priceSaleUsd') ? self::parseNullableFloat($query['minUnitSalePriceUsd'] ?? null) : null,
            'maxUnitSalePriceUsd' => $isClientFilterVisible('priceSaleUsd') ? self::parseNullableFloat($query['maxUnitSalePriceUsd'] ?? null) : null,
            'minUnitPurchasePriceUsd' => $isClientFilterVisible('pricePurchaseUsd') ? self::parseNullableFloat($query['minUnitPurchasePriceUsd'] ?? null) : null,
            'maxUnitPurchasePriceUsd' => $isClientFilterVisible('pricePurchaseUsd') ? self::parseNullableFloat($query['maxUnitPurchasePriceUsd'] ?? null) : null,
            'groupBy' => $isClientFilterVisible('groupBy')
                ? trim((string) ($query['groupBy'] ?? 'none'))
                : 'none',
        ];
    }

    /** @param array<string, mixed> $resultFilters @param array<string, mixed> $policyRules @return array<string, mixed> */
    private static function scopeResultFiltersForPolicy(array $resultFilters, array $policyRules): array
    {
        $scopeStringFacets = static function (array $facets, array $forced): array {
            $withResults = array_values(array_filter($facets, static function (array $facet): bool {
                $count = $facet['count'] ?? null;

                return $count !== null && (int) $count > 0;
            }));
            if ($forced === []) {
                return $withResults;
            }
            $allowed = [];
            foreach ($forced as $value) {
                $allowed[strtolower((string) $value)] = true;
            }

            return array_values(array_filter($withResults, static function (array $facet) use ($allowed): bool {
                return isset($allowed[strtolower((string) ($facet['value'] ?? ''))]);
            }));
        };

        $scopeGroupFacets = static function (array $facets, array $forcedGuids): array {
            $withResults = array_values(array_filter($facets, static function (array $facet): bool {
                $count = $facet['count'] ?? null;

                return $count !== null && (int) $count > 0;
            }));
            if ($forcedGuids === []) {
                return $withResults;
            }
            $allowed = array_flip(array_map('strtolower', $forcedGuids));

            return array_values(array_filter($withResults, static function (array $facet) use ($allowed): bool {
                $guid = strtolower((string) ($facet['guid'] ?? ''));

                return $guid !== '' && isset($allowed[$guid]);
            }));
        };

        $resultFilters['materialTypes'] = $scopeStringFacets(
            is_array($resultFilters['materialTypes'] ?? null) ? $resultFilters['materialTypes'] : [],
            self::parseList($policyRules['material_types'] ?? [])
        );
        $resultFilters['ageCategories'] = $scopeStringFacets(
            is_array($resultFilters['ageCategories'] ?? null) ? $resultFilters['ageCategories'] : [],
            self::parseList($policyRules['age_categories'] ?? [])
        );
        $resultFilters['manufacturers'] = $scopeStringFacets(
            is_array($resultFilters['manufacturers'] ?? null) ? $resultFilters['manufacturers'] : [],
            self::parseList($policyRules['manufacturers'] ?? [])
        );
        $resultFilters['sizeRanges'] = $scopeStringFacets(
            is_array($resultFilters['sizeRanges'] ?? null) ? $resultFilters['sizeRanges'] : [],
            self::parseList($policyRules['size_ranges'] ?? [])
        );
        $resultFilters['countryOfOrigins'] = $scopeStringFacets(
            is_array($resultFilters['countryOfOrigins'] ?? null) ? $resultFilters['countryOfOrigins'] : [],
            self::parseList($policyRules['country_origins'] ?? [])
        );
        $resultFilters['groups'] = $scopeGroupFacets(
            is_array($resultFilters['groups'] ?? null) ? $resultFilters['groups'] : [],
            self::parseList($policyRules['group_guids'] ?? [])
        );

        return $resultFilters;
    }

    /** @return array{stores: list<array<string, mixed>>, groups: list<array<string, mixed>>} */
    private static function loadFilterOptions(): array
    {
        try {
            $response = ApiClient::get('/api/materials/filter-options');
            if (!$response['ok'] || !is_array($response['data'] ?? null)) {
                return ['stores' => [], 'groups' => []];
            }
            $data = $response['data'];
            $stores = is_array($data['stores'] ?? null) ? $data['stores'] : (is_array($data['Stores'] ?? null) ? $data['Stores'] : []);
            $groups = is_array($data['groups'] ?? null) ? $data['groups'] : (is_array($data['Groups'] ?? null) ? $data['Groups'] : []);
            $normalizeGuidRows = static function (array $rows): array {
                $items = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $guid = trim((string) ($row['guid'] ?? $row['Guid'] ?? ''));
                    if ($guid === '') {
                        continue;
                    }
                    $items[] = [
                        'guid' => $guid,
                        'name' => trim((string) ($row['name'] ?? $row['Name'] ?? '')),
                        'code' => trim((string) ($row['code'] ?? $row['Code'] ?? '')),
                    ];
                }

                return $items;
            };

            return [
                'stores' => $normalizeGuidRows($stores),
                'groups' => $normalizeGuidRows($groups),
            ];
        } catch (\Throwable) {
            return ['stores' => [], 'groups' => []];
        }
    }

    private static function parseNullableFloat(mixed $value): ?float
    {
        if (is_array($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text !== '' && is_numeric($text) ? (float) $text : null;
    }

    /** @param list<string> $forced @param list<string> $selected */
    private static function mergeConstrainedValues(array $forced, array $selected, bool &$hasConflict): array
    {
        if ($forced === []) {
            return $selected;
        }
        if ($selected === []) {
            return $forced;
        }

        $forcedMap = [];
        foreach ($forced as $value) {
            $forcedMap[strtolower($value)] = $value;
        }
        $intersection = [];
        foreach ($selected as $value) {
            $key = strtolower($value);
            if (isset($forcedMap[$key])) {
                $intersection[] = $forcedMap[$key];
            }
        }
        $intersection = array_values(array_unique($intersection));
        if ($intersection === []) {
            $hasConflict = true;
        }

        return $intersection;
    }

    private static function mergeNullableBool(?bool $forced, ?bool $selected, bool &$hasConflict): ?bool
    {
        if ($forced === null) {
            return $selected;
        }
        if ($selected === null) {
            return $forced;
        }
        if ($forced !== $selected) {
            $hasConflict = true;
        }

        return $forced;
    }

    private static function mergeMinFloat(?float $forced, ?float $selected): ?float
    {
        if ($forced === null) {
            return $selected;
        }
        if ($selected === null) {
            return $forced;
        }

        return max($forced, $selected);
    }

    private static function mergeMaxFloat(?float $forced, ?float $selected): ?float
    {
        if ($forced === null) {
            return $selected;
        }
        if ($selected === null) {
            return $forced;
        }

        return min($forced, $selected);
    }

    /** @param array<string, mixed> $rules */
    private static function validateNumericRanges(array $rules, bool &$hasConflict): void
    {
        $pairs = [
            ['min_warehouse_quantity', 'max_warehouse_quantity'],
            ['min_unit_sale_price_syp', 'max_unit_sale_price_syp'],
            ['min_unit_sale_price_usd', 'max_unit_sale_price_usd'],
            ['min_unit_purchase_price_usd', 'max_unit_purchase_price_usd'],
        ];
        foreach ($pairs as [$minKey, $maxKey]) {
            $min = self::nullableFloat($rules[$minKey] ?? null);
            $max = self::nullableFloat($rules[$maxKey] ?? null);
            if ($min !== null && $max !== null && $min > $max) {
                $hasConflict = true;
            }
        }
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric((string) $value) ? (float) $value : null;
    }

    /** @param array<string, mixed> $policyRules @return list<string> */
    private static function lockedClientFilters(array $policyRules): array
    {
        $locked = [];
        if (array_key_exists('is_available', $policyRules) && $policyRules['is_available'] !== null) {
            $locked[] = 'availability';
        }

        return $locked;
    }
}
