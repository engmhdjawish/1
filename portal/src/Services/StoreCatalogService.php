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
        $sort = trim((string) ($query['sort'] ?? 'number:asc'));
        $materialTypes = self::parseList($query['materialTypes'] ?? []);
        $manufacturers = self::parseList($query['manufacturers'] ?? []);
        $isAvailable = self::parseNullableBool($query['isAvailable'] ?? null);

        $apiQuery = array_filter([
            'page' => $page,
            'pageSize' => $pageSize,
            'search' => $search !== '' ? $search : null,
            'sort' => $sort !== '' ? $sort : null,
            'materialTypes' => $materialTypes !== [] ? implode(',', $materialTypes) : null,
            'manufacturers' => $manufacturers !== [] ? implode(',', $manufacturers) : null,
            'isAvailable' => $isAvailable,
            'includeResultFilters' => true,
        ], static fn ($value) => $value !== null && $value !== '');

        $products = [];
        $totalCount = 0;
        $resultFilters = [];
        $apiError = null;

        if (self::activePolicy() === null) {
            return self::emptyCatalogResult($page, $pageSize, 'لم تُضبط سياسة عرض المتجر بعد.');
        }

        try {
            $materials = ApiClient::get('/api/materials', $apiQuery);
            if ($materials['ok']) {
                $data = is_array($materials['data'] ?? null) ? $materials['data'] : [];
                $products = is_array($data['items'] ?? null) ? $data['items'] : [];
                $totalCount = max(0, (int) ($data['totalCount'] ?? 0));
                $page = max(1, (int) ($data['page'] ?? $page));
                $pageSize = max(1, (int) ($data['pageSize'] ?? $pageSize));
                $resultFilters = is_array($data['resultFilters'] ?? null) ? $data['resultFilters'] : [];
            } else {
                $apiError = 'تعذر جلب المواد من API (رمز ' . (int) ($materials['status'] ?? 0) . ')';
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
            'filters' => [
                'q' => $search,
                'sort' => $sort,
                'materialTypes' => $materialTypes,
                'manufacturers' => $manufacturers,
                'isAvailable' => $isAvailable,
            ],
        ];
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

            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
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
                'isAvailable' => null,
            ],
        ];
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
        $value = trim(strtolower((string) $value));

        return match ($value) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }
}
