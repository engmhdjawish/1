<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use Portal\Support\SessionGate;
use PDO;
use Throwable;

final class ProcurementService
{
    public const LIFECYCLE_NEW = 'new';
    public const LIFECYCLE_ACTIVE = 'active';
    public const LIFECYCLE_CANCELLED = 'cancelled';

    /** @deprecated use LIFECYCLE_NEW */
    public const LIFECYCLE_TRIAL = self::LIFECYCLE_NEW;
    /** @deprecated use LIFECYCLE_ACTIVE */
    public const LIFECYCLE_SEASON_ACTIVE = self::LIFECYCLE_ACTIVE;
    /** @deprecated use LIFECYCLE_CANCELLED */
    public const LIFECYCLE_DISCONTINUED = self::LIFECYCLE_CANCELLED;

    public const LIFECYCLE_LABELS = [
        self::LIFECYCLE_NEW => 'جديد',
        self::LIFECYCLE_ACTIVE => 'فعال',
        self::LIFECYCLE_CANCELLED => 'ملغي',
    ];

    public static function normalizeLifecycle(string $value): string
    {
        $value = trim($value);

        return match ($value) {
            'trial', self::LIFECYCLE_NEW => self::LIFECYCLE_NEW,
            'season_active', self::LIFECYCLE_ACTIVE => self::LIFECYCLE_ACTIVE,
            'discontinued', self::LIFECYCLE_CANCELLED => self::LIFECYCLE_CANCELLED,
            default => isset(self::LIFECYCLE_LABELS[$value]) ? $value : self::LIFECYCLE_NEW,
        };
    }

    public static function normalizeFilterPreset(string $preset): string
    {
        return match (trim($preset)) {
            'trial' => 'new',
            'season_active' => 'active',
            'discontinued' => 'cancelled',
            default => trim($preset),
        };
    }

    /** @var list<string> */
    public const OPEN_ORDER_STATUSES = ['draft', 'sent', 'partially_received'];

    public static function tableReady(): bool
    {
        try {
            Database::pdo()->query('SELECT 1 FROM procurement_settings LIMIT 1');
            Database::pdo()->query('SELECT 1 FROM procurement_materials LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{store_guids: list<string>, limit_to_current_season: bool} */
    public static function settings(): array
    {
        if (!self::tableReady()) {
            return ['store_guids' => [], 'limit_to_current_season' => true];
        }

        try {
            $row = Database::pdo()->query('SELECT store_guids, limit_to_current_season FROM procurement_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return ['store_guids' => [], 'limit_to_current_season' => true];
        }

        $guids = [];
        if (is_array($row['store_guids'] ?? null)) {
            foreach ($row['store_guids'] as $guid) {
                $guid = trim((string) $guid);
                if ($guid !== '') {
                    $guids[] = $guid;
                }
            }
        } elseif (is_string($row['store_guids'] ?? null)) {
            $decoded = json_decode($row['store_guids'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $guid) {
                    $guid = trim((string) $guid);
                    if ($guid !== '') {
                        $guids[] = $guid;
                    }
                }
            }
        }

        return [
            'store_guids' => array_values(array_unique($guids)),
            'limit_to_current_season' => (bool) ($row['limit_to_current_season'] ?? true),
        ];
    }

    /**
     * @param array{store_guids?: list<string>, limit_to_current_season?: bool} $input
     * @return array{ok: bool, message: string}
     */
    public static function saveSettings(array $input, ?string $userId): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'message' => 'جداول المشتريات غير جاهزة. نفّذ ترحيل قاعدة البيانات.'];
        }

        $guids = [];
        foreach ($input['store_guids'] ?? [] as $guid) {
            $guid = trim((string) $guid);
            if ($guid !== '') {
                $guids[] = $guid;
            }
        }
        $limitSeason = (bool) ($input['limit_to_current_season'] ?? true);
        $json = json_encode(array_values(array_unique($guids)), JSON_THROW_ON_ERROR);

        $stmt = Database::pdo()->prepare(
            'UPDATE procurement_settings
             SET store_guids = :store_guids::jsonb,
                 limit_to_current_season = :limit_season,
                 updated_at = NOW(),
                 updated_by_user_id = :user_id
             WHERE id = 1'
        );
        $stmt->execute([
            'store_guids' => $json,
            'limit_season' => $limitSeason ? 'true' : 'false',
            'user_id' => $userId,
        ]);

        return ['ok' => true, 'message' => 'تم حفظ إعدادات المشتريات.'];
    }

    /**
     * @return array{ok: bool, message: string, seen?: int, new?: int}
     */
    public static function syncMaterialsFromAmine(?string $userId = null, int $maxPages = 400): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'message' => 'جداول المشتريات غير جاهزة.'];
        }

        $pdo = Database::pdo();
        $runId = self::startSyncRun($pdo);
        $seen = 0;
        $newCount = 0;

        $insert = $pdo->prepare(
            'INSERT INTO procurement_materials (
                material_guid, material_code, material_name_ar, manufacturer, group_guid, lifecycle, first_seen_at
             ) VALUES (
                :guid, :code, :name, :manufacturer, :group_guid, :lifecycle, NOW()
             )
             ON CONFLICT (material_guid) DO NOTHING'
        );

        $updateCache = $pdo->prepare(
            'UPDATE procurement_materials SET
                material_code = :code,
                material_name_ar = :name,
                manufacturer = :manufacturer,
                group_guid = :group_guid,
                updated_at = NOW()
             WHERE material_guid = :guid'
        );

        try {
            for ($page = 1; $page <= $maxPages; $page++) {
                $response = SessionGate::withoutLock(static function () use ($page): array {
                    return ApiClient::get('/api/materials', [
                        'page' => $page,
                        'pageSize' => 200,
                    ], 120);
                });

                if (!($response['ok'] ?? false)) {
                    throw new \RuntimeException('تعذر جلب المواد من API: ' . (string) ($response['error'] ?? ''));
                }

                $data = $response['data'] ?? [];
                $items = is_array($data['items'] ?? null) ? $data['items'] : (is_array($data) ? $data : []);
                if ($items === []) {
                    break;
                }

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $guid = self::materialGuidFromApi($item);
                    if ($guid === '') {
                        continue;
                    }
                    $seen++;
                    $fields = self::cacheFieldsFromApi($item);

                    $insert->execute([
                        'guid' => $guid,
                        'code' => $fields['code'],
                        'name' => $fields['name'],
                        'manufacturer' => $fields['manufacturer'],
                        'group_guid' => $fields['group_guid'],
                        'lifecycle' => self::LIFECYCLE_NEW,
                    ]);
                    if ($insert->rowCount() > 0) {
                        $newCount++;
                    }

                    $updateCache->execute([
                        'guid' => $guid,
                        'code' => $fields['code'],
                        'name' => $fields['name'],
                        'manufacturer' => $fields['manufacturer'],
                        'group_guid' => $fields['group_guid'],
                    ]);
                }

                $total = (int) ($data['totalCount'] ?? $data['total'] ?? 0);
                $pageSize = (int) ($data['pageSize'] ?? 200);
                if ($total > 0 && $page * max(1, $pageSize) >= $total) {
                    break;
                }
                if (count($items) < 200) {
                    break;
                }
            }

            self::finishSyncRun($pdo, $runId, 'ok', $seen, $newCount, '');

            return [
                'ok' => true,
                'message' => sprintf('تمت المزامنة: %d مادة، %d جديدة في سجل المشتريات.', $seen, $newCount),
                'seen' => $seen,
                'new' => $newCount,
            ];
        } catch (Throwable $exception) {
            self::finishSyncRun($pdo, $runId, 'failed', $seen, $newCount, $exception->getMessage());

            return ['ok' => false, 'message' => $exception->getMessage(), 'seen' => $seen, 'new' => $newCount];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listMaterials(string $filter = 'needs_config', int $limit = 200): array
    {
        $result = self::searchMaterialsCatalog([
            'preset' => $filter,
            'page' => 1,
            'page_size' => $limit,
        ]);

        return $result['items'];
    }

    /**
     * @param array{q?: string, manufacturer?: string, preset?: string, page?: int, page_size?: int} $criteria
     * @return array{items: list<array<string, mixed>>, total: int, page: int, page_size: int}
     */
    public static function searchMaterialsCatalog(array $criteria): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'page_size' => 50];
        }

        $page = max(1, (int) ($criteria['page'] ?? 1));
        $pageSize = max(10, min(100, (int) ($criteria['page_size'] ?? 50)));
        $offset = ($page - 1) * $pageSize;

        $where = ['TRUE'];
        $params = [];
        $preset = self::normalizeFilterPreset((string) ($criteria['preset'] ?? 'needs_config'));
        if ($preset === 'needs_config') {
            $where[] = 'configured_at IS NULL';
            $where[] = "lifecycle <> 'cancelled'";
        } elseif ($preset === 'active') {
            $where[] = "lifecycle = 'active'";
        } elseif ($preset === 'new') {
            $where[] = "lifecycle = 'new'";
        } elseif ($preset === 'cancelled') {
            $where[] = "lifecycle = 'cancelled'";
        }

        $q = trim((string) ($criteria['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(material_code ILIKE :q OR material_name_ar ILIKE :q OR manufacturer ILIKE :q)';
            $params['q'] = '%' . $q . '%';
        }

        $manufacturer = trim((string) ($criteria['manufacturer'] ?? ''));
        if ($manufacturer !== '') {
            $where[] = 'manufacturer = :manufacturer';
            $params['manufacturer'] = $manufacturer;
        }

        $whereSql = implode(' AND ', $where);
        $pdo = Database::pdo();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM procurement_materials WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = "SELECT * FROM procurement_materials WHERE {$whereSql}
                ORDER BY first_seen_at DESC NULLS LAST
                LIMIT {$pageSize} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * Ensures a procurement_materials row exists (جديد) with API cache fields.
     *
     * @return array{ok: bool, message: string}
     */
    public static function ensureMaterialRegistered(string $materialGuid): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'message' => 'جداول المشتريات غير جاهزة.'];
        }

        $materialGuid = trim($materialGuid);
        if ($materialGuid === '') {
            return ['ok' => false, 'message' => 'معرف المادة غير صالح.'];
        }

        if (self::policyRow($materialGuid) !== null) {
            return ['ok' => true, 'message' => 'مسجّلة مسبقاً.'];
        }

        $settings = self::settings();
        $storeGuids = $settings['store_guids'] !== [] ? $settings['store_guids'] : [];
        $material = null;
        if ($storeGuids !== []) {
            $material = self::fetchMaterialsByGuids([$materialGuid], $storeGuids)[strtolower($materialGuid)] ?? null;
        }
        if (!is_array($material)) {
            $response = SessionGate::withoutLock(static function () use ($materialGuid): array {
                return ApiClient::get('/api/materials/' . rawurlencode($materialGuid), [], 60);
            });
            if (($response['ok'] ?? false) && is_array($response['data'] ?? null)) {
                $material = $response['data'];
            }
        }

        if (!is_array($material)) {
            return ['ok' => false, 'message' => 'المادة غير موجودة في الأمين.'];
        }

        $fields = self::cacheFieldsFromApi($material);
        Database::pdo()->prepare(
            'INSERT INTO procurement_materials (
                material_guid, material_code, material_name_ar, manufacturer, group_guid, lifecycle, first_seen_at
             ) VALUES (
                :guid, :code, :name, :manufacturer, :group_guid, :lifecycle, NOW()
             )
             ON CONFLICT (material_guid) DO NOTHING'
        )->execute([
            'guid' => $materialGuid,
            'code' => $fields['code'],
            'name' => $fields['name'],
            'manufacturer' => $fields['manufacturer'],
            'group_guid' => $fields['group_guid'],
            'lifecycle' => self::LIFECYCLE_NEW,
        ]);

        return ['ok' => true, 'message' => 'تمت إضافة المادة للسجل.'];
    }

    /**
     * @param list<string> $guids
     * @return list<array<string, mixed>>
     */
    public static function materialInvoiceRows(array $guids): array
    {
        $guids = array_values(array_unique(array_filter(array_map('trim', $guids))));
        if ($guids === [] || !self::tableReady()) {
            return [];
        }

        foreach ($guids as $guid) {
            self::ensureMaterialRegistered($guid);
        }

        $policies = self::policyRowsForGuids($guids);
        $settings = self::settings();
        $byGuid = $settings['store_guids'] !== []
            ? self::fetchMaterialsByGuids($guids, $settings['store_guids'])
            : [];

        StockReservationService::forgetWarehouseMemo();
        $reserved = $settings['store_guids'] !== []
            ? StockReservationService::reservedPrimaryByMaterial($guids)
            : [];

        $rows = [];
        foreach ($guids as $guid) {
            $key = strtolower($guid);
            $policy = $policies[$key] ?? null;
            $material = $byGuid[$key] ?? null;
            $snapshot = is_array($material)
                ? self::buildLineSnapshot($guid, $material, $policy, $reserved)
                : null;

            $rows[] = [
                'material_guid' => $guid,
                'material_code' => (string) ($policy['material_code'] ?? $snapshot['material_code'] ?? ''),
                'material_name_ar' => (string) ($policy['material_name_ar'] ?? $snapshot['material_name_ar'] ?? ''),
                'manufacturer' => (string) ($policy['manufacturer'] ?? $snapshot['manufacturer'] ?? ''),
                'lifecycle' => self::normalizeLifecycle((string) ($policy['lifecycle'] ?? self::LIFECYCLE_NEW)),
                'min_cartons' => $policy['min_cartons'] ?? null,
                'max_cartons' => $policy['max_cartons'] ?? null,
                'notes_ar' => (string) ($policy['notes_ar'] ?? ''),
                'available_cartons' => $snapshot['available_cartons'] ?? null,
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    public static function distinctManufacturers(int $limit = 300): array
    {
        if (!self::tableReady()) {
            return [];
        }

        try {
            $rows = Database::pdo()->query(
                'SELECT DISTINCT manufacturer FROM procurement_materials
                 WHERE manufacturer <> \'\'
                 ORDER BY manufacturer
                 LIMIT ' . max(1, min(1000, $limit))
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable) {
            return [];
        }

        return array_values(array_map('strval', $rows));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, message: string}
     */
    public static function saveMaterial(string $materialGuid, array $input, ?string $userId): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'message' => 'جداول المشتريات غير جاهزة.'];
        }

        $materialGuid = trim($materialGuid);
        if ($materialGuid === '') {
            return ['ok' => false, 'message' => 'معرف المادة غير صالح.'];
        }

        $lifecycle = self::normalizeLifecycle(trim((string) ($input['lifecycle'] ?? self::LIFECYCLE_NEW)));
        if (!isset(self::LIFECYCLE_LABELS[$lifecycle])) {
            return ['ok' => false, 'message' => 'حالة المادة غير معروفة.'];
        }

        $minCartons = self::parseNullableCartons($input['min_cartons'] ?? null);
        $maxCartons = self::parseNullableCartons($input['max_cartons'] ?? null);
        if ($lifecycle === self::LIFECYCLE_ACTIVE) {
            if ($minCartons === null || $maxCartons === null) {
                return ['ok' => false, 'message' => 'للمواد الفعّالة أدخل حدّ الطلب (أدنى) وسقف المخزون (أقصى) بالطرد.'];
            }
            if ($maxCartons < $minCartons) {
                return ['ok' => false, 'message' => 'سقف المخزون يجب أن يكون ≥ حد تفعيل الطلب.'];
            }
            if ($maxCartons <= 0) {
                return ['ok' => false, 'message' => 'السقف يجب أن يكون أكبر من صفر (استخدم أرقام 5 و 10 وليس فارغاً).'];
            }
        }

        $notes = trim((string) ($input['notes_ar'] ?? ''));

        self::refreshMaterialCacheFromApi($materialGuid);

        $pdo = Database::pdo();
        $exists = $pdo->prepare('SELECT 1 FROM procurement_materials WHERE material_guid = :guid');
        $exists->execute(['guid' => $materialGuid]);
        if (!$exists->fetchColumn()) {
            $pdo->prepare(
                'INSERT INTO procurement_materials (material_guid, lifecycle, first_seen_at) VALUES (:guid, :lifecycle, NOW())'
            )->execute(['guid' => $materialGuid, 'lifecycle' => $lifecycle]);
        }

        $stmt = $pdo->prepare(
            "UPDATE procurement_materials SET
                lifecycle = :lifecycle,
                min_cartons = :min_cartons,
                max_cartons = :max_cartons,
                notes_ar = :notes,
                configured_at = CASE
                    WHEN :lifecycle2 IN ('active', 'cancelled') THEN NOW()
                    ELSE configured_at
                END,
                configured_by_user_id = CASE
                    WHEN :lifecycle3 IN ('active', 'cancelled') THEN :user_id::uuid
                    ELSE configured_by_user_id
                END,
                updated_at = NOW()
             WHERE material_guid = :guid"
        );
        $stmt->execute([
            'lifecycle' => $lifecycle,
            'lifecycle2' => $lifecycle,
            'lifecycle3' => $lifecycle,
            'min_cartons' => $minCartons,
            'max_cartons' => $maxCartons,
            'notes' => $notes,
            'user_id' => $userId,
            'guid' => $materialGuid,
        ]);

        return ['ok' => true, 'message' => 'تم حفظ سياسة المادة.'];
    }

    /** @return array<string, true> */
    public static function materialGuidsWithOpenLines(): array
    {
        if (!self::tableReady()) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count(self::OPEN_ORDER_STATUSES), '?'));
        $sql = "SELECT DISTINCT l.material_guid::text AS material_guid
                FROM procurement_order_lines l
                INNER JOIN procurement_orders o ON o.id = l.order_id
                WHERE l.line_status = 'open'
                  AND o.status IN ($placeholders)";

        try {
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute(self::OPEN_ORDER_STATUSES);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $guid = strtolower(trim((string) ($row['material_guid'] ?? '')));
            if ($guid !== '') {
                $map[$guid] = true;
            }
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function computeShortageLines(): array
    {
        if (!self::tableReady()) {
            return [];
        }

        $settings = self::settings();
        $storeGuids = $settings['store_guids'];
        if ($storeGuids === []) {
            return [];
        }

        $seasonGroups = [];
        if ($settings['limit_to_current_season']) {
            $season = StoreSeasonService::current();
            if ($season !== null && ($season['group_guids'] ?? []) !== []) {
                foreach ($season['group_guids'] as $guid) {
                    $seasonGroups[strtolower(trim((string) $guid))] = true;
                }
            }
        }

        $stmt = Database::pdo()->query(
            "SELECT * FROM procurement_materials
             WHERE lifecycle IN ('active', 'season_active')
               AND min_cartons IS NOT NULL
               AND max_cartons IS NOT NULL"
        );
        $policies = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($policies === []) {
            return [];
        }

        $openLines = self::materialGuidsWithOpenLines();
        $guids = [];
        foreach ($policies as $policy) {
            $guid = strtolower(trim((string) ($policy['material_guid'] ?? '')));
            if ($guid === '' || isset($openLines[$guid])) {
                continue;
            }
            $guids[] = (string) $policy['material_guid'];
        }

        if ($guids === []) {
            return [];
        }

        $materialsByGuid = self::fetchMaterialsByGuids($guids, $storeGuids);
        StockReservationService::forgetWarehouseMemo();
        $reserved = StockReservationService::reservedPrimaryByMaterial($guids);

        $lines = [];
        foreach ($policies as $policy) {
            $guid = trim((string) ($policy['material_guid'] ?? ''));
            $guidKey = strtolower($guid);
            if ($guid === '' || isset($openLines[$guidKey])) {
                continue;
            }
            $material = self::fetchMaterialWithStores($guid, $storeGuids, $materialsByGuid);
            if (!is_array($material)) {
                continue;
            }

            if ($seasonGroups !== []) {
                $groupGuid = self::materialGroupGuidForSeason($policy, $material, $guid);
                if ($groupGuid === '' || !isset($seasonGroups[$groupGuid])) {
                    continue;
                }
            }

            $pcs = max(1.0, ShareCartService::packaging($material));
            $warehousePrimary = max(0.0, (float) ($material['warehouseQuantity'] ?? $material['WarehouseQuantity'] ?? 0));
            $reservedPrimary = (float) ($reserved[$guid] ?? $reserved[$guidKey] ?? 0.0);
            $availablePrimary = max(0.0, $warehousePrimary - $reservedPrimary);
            $availableCartons = $availablePrimary / $pcs;

            $minCartons = (float) $policy['min_cartons'];
            $maxCartons = (float) $policy['max_cartons'];
            if ($availableCartons > $minCartons) {
                continue;
            }

            $targetPrimary = $maxCartons * $pcs;
            $needPrimary = max(0.0, $targetPrimary - $availablePrimary);
            $orderCartons = (int) ceil($needPrimary / $pcs);
            if ($orderCartons <= 0) {
                continue;
            }

            $manufacturer = trim((string) ($policy['manufacturer'] ?? $material['manufacturer'] ?? ''));
            if ($manufacturer === '') {
                $manufacturer = '— بدون شركة —';
            }

            $lines[] = [
                'material_guid' => $guid,
                'material_code' => (string) ($policy['material_code'] ?? $material['materialCode'] ?? ''),
                'material_name_ar' => (string) ($policy['material_name_ar'] ?? $material['name'] ?? ''),
                'manufacturer' => $manufacturer,
                'available_cartons' => round($availableCartons, 3),
                'min_cartons' => $minCartons,
                'max_cartons' => $maxCartons,
                'suggested_cartons' => (float) $orderCartons,
                'pcs_per_carton' => $pcs,
            ];
        }

        usort($lines, static function (array $a, array $b): int {
            $cmp = strcmp((string) $a['manufacturer'], (string) $b['manufacturer']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['material_code'], (string) $b['material_code']);
        });

        return $lines;
    }

    /**
     * Why the shortages tab may be empty (for staff UI hints).
     *
     * @return array{
     *   stores_ok: bool,
     *   active_with_limits: int,
     *   season_filter_on: bool,
     *   season_name: string,
     *   shortage_count: int,
     *   skipped_season: int,
     *   skipped_stock_above_min: int,
     *   skipped_open_line: int,
     *   skipped_not_in_api: int,
     *   samples_above_min: list<array{code: string, name: string, available_cartons: float, min_cartons: float}>
     * }
     */
    public static function shortagesDiagnostics(): array
    {
        $empty = [
            'stores_ok' => false,
            'active_with_limits' => 0,
            'season_filter_on' => false,
            'season_name' => '',
            'shortage_count' => 0,
            'skipped_season' => 0,
            'skipped_stock_above_min' => 0,
            'skipped_open_line' => 0,
            'skipped_not_in_api' => 0,
            'samples_above_min' => [],
        ];

        if (!self::tableReady()) {
            return $empty;
        }

        $settings = self::settings();
        $storeGuids = $settings['store_guids'];
        if ($storeGuids === []) {
            return $empty;
        }

        $empty['stores_ok'] = true;

        $seasonGroups = [];
        if ($settings['limit_to_current_season']) {
            $season = StoreSeasonService::current();
            $empty['season_filter_on'] = true;
            $empty['season_name'] = is_array($season) ? (string) ($season['name_ar'] ?? '') : '';
            if ($season !== null && ($season['group_guids'] ?? []) !== []) {
                foreach ($season['group_guids'] as $guid) {
                    $seasonGroups[strtolower(trim((string) $guid))] = true;
                }
            }
        }

        $stmt = Database::pdo()->query(
            "SELECT * FROM procurement_materials
             WHERE lifecycle IN ('active', 'season_active', 'new', 'trial')
               AND min_cartons IS NOT NULL
               AND max_cartons IS NOT NULL"
        );
        $policies = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($policies as $policy) {
            $lc = self::normalizeLifecycle((string) ($policy['lifecycle'] ?? ''));
            if ($lc !== self::LIFECYCLE_ACTIVE) {
                continue;
            }
            $empty['active_with_limits']++;
        }

        $stmtActive = Database::pdo()->query(
            "SELECT * FROM procurement_materials
             WHERE lifecycle IN ('active', 'season_active')
               AND min_cartons IS NOT NULL
               AND max_cartons IS NOT NULL"
        );
        $activePolicies = $stmtActive->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($activePolicies === []) {
            return $empty;
        }

        $openLines = self::materialGuidsWithOpenLines();
        $guids = [];
        foreach ($activePolicies as $policy) {
            $guid = strtolower(trim((string) ($policy['material_guid'] ?? '')));
            if ($guid === '' || isset($openLines[$guid])) {
                continue;
            }
            $guids[] = (string) $policy['material_guid'];
        }

        $materialsByGuid = $guids !== [] ? self::fetchMaterialsByGuids($guids, $storeGuids) : [];
        StockReservationService::forgetWarehouseMemo();
        $reserved = $guids !== [] ? StockReservationService::reservedPrimaryByMaterial($guids) : [];

        foreach ($activePolicies as $policy) {
            $guid = trim((string) ($policy['material_guid'] ?? ''));
            $guidKey = strtolower($guid);
            if ($guid === '') {
                continue;
            }
            if (isset($openLines[$guidKey])) {
                $empty['skipped_open_line']++;
                continue;
            }
            $material = self::fetchMaterialWithStores($guid, $storeGuids, $materialsByGuid);
            if (!is_array($material)) {
                $empty['skipped_not_in_api']++;
                continue;
            }

            if ($seasonGroups !== []) {
                $groupGuid = self::materialGroupGuidForSeason($policy, $material, $guid);
                if ($groupGuid === '' || !isset($seasonGroups[$groupGuid])) {
                    $empty['skipped_season']++;
                    continue;
                }
            }

            $pcs = max(1.0, ShareCartService::packaging($material));
            $warehousePrimary = max(0.0, (float) ($material['warehouseQuantity'] ?? $material['WarehouseQuantity'] ?? 0));
            $reservedPrimary = (float) ($reserved[$guid] ?? $reserved[$guidKey] ?? 0.0);
            $availablePrimary = max(0.0, $warehousePrimary - $reservedPrimary);
            $availableCartons = $availablePrimary / $pcs;
            $minCartons = (float) $policy['min_cartons'];

            if ($availableCartons > $minCartons) {
                $empty['skipped_stock_above_min']++;
                if (count($empty['samples_above_min']) < 5) {
                    $empty['samples_above_min'][] = [
                        'code' => (string) ($policy['material_code'] ?? ''),
                        'name' => (string) ($policy['material_name_ar'] ?? ''),
                        'available_cartons' => round($availableCartons, 3),
                        'min_cartons' => $minCartons,
                    ];
                }
                continue;
            }

            $maxCartons = (float) $policy['max_cartons'];
            $targetPrimary = $maxCartons * $pcs;
            $needPrimary = max(0.0, $targetPrimary - $availablePrimary);
            $orderCartons = (int) ceil($needPrimary / $pcs);
            if ($orderCartons > 0) {
                $empty['shortage_count']++;
            }
        }

        return $empty;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function shortagesByManufacturer(): array
    {
        $grouped = [];
        foreach (self::computeShortageLines() as $line) {
            $key = (string) $line['manufacturer'];
            $grouped[$key][] = $line;
        }

        return $grouped;
    }

    /**
     * @return array{ok: bool, message: string, order_ids?: list<string>}
     */
    public static function createDraftOrdersFromShortages(?string $userId): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'message' => 'جداول المشتريات غير جاهزة.'];
        }

        $byManufacturer = self::shortagesByManufacturer();
        if ($byManufacturer === []) {
            return ['ok' => false, 'message' => 'لا توجد نواقص حالياً لإنشاء مسودات.'];
        }

        $season = StoreSeasonService::current();
        $seasonId = is_array($season) ? ($season['id'] ?? null) : null;

        $pdo = Database::pdo();
        $orderIds = [];

        try {
            $pdo->beginTransaction();

            $insertOrder = $pdo->prepare(
                'INSERT INTO procurement_orders (manufacturer, status, season_id, created_by_user_id)
                 VALUES (:manufacturer, \'draft\', :season_id, :user_id)
                 RETURNING id'
            );
            $insertLine = $pdo->prepare(
                'INSERT INTO procurement_order_lines (
                    order_id, material_guid, material_code, material_name_ar,
                    suggested_cartons, approved_cartons, available_cartons
                 ) VALUES (
                    :order_id, :material_guid, :code, :name,
                    :suggested, :approved, :available
                 )'
            );

            foreach ($byManufacturer as $manufacturer => $lines) {
                $insertOrder->execute([
                    'manufacturer' => $manufacturer,
                    'season_id' => $seasonId,
                    'user_id' => $userId,
                ]);
                $orderId = (string) $insertOrder->fetchColumn();
                $orderIds[] = $orderId;

                foreach ($lines as $line) {
                    try {
                        $insertLine->execute([
                            'order_id' => $orderId,
                            'material_guid' => $line['material_guid'],
                            'code' => $line['material_code'],
                            'name' => $line['material_name_ar'],
                            'suggested' => $line['suggested_cartons'],
                            'approved' => $line['suggested_cartons'],
                            'available' => $line['available_cartons'],
                        ]);
                    } catch (Throwable) {
                        // Skip duplicate open line (unique index).
                    }
                }
            }

            $pdo->commit();

            return [
                'ok' => true,
                'message' => sprintf('تم إنشاء %d مسودة طلب (واحدة لكل شركة).', count($orderIds)),
                'order_ids' => $orderIds,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    /** @return list<array<string, mixed>> */
    public static function listOrders(string $statusFilter = 'open'): array
    {
        if (!self::tableReady()) {
            return [];
        }

        $sql = 'SELECT o.*,
                       (SELECT COUNT(*) FROM procurement_order_lines l WHERE l.order_id = o.id AND l.line_status = \'open\') AS open_lines
                FROM procurement_orders o';
        if ($statusFilter === 'open') {
            $sql .= " WHERE o.status IN ('draft','sent','partially_received')";
        } elseif ($statusFilter !== 'all') {
            $sql .= ' WHERE o.status = :status';
        }
        $sql .= ' ORDER BY o.created_at DESC LIMIT 100';

        $stmt = Database::pdo()->prepare($sql);
        if ($statusFilter !== 'open' && $statusFilter !== 'all') {
            $stmt->execute(['status' => $statusFilter]);
        } else {
            $stmt->execute();
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function orderLines(string $orderId): array
    {
        if (!self::tableReady()) {
            return [];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT * FROM procurement_order_lines WHERE order_id = :id ORDER BY material_code, material_name_ar'
        );
        $stmt->execute(['id' => $orderId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function orderLinesEnriched(string $orderId): array
    {
        $lines = self::orderLines($orderId);
        if ($lines === []) {
            return [];
        }

        $guids = [];
        foreach ($lines as $line) {
            $guid = trim((string) ($line['material_guid'] ?? ''));
            if ($guid !== '') {
                $guids[] = $guid;
            }
        }

        $policies = self::policyRowsForGuids($guids);
        foreach ($lines as &$line) {
            $key = strtolower(trim((string) ($line['material_guid'] ?? '')));
            $policy = $policies[$key] ?? null;
            $lifecycle = is_array($policy)
                ? self::normalizeLifecycle((string) ($policy['lifecycle'] ?? ''))
                : '';
            $line['lifecycle'] = $lifecycle;
            $line['lifecycle_label'] = $lifecycle !== '' ? (self::LIFECYCLE_LABELS[$lifecycle] ?? $lifecycle) : '—';
            $line['min_cartons'] = is_array($policy) ? $policy['min_cartons'] : null;
            $line['max_cartons'] = is_array($policy) ? $policy['max_cartons'] : null;
            $line['notes_ar'] = is_array($policy) ? (string) ($policy['notes_ar'] ?? '') : '';
            $line['manufacturer'] = is_array($policy) ? (string) ($policy['manufacturer'] ?? '') : '';
            $min = $line['min_cartons'] !== null ? (float) $line['min_cartons'] : null;
            $available = (float) ($line['available_cartons'] ?? 0);
            $line['below_min'] = $min !== null && $available <= $min;
        }
        unset($line);

        return $lines;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function updateOrderStatus(string $orderId, string $status, ?string $userId = null): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'message' => 'جداول المشتريات غير جاهزة.'];
        }

        $allowed = ['draft', 'sent', 'partially_received', 'closed', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            return ['ok' => false, 'message' => 'حالة غير معروفة.'];
        }

        $stmt = Database::pdo()->prepare(
            "UPDATE procurement_orders SET
                status = :status,
                sent_at = CASE WHEN :status2 = 'sent' AND sent_at IS NULL THEN NOW() ELSE sent_at END,
                updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute(['status' => $status, 'status2' => $status, 'id' => $orderId]);
        if ($stmt->rowCount() === 0) {
            return ['ok' => false, 'message' => 'الطلب غير موجود.'];
        }

        if ($status === 'cancelled' || $status === 'closed') {
            Database::pdo()->prepare(
                "UPDATE procurement_order_lines SET line_status = 'closed', updated_at = NOW()
                 WHERE order_id = :id AND line_status = 'open'"
            )->execute(['id' => $orderId]);
        }

        return ['ok' => true, 'message' => 'تم تحديث حالة الطلب.'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function updateLineApprovedCartons(string $lineId, float $cartons): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'message' => 'جداول المشتريات غير جاهزة.'];
        }

        if ($cartons < 0) {
            return ['ok' => false, 'message' => 'الكمية غير صالحة.'];
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE procurement_order_lines SET approved_cartons = :qty, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['qty' => $cartons, 'id' => $lineId]);

        return $stmt->rowCount() > 0
            ? ['ok' => true, 'message' => 'تم تحديث كمية الطرد.']
            : ['ok' => false, 'message' => 'السطر غير موجود.'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function closeLine(string $lineId): array
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE procurement_order_lines SET line_status = 'closed', updated_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $lineId]);

        return $stmt->rowCount() > 0
            ? ['ok' => true, 'message' => 'تم إغلاق سطر المادة (يمكن اقتراح طلب جديد لاحقاً).']
            : ['ok' => false, 'message' => 'السطر غير موجود.'];
    }

    /** @return array<string, mixed>|null */
    public static function getOrder(string $orderId): ?array
    {
        if (!self::tableReady()) {
            return null;
        }

        $orderId = trim($orderId);
        if ($orderId === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM procurement_orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array{ok: bool, message: string, order_id?: string}
     */
    public static function createEmptyDraftOrder(string $manufacturer, ?string $userId): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'message' => 'جداول المشتريات غير جاهزة.'];
        }

        $manufacturer = trim($manufacturer);
        if ($manufacturer === '') {
            return ['ok' => false, 'message' => 'اختر الشركة المصنّعة.'];
        }

        $season = StoreSeasonService::current();
        $seasonId = is_array($season) ? ($season['id'] ?? null) : null;

        $stmt = Database::pdo()->prepare(
            'INSERT INTO procurement_orders (manufacturer, status, season_id, created_by_user_id)
             VALUES (:manufacturer, \'draft\', :season_id, :user_id)
             RETURNING id::text'
        );
        $stmt->execute([
            'manufacturer' => $manufacturer,
            'season_id' => $seasonId,
            'user_id' => $userId,
        ]);
        $orderId = (string) ($stmt->fetchColumn() ?: '');

        return $orderId !== ''
            ? ['ok' => true, 'message' => 'تم إنشاء مسودة طلب.', 'order_id' => $orderId]
            : ['ok' => false, 'message' => 'تعذر إنشاء الطلب.'];
    }

    /**
     * @return array{ok: bool, message: string, snapshot?: array<string, mixed>}|null
     */
    public static function materialLineSnapshot(string $materialGuid): ?array
    {
        $materialGuid = trim($materialGuid);
        if ($materialGuid === '') {
            return null;
        }

        $settings = self::settings();
        if ($settings['store_guids'] === []) {
            return null;
        }

        $materials = self::fetchMaterialsByGuids([$materialGuid], $settings['store_guids']);
        $material = $materials[strtolower($materialGuid)] ?? null;
        if (!is_array($material)) {
            return null;
        }

        $policy = self::policyRow($materialGuid);
        $reserved = StockReservationService::reservedPrimaryByMaterial([$materialGuid]);

        return self::buildLineSnapshot($materialGuid, $material, $policy, $reserved);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function addLineToOrder(string $orderId, string $materialGuid, ?float $approvedCartons = null): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'message' => 'جداول المشتريات غير جاهزة.'];
        }

        $order = self::getOrder($orderId);
        if ($order === null) {
            return ['ok' => false, 'message' => 'الطلب غير موجود.'];
        }

        if (!in_array((string) ($order['status'] ?? ''), ['draft', 'sent', 'partially_received'], true)) {
            return ['ok' => false, 'message' => 'لا يمكن إضافة بنود لطلب مغلق.'];
        }

        $snapshot = self::materialLineSnapshot($materialGuid);
        if ($snapshot === null) {
            return ['ok' => false, 'message' => 'تعذر قراءة المادة أو المخزون — راجع مستودعات الإعدادات.'];
        }

        $orderManufacturer = trim((string) ($order['manufacturer'] ?? ''));
        $lineManufacturer = trim((string) ($snapshot['manufacturer'] ?? ''));
        if ($orderManufacturer !== '' && $lineManufacturer !== '' && $orderManufacturer !== $lineManufacturer) {
            return ['ok' => false, 'message' => 'الصنف تابع لشركة أخرى — أنشئ طلباً لتلك الشركة.'];
        }

        $qty = $approvedCartons ?? (float) ($snapshot['suggested_cartons'] ?? 0);
        if ($qty <= 0) {
            $qty = 1.0;
        }

        try {
            Database::pdo()->prepare(
                'INSERT INTO procurement_order_lines (
                    order_id, material_guid, material_code, material_name_ar,
                    suggested_cartons, approved_cartons, available_cartons
                 ) VALUES (
                    :order_id, :material_guid, :code, :name,
                    :suggested, :approved, :available
                 )'
            )->execute([
                'order_id' => $orderId,
                'material_guid' => $materialGuid,
                'code' => $snapshot['material_code'],
                'name' => $snapshot['material_name_ar'],
                'suggested' => $snapshot['suggested_cartons'],
                'approved' => $qty,
                'available' => $snapshot['available_cartons'],
            ]);
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'الصنف موجود في طلب مفتوح آخر أو في هذا الطلب.'];
        }

        return ['ok' => true, 'message' => 'تمت إضافة الصنف للطلب.'];
    }

    /**
     * @param array{only_active?: bool, only_season_active?: bool, only_below_min?: bool, respect_current_season?: bool} $criteria
     * @return array{ok: bool, message: string, added?: int}
     */
    public static function fillOrderFromCriteria(string $orderId, array $criteria, ?string $userId = null): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'message' => 'جداول المشتريات غير جاهزة.'];
        }

        $order = self::getOrder($orderId);
        if ($order === null) {
            return ['ok' => false, 'message' => 'الطلب غير موجود.'];
        }

        $manufacturer = trim((string) ($order['manufacturer'] ?? ''));
        $onlyActive = (bool) ($criteria['only_active'] ?? $criteria['only_season_active'] ?? true);
        $onlyBelowMin = (bool) ($criteria['only_below_min'] ?? true);

        $added = 0;
        foreach (self::computeShortageLines() as $line) {
            if ($manufacturer !== '' && (string) ($line['manufacturer'] ?? '') !== $manufacturer) {
                continue;
            }

            $guid = (string) ($line['material_guid'] ?? '');
            if ($guid === '') {
                continue;
            }

            if ($onlyBelowMin && ((float) ($line['suggested_cartons'] ?? 0)) <= 0) {
                continue;
            }

            if ($onlyActive) {
                $policy = self::policyRow($guid);
                $lc = is_array($policy) ? self::normalizeLifecycle((string) ($policy['lifecycle'] ?? '')) : '';
                if ($lc !== self::LIFECYCLE_ACTIVE) {
                    continue;
                }
            }

            $result = self::addLineToOrder(
                $orderId,
                $guid,
                (float) ($line['suggested_cartons'] ?? 1)
            );
            if ($result['ok']) {
                $added++;
            }
        }

        return [
            'ok' => true,
            'message' => $added > 0
                ? sprintf('تمت إضافة %d صنفاً حسب المعايير.', $added)
                : 'لم يُضف أي صنف — راجع النواقص أو المعايير.',
            'added' => $added,
        ];
    }

    /**
     * @param array{q?: string, keyword?: string, page?: int, page_size?: int, group_guids?: list<string>} $params
     * @return array{items: list<array<string, mixed>>, total: int, page: int, page_size: int}
     */
    public static function searchMaterialsForPicker(string $manufacturer, array $params): array
    {
        $settings = self::settings();
        $storeGuids = $settings['store_guids'];
        if ($storeGuids === []) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'page_size' => 25];
        }

        $page = max(1, (int) ($params['page'] ?? 1));
        $pageSize = max(5, min(50, (int) ($params['page_size'] ?? 25)));
        $keyword = trim((string) ($params['q'] ?? $params['keyword'] ?? ''));

        $query = [
            'page' => $page,
            'pageSize' => $pageSize,
            'storeGuids' => implode(',', $storeGuids),
        ];
        if ($keyword !== '') {
            $query['keyword'] = $keyword;
        }
        $manufacturer = trim($manufacturer);
        if ($manufacturer !== '') {
            $query['manufacturers'] = $manufacturer;
        }

        $groupGuids = $params['group_guids'] ?? [];
        if (is_array($groupGuids) && $groupGuids !== []) {
            $query['groupGuids'] = implode(',', array_map('strval', $groupGuids));
        } elseif (!empty($params['current_season_only']) && $settings['limit_to_current_season']) {
            $season = StoreSeasonService::current();
            $groups = is_array($season) ? ($season['group_guids'] ?? []) : [];
            if ($groups !== []) {
                $query['groupGuids'] = implode(',', array_map('strval', $groups));
            }
        }

        $response = SessionGate::withoutLock(static function () use ($query): array {
            return ApiClient::get('/api/materials', $query, 90);
        });

        if (!($response['ok'] ?? false)) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'page_size' => $pageSize];
        }

        $data = $response['data'] ?? [];
        $apiItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        $total = (int) ($data['totalCount'] ?? 0);

        $guids = [];
        foreach ($apiItems as $apiItem) {
            if (!is_array($apiItem)) {
                continue;
            }
            $guid = self::materialGuidFromApi($apiItem);
            if ($guid !== '') {
                $guids[] = $guid;
            }
        }

        $byGuid = self::fetchMaterialsByGuids($guids, $storeGuids);
        StockReservationService::forgetWarehouseMemo();
        $reserved = StockReservationService::reservedPrimaryByMaterial($guids);
        $policies = self::policyRowsForGuids($guids);

        $items = [];
        foreach ($guids as $guid) {
            $key = strtolower($guid);
            $material = $byGuid[$key] ?? null;
            if (!is_array($material)) {
                continue;
            }
            $snapshot = self::buildLineSnapshot($guid, $material, $policies[$key] ?? null, $reserved);
            if ($snapshot !== null) {
                $items[] = $snapshot;
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /** @param array<string, mixed> $material @param array<string, mixed>|null $policy */
    private static function buildLineSnapshot(string $materialGuid, array $material, ?array $policy, array $reservedByGuid): ?array
    {
        $pcs = max(1.0, ShareCartService::packaging($material));
        $warehousePrimary = max(0.0, (float) ($material['warehouseQuantity'] ?? 0));
        $key = strtolower(trim($materialGuid));
        $reserved = (float) ($reservedByGuid[$materialGuid] ?? $reservedByGuid[$key] ?? 0.0);
        $availablePrimary = max(0.0, $warehousePrimary - $reserved);
        $availableCartons = round($availablePrimary / $pcs, 3);

        $suggested = 0.0;
        if (is_array($policy)
            && self::normalizeLifecycle((string) ($policy['lifecycle'] ?? '')) === self::LIFECYCLE_ACTIVE
            && $policy['min_cartons'] !== null
            && $policy['max_cartons'] !== null
            && $availableCartons <= (float) $policy['min_cartons']) {
            $needPrimary = max(0.0, ((float) $policy['max_cartons']) * $pcs - $availablePrimary);
            $suggested = (float) (int) ceil($needPrimary / $pcs);
        }

        $fields = self::cacheFieldsFromApi($material);

        return [
            'material_guid' => $materialGuid,
            'material_code' => $fields['code'],
            'material_name_ar' => $fields['name'],
            'manufacturer' => $fields['manufacturer'],
            'available_cartons' => $availableCartons,
            'suggested_cartons' => $suggested,
            'lifecycle' => is_array($policy)
                ? self::normalizeLifecycle((string) ($policy['lifecycle'] ?? ''))
                : '',
            'min_cartons' => is_array($policy) ? $policy['min_cartons'] : null,
            'max_cartons' => is_array($policy) ? $policy['max_cartons'] : null,
        ];
    }

    /** @param list<string> $guids @return array<string, array<string, mixed>> keyed by lower guid */
    private static function policyRowsForGuids(array $guids): array
    {
        $guids = array_values(array_unique(array_filter(array_map('trim', $guids))));
        if ($guids === [] || !self::tableReady()) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($guids as $index => $guid) {
            $key = 'g' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $guid;
        }

        $sql = 'SELECT * FROM procurement_materials WHERE material_guid::text IN (' . implode(', ', $placeholders) . ')';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $guid = strtolower(trim((string) ($row['material_guid'] ?? '')));
            if ($guid !== '') {
                $map[$guid] = $row;
            }
        }

        return $map;
    }

    /** @return array<string, mixed>|null */
    private static function policyRow(string $materialGuid): ?array
    {
        if (!self::tableReady()) {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM procurement_materials WHERE material_guid = :guid');
        $stmt->execute(['guid' => $materialGuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, array<string, mixed>> */
    private static function fetchMaterialsByGuids(array $guids, array $storeGuids): array
    {
        $guids = array_values(array_unique(array_filter(array_map('trim', $guids))));
        if ($guids === []) {
            return [];
        }

        $result = [];
        if ($storeGuids === []) {
            foreach (array_chunk($guids, 20) as $chunk) {
                $requests = [];
                foreach ($chunk as $guid) {
                    $requests[] = [
                        'key' => strtolower($guid),
                        'path' => '/api/materials/' . rawurlencode($guid),
                    ];
                }
                foreach (ApiClient::getMany($requests, 20) as $key => $response) {
                    if (($response['ok'] ?? false) && is_array($response['data'] ?? null)) {
                        $result[(string) $key] = $response['data'];
                    }
                }
            }

            return $result;
        }

        $storeCsv = implode(',', $storeGuids);
        foreach (array_chunk($guids, 40) as $chunk) {
            foreach (['true', 'false'] as $availability) {
                $pending = array_values(array_filter(
                    $chunk,
                    static fn (string $guid): bool => !isset($result[strtolower($guid)])
                ));
                if ($pending === []) {
                    break;
                }

                $response = SessionGate::withoutLock(static function () use ($pending, $storeCsv, $availability): array {
                    return ApiClient::get('/api/materials', [
                        'page' => 1,
                        'pageSize' => count($pending),
                        'materialGuids' => implode(',', $pending),
                        'storeGuids' => $storeCsv,
                        'isAvailable' => $availability,
                        'includeTotalCount' => 'false',
                    ], 90);
                });

                if (!($response['ok'] ?? false)) {
                    continue;
                }

                $data = $response['data'] ?? [];
                $items = is_array($data['items'] ?? null) ? $data['items'] : [];
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $guid = strtolower(self::materialGuidFromApi($item));
                    if ($guid !== '') {
                        $result[$guid] = $item;
                    }
                }
            }
        }

        foreach ($guids as $guid) {
            $key = strtolower($guid);
            if (isset($result[$key])) {
                continue;
            }
            $single = self::fetchSingleMaterialFromApi($guid, $storeGuids);
            if ($single !== null) {
                $result[$key] = $single;
            }
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private static function fetchSingleMaterialFromApi(string $materialGuid, array $storeGuids): ?array
    {
        $materialGuid = trim($materialGuid);
        if ($materialGuid === '') {
            return null;
        }

        $query = [];
        if ($storeGuids !== []) {
            $query['storeGuids'] = implode(',', $storeGuids);
        }

        $response = SessionGate::withoutLock(static function () use ($materialGuid, $query): array {
            return ApiClient::get('/api/materials/' . rawurlencode($materialGuid), $query, 60);
        });

        if (($response['ok'] ?? false) && is_array($response['data'] ?? null)) {
            return $response['data'];
        }

        return null;
    }

    /** @param array<string, mixed> $item */
    private static function materialGuidFromApi(array $item): string
    {
        foreach (['materialGuid', 'MaterialGuid', 'guid', 'Guid'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $item @return array{code: string, name: string, manufacturer: string, group_guid: ?string} */
    private static function cacheFieldsFromApi(array $item): array
    {
        $group = trim((string) ($item['groupGuid'] ?? $item['GroupGuid'] ?? ''));

        return [
            'code' => (string) ($item['materialCode'] ?? $item['MaterialCode'] ?? $item['code'] ?? ''),
            'name' => (string) ($item['name'] ?? $item['Name'] ?? ''),
            'manufacturer' => (string) ($item['manufacturer'] ?? $item['Manufacturer'] ?? ''),
            'group_guid' => $group !== '' ? $group : null,
        ];
    }

    private static function normalizeDecimalString(string $value): string
    {
        $value = str_replace(',', '.', trim($value));
        $map = [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ];

        return strtr($value, $map);
    }

    private static function parseNullableCartons(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = (float) self::normalizeDecimalString((string) $value);

        return $parsed >= 0 ? $parsed : null;
    }

    private static function refreshMaterialCacheFromApi(string $materialGuid): void
    {
        if (!self::tableReady()) {
            return;
        }

        $materialGuid = trim($materialGuid);
        if ($materialGuid === '') {
            return;
        }

        $settings = self::settings();
        $storeGuids = $settings['store_guids'];
        $material = null;
        if ($storeGuids !== []) {
            $material = self::fetchMaterialsByGuids([$materialGuid], $storeGuids)[strtolower($materialGuid)] ?? null;
        }
        if (!is_array($material)) {
            $response = SessionGate::withoutLock(static function () use ($materialGuid): array {
                return ApiClient::get('/api/materials/' . rawurlencode($materialGuid), [], 60);
            });
            if (($response['ok'] ?? false) && is_array($response['data'] ?? null)) {
                $material = $response['data'];
            }
        }

        if (!is_array($material)) {
            return;
        }

        $fields = self::cacheFieldsFromApi($material);
        Database::pdo()->prepare(
            'UPDATE procurement_materials SET
                material_code = :code,
                material_name_ar = :name,
                manufacturer = :manufacturer,
                group_guid = :group_guid,
                updated_at = NOW()
             WHERE material_guid = :guid'
        )->execute([
            'code' => $fields['code'],
            'name' => $fields['name'],
            'manufacturer' => $fields['manufacturer'],
            'group_guid' => $fields['group_guid'],
            'guid' => $materialGuid,
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $cache
     * @return array<string, mixed>|null
     */
    private static function fetchMaterialWithStores(string $materialGuid, array $storeGuids, array &$cache): ?array
    {
        $key = strtolower(trim($materialGuid));
        if ($key === '') {
            return null;
        }

        if (isset($cache[$key]) && is_array($cache[$key])) {
            return $cache[$key];
        }

        if ($storeGuids !== []) {
            $fetched = self::fetchMaterialsByGuids([$materialGuid], $storeGuids);
            if (isset($fetched[$key]) && is_array($fetched[$key])) {
                $cache[$key] = $fetched[$key];

                return $fetched[$key];
            }
        }

        $single = self::fetchSingleMaterialFromApi($materialGuid, $storeGuids);
        if ($single !== null) {
            $cache[$key] = $single;

            return $single;
        }

        return null;
    }

    /** @param array<string, mixed> $policy @param array<string, mixed> $material */
    private static function materialGroupGuidForSeason(array $policy, array $material, string $materialGuid): string
    {
        $groupGuid = strtolower(trim((string) ($policy['group_guid'] ?? '')));
        if ($groupGuid !== '') {
            return $groupGuid;
        }

        $fields = self::cacheFieldsFromApi($material);
        $fromApi = strtolower(trim((string) ($fields['group_guid'] ?? '')));
        if ($fromApi !== '' && self::tableReady()) {
            Database::pdo()->prepare(
                'UPDATE procurement_materials SET group_guid = :group_guid, updated_at = NOW() WHERE material_guid = :guid'
            )->execute(['group_guid' => $fields['group_guid'], 'guid' => $materialGuid]);
        }

        return $fromApi;
    }

    private static function startSyncRun(PDO $pdo): string
    {
        $stmt = $pdo->query(
            "INSERT INTO procurement_sync_runs (status) VALUES ('running') RETURNING id::text"
        );
        $id = (string) ($stmt->fetchColumn() ?: '');

        return $id;
    }

    private static function finishSyncRun(PDO $pdo, string $runId, string $status, int $seen, int $newCount, string $error): void
    {
        if ($runId === '') {
            return;
        }

        $pdo->prepare(
            'UPDATE procurement_sync_runs SET
                finished_at = NOW(),
                materials_seen = :seen,
                materials_new = :new_count,
                status = :status,
                error_ar = :error
             WHERE id = :id::uuid'
        )->execute([
            'seen' => $seen,
            'new_count' => $newCount,
            'status' => $status,
            'error' => mb_substr($error, 0, 2000),
            'id' => $runId,
        ]);
    }
}
