<?php

declare(strict_types=1);

namespace Portal\Services;

/**
 * Decision-support for stagnant stock promotions via GET /api/materials/ages (FIFO).
 */
final class StagnantInventoryService
{
    /**
     * @param array{
     *   minAgeDays?: int,
     *   maxAgeDays?: int|null,
     *   asOf?: string,
     *   keyword?: string,
     *   groupGuids?: list<string>,
     *   storeGuids?: list<string>,
     *   companies?: list<string>,
     *   materialTypes?: list<string>,
     *   sort?: string,
     *   page?: int,
     *   pageSize?: int
     * } $query
     * @return array{ok: bool, error?: string, data?: array<string, mixed>}
     */
    public static function fetch(array $query): array
    {
        $minAgeDays = max(1, min(3650, (int) ($query['minAgeDays'] ?? 50)));
        $maxAgeDays = isset($query['maxAgeDays']) && $query['maxAgeDays'] !== null && $query['maxAgeDays'] !== ''
            ? max($minAgeDays, min(3650, (int) $query['maxAgeDays']))
            : null;
        $page = max(1, (int) ($query['page'] ?? 1));
        $pageSize = max(1, min(200, (int) ($query['pageSize'] ?? 50)));
        $sort = trim((string) ($query['sort'] ?? 'age:desc'));
        $asOf = trim((string) ($query['asOf'] ?? ''));
        $keyword = trim((string) ($query['keyword'] ?? ''));
        $groupGuids = self::normalizeGuids($query['groupGuids'] ?? []);
        $storeGuids = self::normalizeGuids($query['storeGuids'] ?? []);
        $companies = self::normalizeTexts($query['companies'] ?? []);
        $materialTypes = self::normalizeTexts($query['materialTypes'] ?? []);

        $apiQuery = [
            'ageMode' => 'oldest',
            'skipEmpty' => 'true',
            'skipZeroBalance' => 'true',
            'minAgeDays' => $minAgeDays,
            'sort' => $sort !== '' ? $sort : 'age:desc',
            'page' => $page,
            'pageSize' => $pageSize,
        ];
        if ($maxAgeDays !== null) {
            $apiQuery['maxAgeDays'] = $maxAgeDays;
        }
        if ($asOf !== '') {
            $apiQuery['asOf'] = $asOf;
        }
        if ($keyword !== '') {
            $apiQuery['keyword'] = $keyword;
        }
        if ($groupGuids !== []) {
            $apiQuery['groupGuids'] = implode(',', $groupGuids);
        }
        if ($storeGuids !== []) {
            $apiQuery['storeGuids'] = implode(',', $storeGuids);
        }
        if ($companies !== []) {
            $apiQuery['companies'] = implode(',', $companies);
        }
        if ($materialTypes !== []) {
            $apiQuery['materialTypes'] = implode(',', $materialTypes);
        }

        try {
            $response = ApiClient::get('/api/materials/ages', $apiQuery, 180);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'تعذر الاتصال بـ API الأمين: ' . $e->getMessage()];
        }

        if (!($response['ok'] ?? false)) {
            $status = (int) ($response['status'] ?? 0);
            $data = $response['data'] ?? null;
            $message = '';
            if (is_array($data)) {
                $message = trim((string) ($data['message'] ?? $data['title'] ?? $data['detail'] ?? ''));
                if ($message === '' && isset($data['errors']) && is_array($data['errors'])) {
                    $message = json_encode($data['errors'], JSON_UNESCAPED_UNICODE) ?: '';
                }
            }
            if ($message === '') {
                $message = trim((string) ($response['error'] ?? ''));
            }
            if ($message === '') {
                $message = match (true) {
                    $status === 404 => 'مسار أعمار المواد غير متاح على API الأمين.',
                    $status === 401, $status === 403 => 'حساب API الموقع بلا صلاحية قراءة المواد.',
                    $status === 0 => 'تعذر الاتصال بخادم API الأمين.',
                    $status >= 500 => 'خطأ داخل API الأمين أثناء حساب الأعمار.',
                    default => 'فشل جلب تقرير أعمار المواد.',
                };
            }
            if ($status > 0) {
                $message .= ' [HTTP ' . $status . ']';
            }

            return ['ok' => false, 'error' => $message];
        }

        $payload = is_array($response['data'] ?? null) ? $response['data'] : [];

        return [
            'ok' => true,
            'data' => self::normalizePayload($payload, $minAgeDays),
        ];
    }

    /**
     * Create a manual special offer from selected stagnant materials.
     *
     * @param list<string> $materialGuids
     * @param array<string, float|int|string> $perMaterialPercents guid => percent
     * @return array{ok: bool, message: string, id?: string|null}
     */
    public static function createOfferFromSelection(
        array $materialGuids,
        array $perMaterialPercents,
        float $defaultPercent,
        string $titleAr,
        ?string $userId,
        bool $asDraft = true
    ): array {
        $guids = [];
        foreach ($materialGuids as $guid) {
            $guid = strtolower(trim((string) $guid));
            if ($guid !== '' && !in_array($guid, $guids, true)) {
                $guids[] = $guid;
            }
        }
        if ($guids === []) {
            return ['ok' => false, 'message' => 'أضف مادة واحدة على الأقل إلى المرشّحين.', 'id' => null];
        }

        $defaultPercent = max(0.01, min(95.0, $defaultPercent));
        $title = trim($titleAr);
        if ($title === '') {
            $title = 'عرض تصريف راكد ' . date('Y-m-d');
        }

        $overrides = [];
        foreach ($guids as $guid) {
            $raw = $perMaterialPercents[$guid]
                ?? $perMaterialPercents[strtolower($guid)]
                ?? $defaultPercent;
            $pct = is_numeric($raw) ? (float) $raw : $defaultPercent;
            $pct = max(0.01, min(95.0, $pct));
            $overrides[$guid] = [
                'discount_type' => 'percent',
                'discount_percent' => $pct,
            ];
        }

        return SpecialOfferService::save([
            'title_ar' => $title,
            'subtitle_ar' => $asDraft ? 'مسودة من المخزون الراكد' : 'مولَّد من المخزون الراكد',
            'badge_text_ar' => 'تصريف',
            'selection_mode' => 'manual',
            'pricing_scope' => 'per_material',
            'discount_type' => 'percent',
            'discount_percent' => $defaultPercent,
            'starts_at' => date('Y-m-d H:i:s'),
            'ends_at' => '',
            'slug' => 'stagnant-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6),
            'is_active' => $asDraft ? 0 : 1,
            'priority' => 10,
            'max_products' => max(1, min(48, count($guids))),
            'show_on_home' => $asDraft ? 0 : 1,
            'home_sort_order' => 0,
            'material_guids' => $guids,
            'display_options' => [
                'show_images' => true,
                'price_mode' => 'both',
            ],
            'filter_rules' => [],
            'product_overrides' => $overrides,
        ], $userId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalizePayload(array $payload, int $minAgeDays): array
    {
        $itemsRaw = $payload['items'] ?? $payload['Items'] ?? [];
        $items = [];
        foreach (is_array($itemsRaw) ? $itemsRaw : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $guid = (string) ($row['materialGuid'] ?? $row['MaterialGuid'] ?? '');
            if ($guid === '') {
                continue;
            }
            $imageGuid = (string) ($row['pictureGuid'] ?? $row['PictureGuid'] ?? '');
            $oldestDate = (string) ($row['oldestDate'] ?? $row['OldestDate'] ?? '');
            if (strlen($oldestDate) >= 10) {
                $oldestDate = substr($oldestDate, 0, 10);
            }

            $items[] = [
                'materialGuid' => $guid,
                'name' => (string) ($row['name'] ?? $row['Name'] ?? ''),
                'materialCode' => (string) ($row['code'] ?? $row['Code'] ?? ''),
                'groupGuid' => (string) ($row['groupGuid'] ?? $row['GroupGuid'] ?? ''),
                'groupName' => (string) ($row['groupName'] ?? $row['GroupName'] ?? ''),
                'company' => (string) ($row['company'] ?? $row['Company'] ?? ''),
                'materialType' => (string) ($row['materialType'] ?? $row['MaterialType'] ?? ''),
                'productImageGuid' => $imageGuid !== '' && $imageGuid !== '00000000-0000-0000-0000-000000000000' ? $imageGuid : '',
                'stagnantRemainingQty' => (float) ($row['remainingQuantity'] ?? $row['RemainingQuantity'] ?? 0),
                'maxAgeDays' => (float) ($row['oldestAgeDays'] ?? $row['OldestAgeDays'] ?? 0),
                'oldestPurchaseDate' => $oldestDate,
                'unitSalePriceUsd' => self::nullableNumber($row['half'] ?? $row['Half'] ?? null),
                'unitPurchasePriceUsd' => self::nullableNumber($row['endUser'] ?? $row['EndUser'] ?? null),
                'unitSalePriceSyp' => self::nullableNumber($row['whole'] ?? $row['Whole'] ?? null),
                'currencyVal' => self::nullableNumber($row['currencyVal'] ?? $row['CurrencyVal'] ?? null),
                'storeBalances' => is_array($row['storeBalances'] ?? null) ? $row['storeBalances'] : [],
            ];
        }

        return [
            'page' => (int) ($payload['page'] ?? $payload['Page'] ?? 1),
            'pageSize' => (int) ($payload['pageSize'] ?? $payload['PageSize'] ?? 50),
            'totalCount' => (int) ($payload['totalCount'] ?? $payload['TotalCount'] ?? count($items)),
            'minAgeDays' => $minAgeDays,
            'asOf' => (string) ($payload['asOf'] ?? $payload['AsOf'] ?? ''),
            'items' => $items,
        ];
    }

    /**
     * @param mixed $values
     * @return list<string>
     */
    private static function normalizeGuids(mixed $values): array
    {
        $guids = [];
        foreach (is_array($values) ? $values : [] as $guid) {
            $guid = strtolower(trim((string) $guid));
            if ($guid !== '' && !in_array($guid, $guids, true)) {
                $guids[] = $guid;
            }
        }

        return $guids;
    }

    /**
     * @param mixed $values
     * @return list<string>
     */
    private static function normalizeTexts(mixed $values): array
    {
        $out = [];
        foreach (is_array($values) ? $values : [] as $value) {
            $value = trim((string) $value);
            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    private static function nullableNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
