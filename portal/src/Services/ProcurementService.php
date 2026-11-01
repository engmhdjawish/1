<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use Portal\Support\SessionGate;
use PDO;
use Throwable;

final class ProcurementService
{
    public const LIFECYCLE_TRIAL = 'trial';
    public const LIFECYCLE_SEASON_ACTIVE = 'season_active';
    public const LIFECYCLE_DISCONTINUED = 'discontinued';

    public const LIFECYCLE_LABELS = [
        self::LIFECYCLE_TRIAL => 'تجربة',
        self::LIFECYCLE_SEASON_ACTIVE => 'موسمي مستمر',
        self::LIFECYCLE_DISCONTINUED => 'متوقف',
    ];

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
                        'lifecycle' => self::LIFECYCLE_TRIAL,
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
        if (!self::tableReady()) {
            return [];
        }

        $sql = 'SELECT * FROM procurement_materials';
        $params = [];
        if ($filter === 'needs_config') {
            $sql .= ' WHERE configured_at IS NULL AND lifecycle <> :discontinued';
            $params['discontinued'] = self::LIFECYCLE_DISCONTINUED;
        } elseif ($filter === 'season_active') {
            $sql .= ' WHERE lifecycle = :active';
            $params['active'] = self::LIFECYCLE_SEASON_ACTIVE;
        } elseif ($filter === 'trial') {
            $sql .= ' WHERE lifecycle = :trial';
            $params['trial'] = self::LIFECYCLE_TRIAL;
        } elseif ($filter === 'discontinued') {
            $sql .= ' WHERE lifecycle = :discontinued';
            $params['discontinued'] = self::LIFECYCLE_DISCONTINUED;
        }

        $sql .= ' ORDER BY first_seen_at DESC NULLS LAST LIMIT ' . max(1, min(500, $limit));

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

        $lifecycle = trim((string) ($input['lifecycle'] ?? self::LIFECYCLE_TRIAL));
        if (!isset(self::LIFECYCLE_LABELS[$lifecycle])) {
            return ['ok' => false, 'message' => 'نوع المادة غير معروف.'];
        }

        $minCartons = self::parseNullableCartons($input['min_cartons'] ?? null);
        $maxCartons = self::parseNullableCartons($input['max_cartons'] ?? null);
        if ($lifecycle === self::LIFECYCLE_SEASON_ACTIVE) {
            if ($minCartons === null || $maxCartons === null) {
                return ['ok' => false, 'message' => 'للموسمي المستمر أدخل حدّ الطلب (أدنى) وسقف المخزون (أقصى) بالطرد.'];
            }
            if ($maxCartons < $minCartons) {
                return ['ok' => false, 'message' => 'سقف المخزون يجب أن يكون ≥ حد تفعيل الطلب.'];
            }
        }

        $notes = trim((string) ($input['notes_ar'] ?? ''));

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
                    WHEN :lifecycle2 IN ('season_active', 'discontinued') THEN NOW()
                    ELSE configured_at
                END,
                configured_by_user_id = CASE
                    WHEN :lifecycle3 IN ('season_active', 'discontinued') THEN :user_id::uuid
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
             WHERE lifecycle = 'season_active'
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
            if ($seasonGroups !== []) {
                $groupGuid = strtolower(trim((string) ($policy['group_guid'] ?? '')));
                if ($groupGuid === '' || !isset($seasonGroups[$groupGuid])) {
                    continue;
                }
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
            if ($seasonGroups !== []) {
                $groupGuid = strtolower(trim((string) ($policy['group_guid'] ?? '')));
                if ($groupGuid === '' || !isset($seasonGroups[$groupGuid])) {
                    continue;
                }
            }

            $material = $materialsByGuid[$guidKey] ?? null;
            if (!is_array($material)) {
                continue;
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

        $sentAt = $status === 'sent' ? 'NOW()' : 'sent_at';
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

    /** @return array<string, array<string, mixed>> */
    private static function fetchMaterialsByGuids(array $guids, array $storeGuids): array
    {
        $result = [];
        $chunks = array_chunk($guids, 40);
        $storeCsv = implode(',', $storeGuids);

        foreach ($chunks as $chunk) {
            $response = SessionGate::withoutLock(static function () use ($chunk, $storeCsv): array {
                return ApiClient::get('/api/materials', [
                    'page' => 1,
                    'pageSize' => count($chunk),
                    'materialGuids' => implode(',', $chunk),
                    'storeGuids' => $storeCsv,
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

        return $result;
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

    private static function parseNullableCartons(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = (float) str_replace(',', '.', (string) $value);

        return $parsed >= 0 ? $parsed : null;
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
