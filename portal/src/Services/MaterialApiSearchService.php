<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Support\SessionGate;

/**
 * Material catalog search aligned with the store API: terms split like KeywordSearchTerms (any order, AND).
 */
final class MaterialApiSearchService
{
    /**
     * @return list<string>
     */
    public static function parseTerms(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $parts = preg_split('/[\s,،|\x{060C}\t\r\n]+/u', $query) ?: [];
        $terms = [];
        $seen = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($part, 'UTF-8') : strtolower($part);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $terms[] = $part;
        }

        return $terms;
    }

    /** @param array<string, mixed> $row */
    public static function materialHaystack(array $row): string
    {
        $chunks = [
            trim((string) ($row['name'] ?? $row['Name'] ?? '')),
            trim((string) ($row['latinName'] ?? $row['LatinName'] ?? '')),
            trim((string) ($row['materialCode'] ?? $row['MaterialCode'] ?? '')),
            trim((string) ($row['barCode'] ?? $row['BarCode'] ?? '')),
            trim((string) ($row['barCode2'] ?? $row['BarCode2'] ?? '')),
            trim((string) ($row['barCode3'] ?? $row['BarCode3'] ?? '')),
        ];

        return HomeSearchService::normalize(implode(' ', array_filter($chunks, static fn (string $v): bool => $v !== '')));
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $terms
     */
    public static function matchesAllTerms(array $row, array $terms): bool
    {
        if ($terms === []) {
            return true;
        }

        $hay = self::materialHaystack($row);
        if ($hay === '') {
            return false;
        }

        foreach ($terms as $term) {
            $needle = HomeSearchService::normalize($term);
            if ($needle === '') {
                continue;
            }
            if (!str_contains($hay, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $storeGuids
     * @return array{
     *   ok: bool,
     *   message: string,
     *   items: list<array<string, mixed>>,
     *   page: int,
     *   pageSize: int,
     *   total: int,
     *   hasMore: bool
     * }
     */
    public static function searchPickerItems(
        string $query,
        int $page,
        int $pageSize,
        array $storeGuids = [],
        string $manufacturer = ''
    ): array {
        $page = max(1, $page);
        $pageSize = max(1, min(48, $pageSize));
        $terms = self::parseTerms($query);
        if ($terms === []) {
            return [
                'ok' => true,
                'message' => '',
                'items' => [],
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => 0,
                'hasMore' => false,
            ];
        }

        $skip = ($page - 1) * $pageSize;
        $collected = [];
        $matched = 0;
        $apiPage = 1;
        $apiPageSize = max(48, min(200, $pageSize * 3));
        $hasMore = false;
        $maxApiPages = 60;

        $baseQuery = [
            'search' => trim($query),
            'sort' => 'number:asc',
            'includeTotalCount' => 'false',
        ];
        if ($storeGuids !== []) {
            $baseQuery['storeGuids'] = implode(',', array_map('strval', $storeGuids));
        }
        $manufacturer = trim($manufacturer);
        if ($manufacturer !== '') {
            $baseQuery['manufacturers'] = $manufacturer;
        }

        while ($apiPage <= $maxApiPages && count($collected) < $pageSize) {
            $apiQuery = array_merge($baseQuery, [
                'page' => $apiPage,
                'pageSize' => $apiPageSize,
            ]);

            $response = SessionGate::withoutLock(static fn (): array => ApiClient::get('/api/materials', $apiQuery, 90));
            if (!($response['ok'] ?? false)) {
                $message = is_array($response['data'] ?? null)
                    ? (string) ($response['data']['message'] ?? $response['data']['title'] ?? 'تعذر البحث.')
                    : (string) ($response['error'] ?? 'تعذر البحث.');
                if ($collected !== []) {
                    break;
                }

                return [
                    'ok' => false,
                    'message' => $message,
                    'items' => [],
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => 0,
                    'hasMore' => false,
                ];
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $rows = is_array($data['items'] ?? null) ? $data['items'] : [];
            $apiTotal = max(0, (int) ($data['totalCount'] ?? $data['TotalCount'] ?? 0));
            $apiHasMore = $apiTotal > 0
                ? ($apiPage * $apiPageSize) < $apiTotal
                : count($rows) >= $apiPageSize;

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (!self::matchesAllTerms($row, $terms)) {
                    continue;
                }
                if ($matched >= $skip && count($collected) < $pageSize) {
                    $item = self::mapPickerItem($row);
                    if ((string) ($item['value'] ?? '') !== '') {
                        $collected[] = $item;
                    }
                }
                $matched++;
            }

            if (count($collected) >= $pageSize) {
                $hasMore = $matched > ($skip + $pageSize) || $apiHasMore;
                break;
            }

            if (!$apiHasMore) {
                break;
            }

            $apiPage++;
        }

        if (!$hasMore && $matched > ($skip + count($collected))) {
            $hasMore = true;
        }

        return [
            'ok' => true,
            'message' => '',
            'items' => $collected,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $matched,
            'hasMore' => $hasMore,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function mapPickerItem(array $row): array
    {
        $guid = trim((string) ($row['materialGuid'] ?? $row['MaterialGuid'] ?? $row['guid'] ?? ''));
        $name = trim((string) ($row['name'] ?? $row['Name'] ?? ''));
        $code = trim((string) ($row['materialCode'] ?? $row['MaterialCode'] ?? ''));
        $mfr = trim((string) ($row['manufacturer'] ?? $row['Manufacturer'] ?? ''));
        $label = $name !== '' ? $name . ($code !== '' ? ' (' . $code . ')' : '') : $code;
        if ($mfr !== '') {
            $label .= ' — ' . $mfr;
        }
        $imageGuid = trim((string) ($row['productImageGuid'] ?? $row['ProductImageGuid'] ?? ''));

        return [
            'value' => $guid,
            'label' => $label,
            'code' => $code,
            'name' => $name,
            'manufacturer' => $mfr,
            'image' => $imageGuid !== '' ? '/api/image.php?id=' . rawurlencode($imageGuid) . '&thumb=1' : '',
        ];
    }
}
