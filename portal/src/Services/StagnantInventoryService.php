<?php

declare(strict_types=1);

namespace Portal\Services;

/**
 * Decision-support for stagnant stock promotions via Amine dbo.repGetAges_bi.
 */
final class StagnantInventoryService
{
    /**
     * @param array{
     *   minAgeDays?: int,
     *   untilDate?: string,
     *   yearStartDate?: string,
     *   groupGuids?: list<string>,
     *   sort?: string,
     *   page?: int,
     *   pageSize?: int
     * } $query
     * @return array{ok: bool, error?: string, data?: array<string, mixed>}
     */
    public static function fetch(array $query): array
    {
        $minAgeDays = max(1, min(3650, (int) ($query['minAgeDays'] ?? 50)));
        $page = max(1, (int) ($query['page'] ?? 1));
        $pageSize = max(1, min(200, (int) ($query['pageSize'] ?? 50)));
        $sort = trim((string) ($query['sort'] ?? 'age:desc'));
        $untilDate = trim((string) ($query['untilDate'] ?? ''));
        $yearStartDate = trim((string) ($query['yearStartDate'] ?? ''));
        $groupGuids = [];
        foreach ($query['groupGuids'] ?? [] as $guid) {
            $guid = trim((string) $guid);
            if ($guid !== '') {
                $groupGuids[] = $guid;
            }
        }

        $apiQuery = [
            'minAgeDays' => $minAgeDays,
            'sort' => $sort !== '' ? $sort : 'age:desc',
            'page' => $page,
            'pageSize' => $pageSize,
        ];
        if ($untilDate !== '') {
            $apiQuery['untilDate'] = $untilDate;
        }
        if ($yearStartDate !== '') {
            $apiQuery['yearStartDate'] = $yearStartDate;
        }
        if ($groupGuids !== []) {
            $apiQuery['groupGuids'] = implode(',', array_values(array_unique($groupGuids)));
        }

        try {
            $response = ApiClient::get('/api/inventory/ages', $apiQuery, 180);
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
                    $status === 404 => 'المسار /api/inventory/ages غير موجود — أعد نشر API من فرع الأداة ثم أعد تشغيل الخدمة.',
                    $status === 401, $status === 403 => 'حساب API الموقع بلا صلاحية materials.read لتقرير الأعمار.',
                    $status === 0 => 'تعذر الاتصال بخادم API الأمين (تحقق من AMINE_API_BASE_URL والخدمة).',
                    $status >= 500 => 'خطأ داخل API الأمين أثناء تشغيل dbo.repGetAges_bi (راجع سجل الخدمة).',
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
            'data' => self::normalizePayload($payload),
        ];
    }

    /**
     * Create a manual special offer from selected stagnant materials.
     *
     * @param list<string> $materialGuids
     * @return array{ok: bool, message: string, id?: string|null}
     */
    public static function createOfferFromSelection(
        array $materialGuids,
        float $discountPercent,
        string $titleAr,
        ?string $userId
    ): array {
        $guids = [];
        foreach ($materialGuids as $guid) {
            $guid = strtolower(trim((string) $guid));
            if ($guid !== '' && !in_array($guid, $guids, true)) {
                $guids[] = $guid;
            }
        }
        if ($guids === []) {
            return ['ok' => false, 'message' => 'اختر مادة واحدة على الأقل.', 'id' => null];
        }

        $discountPercent = max(0.01, min(95.0, $discountPercent));
        $title = trim($titleAr);
        if ($title === '') {
            $title = 'عرض تصريف راكد ' . date('Y-m-d');
        }

        return SpecialOfferService::save([
            'title_ar' => $title,
            'subtitle_ar' => 'مولَّد من أداة المخزون الراكد',
            'badge_text_ar' => 'تصريف',
            'selection_mode' => 'manual',
            'pricing_scope' => 'offer',
            'discount_type' => 'percent',
            'discount_percent' => $discountPercent,
            'starts_at' => date('Y-m-d\TH:i'),
            'ends_at' => '',
            'is_active' => 1,
            'priority' => 10,
            'max_products' => max(1, min(48, count($guids))),
            'show_on_home' => 1,
            'home_sort_order' => 0,
            'material_guids' => $guids,
            'display_options' => [
                'show_images' => true,
                'price_mode' => 'both',
            ],
            'filter_rules' => [],
            'product_overrides' => [],
        ], $userId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalizePayload(array $payload): array
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
            $imageGuid = (string) ($row['productImageGuid'] ?? $row['ProductImageGuid'] ?? '');
            $items[] = [
                'materialGuid' => $guid,
                'name' => (string) ($row['name'] ?? $row['Name'] ?? ''),
                'materialCode' => (string) ($row['materialCode'] ?? $row['MaterialCode'] ?? ''),
                'groupGuid' => (string) ($row['groupGuid'] ?? $row['GroupGuid'] ?? ''),
                'productImageGuid' => $imageGuid !== '' && $imageGuid !== '00000000-0000-0000-0000-000000000000' ? $imageGuid : '',
                'stagnantRemainingQty' => (float) ($row['stagnantRemainingQty'] ?? $row['StagnantRemainingQty'] ?? 0),
                'totalRemainingQty' => isset($row['totalRemainingQty']) || isset($row['TotalRemainingQty'])
                    ? (float) ($row['totalRemainingQty'] ?? $row['TotalRemainingQty'])
                    : null,
                'maxAgeDays' => (float) ($row['maxAgeDays'] ?? $row['MaxAgeDays'] ?? 0),
                'oldestPurchaseDate' => (string) ($row['oldestPurchaseDate'] ?? $row['OldestPurchaseDate'] ?? ''),
                'unitSalePriceUsd' => self::nullableNumber($row['unitSalePriceUsd'] ?? $row['UnitSalePriceUsd'] ?? null),
                'unitPurchasePriceUsd' => self::nullableNumber($row['unitPurchasePriceUsd'] ?? $row['UnitPurchasePriceUsd'] ?? null),
                'unitSalePriceSyp' => self::nullableNumber($row['unitSalePriceSyp'] ?? $row['UnitSalePriceSyp'] ?? null),
            ];
        }

        return [
            'page' => (int) ($payload['page'] ?? $payload['Page'] ?? 1),
            'pageSize' => (int) ($payload['pageSize'] ?? $payload['PageSize'] ?? 50),
            'totalCount' => (int) ($payload['totalCount'] ?? $payload['TotalCount'] ?? count($items)),
            'minAgeDays' => (int) ($payload['minAgeDays'] ?? $payload['MinAgeDays'] ?? 50),
            'untilDate' => (string) ($payload['untilDate'] ?? $payload['UntilDate'] ?? ''),
            'yearStartDate' => (string) ($payload['yearStartDate'] ?? $payload['YearStartDate'] ?? ''),
            'items' => $items,
        ];
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
