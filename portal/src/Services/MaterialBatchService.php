<?php

declare(strict_types=1);

namespace Portal\Services;

/** جلب مواد متعددة من الأمين في طلب واحد (أو على دفعات) بدل طلب لكل مادة. */
final class MaterialBatchService
{
    private const MAX_GUIDS_PER_REQUEST = 200;

    /**
     * @param list<string> $guids
     * @return array<string, array<string, mixed>> guid => material payload
     */
    public static function fetchByGuids(array $guids, int $timeoutSeconds = 25): array
    {
        $guids = array_values(array_unique(array_filter(
            array_map(static fn ($guid): string => trim((string) $guid),
            $guids),
            static fn (string $guid): bool => $guid !== ''
        )));

        if ($guids === []) {
            return [];
        }

        $materials = [];
        foreach (array_chunk($guids, self::MAX_GUIDS_PER_REQUEST) as $chunk) {
            $materials += self::fetchChunkByGuids($chunk, $timeoutSeconds);
        }

        return $materials;
    }

    /**
     * @param list<string> $guids
     * @return array<string, array<string, mixed>>
     */
    private static function fetchChunkByGuids(array $guids, int $timeoutSeconds): array
    {
        $batch = self::fetchChunkViaListEndpoint($guids, $timeoutSeconds);
        if ($batch !== null) {
            return $batch;
        }

        return self::fetchChunkViaParallelRequests($guids, $timeoutSeconds);
    }

    /**
     * @param list<string> $guids
     * @return array<string, array<string, mixed>>|null
     */
    private static function fetchChunkViaListEndpoint(array $guids, int $timeoutSeconds): ?array
    {
        try {
            $response = ApiClient::get('/api/materials', [
                'materialGuids' => implode(',', $guids),
                'page' => 1,
                'pageSize' => count($guids),
            ], $timeoutSeconds);

            if (!($response['ok'] ?? false)) {
                return null;
            }

            $items = $response['data']['items'] ?? null;
            if (!is_array($items)) {
                return null;
            }

            $materials = [];
            $requested = array_fill_keys($guids, true);
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $guid = self::materialGuid($item);
                if ($guid === '') {
                    continue;
                }
                if (!isset($requested[$guid])) {
                    return null;
                }
                $materials[$guid] = $item;
            }

            if ($materials === [] && $guids !== []) {
                return null;
            }

            return $materials;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $guids
     * @return array<string, array<string, mixed>>
     */
    private static function fetchChunkViaParallelRequests(array $guids, int $timeoutSeconds): array
    {
        $requests = [];
        foreach ($guids as $guid) {
            $requests[] = [
                'key' => $guid,
                'path' => '/api/materials/' . rawurlencode($guid),
            ];
        }

        $responses = ApiClient::getMany($requests, $timeoutSeconds);
        $materials = [];
        foreach ($guids as $guid) {
            $response = $responses[$guid] ?? null;
            if (!is_array($response) || !($response['ok'] ?? false) || !is_array($response['data'] ?? null)) {
                continue;
            }
            $materials[$guid] = $response['data'];
        }

        return $materials;
    }

    /** @param array<string, mixed> $item */
    private static function materialGuid(array $item): string
    {
        foreach (['guid', 'Guid', 'materialGuid', 'MaterialGuid'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
