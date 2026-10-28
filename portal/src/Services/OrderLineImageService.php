<?php

declare(strict_types=1);

namespace Portal\Services;

/**
 * Resolves the current catalog image for historical order lines.
 */
final class OrderLineImageService
{
    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function applyCurrentMaterialImages(array $items): array
    {
        if ($items === []) {
            return $items;
        }

        $materialGuids = [];
        foreach ($items as $item) {
            $guid = trim((string) ($item['material_guid'] ?? ''));
            if ($guid !== '') {
                $materialGuids[strtolower($guid)] = $guid;
            }
        }
        if ($materialGuids === []) {
            return $items;
        }

        $pictureByMaterial = self::fetchPictureGuidsByMaterial(array_values($materialGuids));

        foreach ($items as &$item) {
            $materialGuid = trim((string) ($item['material_guid'] ?? ''));
            if ($materialGuid === '') {
                continue;
            }
            $imageGuid = $pictureByMaterial[strtolower($materialGuid)] ?? '';
            if ($imageGuid === '') {
                continue;
            }
            $item['image_url'] = MaterialImageStorageService::imageGuidUrl($imageGuid, true);
        }
        unset($item);

        return $items;
    }

    public static function currentThumbUrlForMaterial(string $materialGuid): string
    {
        $materialGuid = trim($materialGuid);
        if ($materialGuid === '') {
            return '';
        }

        $map = self::fetchPictureGuidsByMaterial([$materialGuid]);
        $imageGuid = $map[strtolower($materialGuid)] ?? '';
        if ($imageGuid === '') {
            return '';
        }

        return MaterialImageStorageService::imageGuidUrl($imageGuid, true);
    }

    /**
     * @param list<string> $materialGuids
     * @return array<string, string> lowercase material guid => lowercase image guid
     */
    private static function fetchPictureGuidsByMaterial(array $materialGuids): array
    {
        $materialGuids = array_values(array_unique(array_filter(array_map('strval', $materialGuids), static fn (string $g): bool => trim($g) !== '')));
        if ($materialGuids === []) {
            return [];
        }

        $map = [];

        $response = ApiClient::get('/api/materials', [
            'materialGuids' => implode(',', $materialGuids),
            'page' => 1,
            'pageSize' => max(1, count($materialGuids)),
            'includeTotalCount' => 'false',
        ]);
        if ($response['ok'] ?? false) {
            $items = is_array($response['data']['items'] ?? null) ? $response['data']['items'] : [];
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $materialGuid = trim((string) ($row['materialGuid'] ?? $row['MaterialGuid'] ?? $row['guid'] ?? $row['Guid'] ?? ''));
                $imageGuid = self::pictureGuidFromMaterialRow($row);
                if ($materialGuid !== '' && $imageGuid !== '') {
                    $map[strtolower($materialGuid)] = strtolower($imageGuid);
                }
            }
        }

        $missing = array_values(array_filter(
            $materialGuids,
            static fn (string $guid): bool => !isset($map[strtolower($guid)])
        ));
        if ($missing === []) {
            return $map;
        }

        $requests = [];
        foreach ($missing as $guid) {
            $requests[] = ['key' => strtolower($guid), 'path' => '/api/materials/' . rawurlencode($guid)];
        }
        foreach (ApiClient::getMany($requests, 20) as $key => $itemResponse) {
            if (!($itemResponse['ok'] ?? false) || !is_array($itemResponse['data'] ?? null)) {
                continue;
            }
            $imageGuid = self::pictureGuidFromMaterialRow($itemResponse['data']);
            if ($imageGuid !== '') {
                $map[(string) $key] = strtolower($imageGuid);
            }
        }

        return $map;
    }

    /** @param array<string, mixed> $row */
    private static function pictureGuidFromMaterialRow(array $row): string
    {
        foreach (['productImageGuid', 'ProductImageGuid', 'pictureGuid', 'PictureGuid', 'PictureGUID'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
