<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Auth\Password;
use Portal\Database;
use PDO;

final class ShareLinkService
{
    private const FILTER_MATERIAL_TYPE = 'material_type';
    private const FILTER_AGE_CATEGORY = 'age_category';
    private const FILTER_MANUFACTURER = 'manufacturer';
    private const FILTER_SIZE_RANGE = 'size_range';
    private const FILTER_COUNTRY_ORIGIN = 'country_origin';
    private const FILTER_STORE_GUID = 'store_guid';
    private const FILTER_GROUP_GUID = 'group_guid';
    private const FILTER_IS_AVAILABLE = 'is_available';
    private const FILTER_HAS_IMAGE = 'has_image';
    private const FILTER_MIN_WAREHOUSE_QUANTITY = 'min_warehouse_quantity';
    private const FILTER_MAX_WAREHOUSE_QUANTITY = 'max_warehouse_quantity';
    private const FILTER_MIN_UNIT_SALE_PRICE_SYP = 'min_unit_sale_price_syp';
    private const FILTER_MAX_UNIT_SALE_PRICE_SYP = 'max_unit_sale_price_syp';
    private const FILTER_MIN_UNIT_SALE_PRICE_USD = 'min_unit_sale_price_usd';
    private const FILTER_MAX_UNIT_SALE_PRICE_USD = 'max_unit_sale_price_usd';
    private const FILTER_MIN_UNIT_PURCHASE_PRICE_USD = 'min_unit_purchase_price_usd';
    private const FILTER_MAX_UNIT_PURCHASE_PRICE_USD = 'max_unit_purchase_price_usd';

    private const OPTION_SHOW_IMAGES = 'option_show_images';
    private const OPTION_PRICE_MODE = 'option_price_mode';
    private const OPTION_ALLOW_CLIENT_FILTERS = 'option_allow_client_filters';
    private const OPTION_ALLOW_SORTING = 'option_allow_sorting';
    private const OPTION_INCLUDE_RESULT_FILTERS = 'option_include_result_filters';
    private const OPTION_DEFAULT_SORT = 'option_default_sort';
    private const OPTION_DEFAULT_GROUP_BY = 'option_default_group_by';
    private const OPTION_VISIBLE_CLIENT_FILTER = 'option_visible_client_filter';
    private const OPTION_CLIENT_SORT_FIELD = 'option_client_sort_field';

    /** @var list<string> */
    private const DEFAULT_VISIBLE_CLIENT_FILTERS = [
        'search',
    ];

    /** @var list<string> */
    private const ALLOWED_VISIBLE_CLIENT_FILTERS = [
        'search',
        'availability',
        'warehouseRange',
        'priceSaleSyp',
        'priceSaleUsd',
        'pricePurchaseUsd',
        'materialTypes',
        'ageCategories',
        'manufacturers',
        'sizeRanges',
        'countryOfOrigins',
        'stores',
        'groups',
        'sort',
        'groupBy',
    ];

    /** @var list<string> */
    private const ALLOWED_CLIENT_SORT_FIELDS = [
        'number',
        'materialType',
        'ageCategory',
        'manufacturer',
        'sizeRange',
        'countryOfOrigin',
        'unitSalePriceSyp',
        'unitSalePriceUsd',
    ];

    /** @param array{active?: string, q?: string, limit?: int} $filters */
    public static function list(array $filters = []): array
    {
        $isActive = trim((string) ($filters['active'] ?? ''));
        $search = trim((string) ($filters['q'] ?? ''));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 100)));

        $sql = 'SELECT
                    sl.id,
                    sl.public_token,
                    sl.name_ar,
                    sl.keyword,
                    sl.min_quantity,
                    sl.expires_at,
                    CASE WHEN sl.is_active THEN 1 ELSE 0 END AS is_active,
                    CASE WHEN sl.require_password THEN 1 ELSE 0 END AS require_password,
                    sl.created_at,
                    ap.name_ar AS access_policy_name_ar,
                    COALESCE(filters.filters_count, 0)::int AS filters_count
                FROM share_links sl
                INNER JOIN access_policies ap ON ap.id = sl.access_policy_id
                LEFT JOIN (
                    SELECT link_id, COUNT(*)::int AS filters_count
                    FROM share_link_filters
                    WHERE filter_type NOT LIKE \'option_%\'
                    GROUP BY link_id
                ) filters ON filters.link_id = sl.id
                WHERE 1 = 1';
        $params = [];

        if ($isActive === '1' || $isActive === '0') {
            $sql .= ' AND sl.is_active = :is_active';
            $params['is_active'] = $isActive === '1';
        }

        if ($search !== '') {
            $sql .= ' AND (sl.name_ar ILIKE :search OR sl.public_token ILIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY sl.created_at DESC LIMIT :limit';

        $stmt = Database::pdo()->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getById(string $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                sl.id,
                sl.public_token,
                sl.name_ar,
                sl.access_policy_id::text AS access_policy_id,
                CASE WHEN sl.require_password THEN 1 ELSE 0 END AS require_password,
                sl.access_username,
                sl.password_hash,
                sl.keyword,
                sl.min_quantity,
                sl.expires_at,
                CASE WHEN sl.is_active THEN 1 ELSE 0 END AS is_active,
                sl.created_at,
                sl.updated_at
             FROM share_links sl
             WHERE sl.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return self::hydrateLink($row);
    }

    /** @return array<string, mixed>|null */
    public static function getByPublicToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT
                sl.id::text AS id,
                sl.public_token,
                sl.name_ar,
                sl.access_policy_id::text AS access_policy_id,
                CASE WHEN sl.require_password THEN 1 ELSE 0 END AS require_password,
                sl.access_username,
                sl.password_hash,
                sl.keyword,
                sl.min_quantity,
                sl.expires_at,
                CASE WHEN sl.is_active THEN 1 ELSE 0 END AS is_active,
                sl.created_at,
                sl.updated_at,
                ap.name_ar AS access_policy_name_ar,
                CASE WHEN ap.show_price THEN 1 ELSE 0 END AS show_price,
                CASE WHEN ap.show_quantity THEN 1 ELSE 0 END AS show_quantity,
                CASE WHEN ap.allow_cart THEN 1 ELSE 0 END AS allow_cart,
                CASE WHEN ap.allow_order THEN 1 ELSE 0 END AS allow_order
             FROM share_links sl
             INNER JOIN access_policies ap ON ap.id = sl.access_policy_id
             WHERE sl.public_token = :token
             LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        if ((int) ($row['is_active'] ?? 0) !== 1) {
            return null;
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) !== false && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        return self::hydrateLink($row);
    }

    public static function verifyProtectedAccess(string $token, string $username, string $plainPassword): bool
    {
        $token = trim($token);
        $username = trim($username);
        $plainPassword = trim($plainPassword);
        if ($token === '' || $username === '' || $plainPassword === '') {
            return false;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT
                access_username,
                password_hash,
                CASE WHEN require_password THEN 1 ELSE 0 END AS require_password,
                CASE WHEN is_active THEN 1 ELSE 0 END AS is_active,
                expires_at
             FROM share_links
             WHERE public_token = :token
             LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }

        if ((int) ($row['is_active'] ?? 0) !== 1) {
            return false;
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) !== false && strtotime((string) $row['expires_at']) < time()) {
            return false;
        }
        if ((int) ($row['require_password'] ?? 0) !== 1) {
            return true;
        }

        $storedUser = trim((string) ($row['access_username'] ?? ''));
        $storedHash = (string) ($row['password_hash'] ?? '');
        if ($storedUser === '' || $storedHash === '') {
            return false;
        }

        return hash_equals(strtolower($storedUser), strtolower($username))
            && Password::verify($plainPassword, $storedHash);
    }

    /** @return array{total: int, active: int, expired: int, protected: int} */
    public static function stats(): array
    {
        $row = Database::pdo()->query(
            'SELECT
                COUNT(*)::int AS total,
                COUNT(*) FILTER (WHERE is_active = TRUE)::int AS active,
                COUNT(*) FILTER (WHERE expires_at IS NOT NULL AND expires_at < NOW())::int AS expired,
                COUNT(*) FILTER (WHERE require_password = TRUE)::int AS protected
             FROM share_links'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'expired' => (int) ($row['expired'] ?? 0),
            'protected' => (int) ($row['protected'] ?? 0),
        ];
    }

    /** @return array{
     *   show_images: bool,
     *   price_mode: string,
     *   allow_client_filters: bool,
     *   allow_sorting: bool,
     *   include_result_filters: bool,
     *   visible_client_filters: list<string>,
     *   client_sort_fields: list<string>,
     *   default_sort: string,
     *   default_group_by: string
     * } */
    public static function defaultLinkOptions(): array
    {
        return [
            'show_images' => true,
            'price_mode' => 'both',
            'allow_client_filters' => true,
            'allow_sorting' => true,
            'include_result_filters' => true,
            'visible_client_filters' => self::DEFAULT_VISIBLE_CLIENT_FILTERS,
            'client_sort_fields' => ['number', 'materialType', 'manufacturer'],
            'default_sort' => 'number:asc',
            'default_group_by' => 'none',
        ];
    }

    /** @return list<array{id: string, code: string, name_ar: string}> */
    public static function listAccessPolicies(): array
    {
        return Database::pdo()->query(
            'SELECT id::text AS id, code, name_ar
             FROM access_policies
             WHERE is_active = TRUE
             ORDER BY name_ar'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{ok: bool, message: string, id?: string} */
    public static function save(
        ?string $id,
        string $name,
        string $accessPolicyId,
        bool $requirePassword,
        ?string $accessUsername,
        ?string $plainPassword,
        ?string $keyword,
        float $minQuantity,
        ?string $expiresAt,
        bool $isActive,
        ?string $userId,
        array $forcedMaterialTypes = [],
        array $forcedAgeCategories = [],
        array $forcedManufacturers = [],
        array $forcedSizeRanges = [],
        array $forcedCountryOrigins = [],
        array $forcedStoreGuids = [],
        array $forcedGroupGuids = [],
        ?bool $forcedIsAvailable = null,
        ?bool $forcedHasImage = null,
        ?float $forcedMinWarehouseQuantity = null,
        ?float $forcedMaxWarehouseQuantity = null,
        ?float $forcedMinUnitSalePriceSyp = null,
        ?float $forcedMaxUnitSalePriceSyp = null,
        ?float $forcedMinUnitSalePriceUsd = null,
        ?float $forcedMaxUnitSalePriceUsd = null,
        ?float $forcedMinUnitPurchasePriceUsd = null,
        ?float $forcedMaxUnitPurchasePriceUsd = null,
        bool $showImages = true,
        string $priceMode = 'both',
        bool $allowClientFilters = true,
        bool $allowSorting = true,
        bool $includeResultFilters = true,
        array $visibleClientFilters = [],
        array $clientSortFields = [],
        ?string $defaultSort = null,
        ?string $defaultGroupBy = null
    ): array {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'message' => 'اسم الرابط مطلوب.'];
        }

        $accessPolicyId = trim($accessPolicyId);
        if ($accessPolicyId === '') {
            return ['ok' => false, 'message' => 'سياسة الوصول مطلوبة.'];
        }

        $accessUsername = trim((string) $accessUsername);
        $keyword = trim((string) $keyword);
        $plainPassword = trim((string) $plainPassword);
        $expiresAt = trim((string) $expiresAt);
        $expiresAt = $expiresAt !== '' ? $expiresAt : null;
        $userId = $userId !== null ? trim($userId) : null;
        $userId = $userId !== '' ? $userId : null;
        $priceMode = in_array($priceMode, ['both', 'syp', 'usd', 'none'], true) ? $priceMode : 'both';
        $defaultSort = trim((string) $defaultSort);
        $defaultSort = $defaultSort !== '' ? $defaultSort : null;
        $defaultGroupBy = trim((string) $defaultGroupBy);
        $defaultGroupBy = in_array($defaultGroupBy, ['ageCategory', 'sizeRange', 'materialType', 'manufacturer', 'countryOfOrigin', 'group'], true)
            ? $defaultGroupBy
            : null;
        $clientSortFields = self::normalizeClientSortFields($clientSortFields);
        $includeResultFilters = $allowClientFilters && $includeResultFilters;

        if ($requirePassword && $accessUsername === '') {
            return ['ok' => false, 'message' => 'اسم مستخدم الوصول مطلوب عند تفعيل كلمة المرور.'];
        }

        $forcedMaterialTypes = self::normalizeFilterValues($forcedMaterialTypes);
        $forcedAgeCategories = self::normalizeFilterValues($forcedAgeCategories);
        $forcedManufacturers = self::normalizeFilterValues($forcedManufacturers);
        $forcedSizeRanges = self::normalizeFilterValues($forcedSizeRanges);
        $forcedCountryOrigins = self::normalizeFilterValues($forcedCountryOrigins);
        $forcedStoreGuids = self::normalizeGuidFilterValues($forcedStoreGuids);
        $forcedGroupGuids = self::normalizeGuidFilterValues($forcedGroupGuids);

        $forcedMinWarehouseQuantity = self::normalizeNullableNumber($forcedMinWarehouseQuantity);
        $forcedMaxWarehouseQuantity = self::normalizeNullableNumber($forcedMaxWarehouseQuantity);
        $forcedMinUnitSalePriceSyp = self::normalizeNullableNumber($forcedMinUnitSalePriceSyp);
        $forcedMaxUnitSalePriceSyp = self::normalizeNullableNumber($forcedMaxUnitSalePriceSyp);
        $forcedMinUnitSalePriceUsd = self::normalizeNullableNumber($forcedMinUnitSalePriceUsd);
        $forcedMaxUnitSalePriceUsd = self::normalizeNullableNumber($forcedMaxUnitSalePriceUsd);
        $forcedMinUnitPurchasePriceUsd = self::normalizeNullableNumber($forcedMinUnitPurchasePriceUsd);
        $forcedMaxUnitPurchasePriceUsd = self::normalizeNullableNumber($forcedMaxUnitPurchasePriceUsd);
        $visibleClientFilters = self::normalizeVisibleClientFilters($visibleClientFilters);

        if (!self::isValidRange($forcedMinWarehouseQuantity, $forcedMaxWarehouseQuantity)) {
            return ['ok' => false, 'message' => 'مدى الكمية غير صحيح (الحد الأدنى أكبر من الحد الأعلى).'];
        }
        if (!self::isValidRange($forcedMinUnitSalePriceSyp, $forcedMaxUnitSalePriceSyp)) {
            return ['ok' => false, 'message' => 'مدى سعر البيع (ل.س) غير صحيح.'];
        }
        if (!self::isValidRange($forcedMinUnitSalePriceUsd, $forcedMaxUnitSalePriceUsd)) {
            return ['ok' => false, 'message' => 'مدى سعر البيع (دولار) غير صحيح.'];
        }
        if (!self::isValidRange($forcedMinUnitPurchasePriceUsd, $forcedMaxUnitPurchasePriceUsd)) {
            return ['ok' => false, 'message' => 'مدى سعر الشراء (دولار) غير صحيح.'];
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($id === null || trim($id) === '') {
            if ($requirePassword && $plainPassword === '') {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'كلمة المرور مطلوبة عند إنشاء رابط محمي.'];
            }

            $stmt = $pdo->prepare(
                'INSERT INTO share_links (
                    public_token,
                    name_ar,
                    access_policy_id,
                    require_password,
                    access_username,
                    password_hash,
                    keyword,
                    min_quantity,
                    expires_at,
                    is_active,
                    created_by_web_user_id
                 ) VALUES (
                    :token,
                    :name,
                    :policy_id,
                    CASE WHEN :require_password = 1 THEN TRUE ELSE FALSE END,
                    :access_username,
                    :password_hash,
                    :keyword,
                    :min_quantity,
                    :expires_at,
                    CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                    :created_by
                 )
                 RETURNING id::text'
            );
            $stmt->execute([
                'token' => self::generateToken(),
                'name' => $name,
                'policy_id' => $accessPolicyId,
                'require_password' => $requirePassword ? 1 : 0,
                'access_username' => $accessUsername !== '' ? $accessUsername : null,
                'password_hash' => $requirePassword ? Password::hash($plainPassword) : null,
                'keyword' => $keyword !== '' ? $keyword : null,
                'min_quantity' => max(0, $minQuantity),
                'expires_at' => $expiresAt,
                'is_active' => $isActive ? 1 : 0,
                'created_by' => $userId,
            ]);
            $createdId = (string) $stmt->fetchColumn();

            self::saveLinkFilters(
                $createdId,
                $forcedMaterialTypes,
                $forcedAgeCategories,
                $forcedManufacturers,
                $forcedSizeRanges,
                $forcedCountryOrigins,
                $forcedStoreGuids,
                $forcedGroupGuids,
                $forcedIsAvailable,
                $forcedHasImage,
                $forcedMinWarehouseQuantity,
                $forcedMaxWarehouseQuantity,
                $forcedMinUnitSalePriceSyp,
                $forcedMaxUnitSalePriceSyp,
                $forcedMinUnitSalePriceUsd,
                $forcedMaxUnitSalePriceUsd,
                $forcedMinUnitPurchasePriceUsd,
                $forcedMaxUnitPurchasePriceUsd,
                $showImages,
                $priceMode,
                $allowClientFilters,
                $allowSorting,
                $includeResultFilters,
                $visibleClientFilters,
                $clientSortFields,
                $defaultSort,
                $defaultGroupBy
            );
            $pdo->commit();

            return [
                'ok' => true,
                'message' => 'تم إنشاء رابط المشاركة.',
                'id' => $createdId,
            ];
            }

            $existing = self::getById($id);
            if ($existing === null) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'الرابط المطلوب غير موجود.'];
            }

            $nextPasswordHash = $existing['require_password'] ? (string) ($existing['password_hash'] ?? '') : null;
            if ($requirePassword) {
                if ($plainPassword !== '') {
                    $nextPasswordHash = Password::hash($plainPassword);
                } elseif ($nextPasswordHash === null || $nextPasswordHash === '') {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => 'أدخل كلمة مرور جديدة للرابط المحمي.'];
                }
            } else {
                $nextPasswordHash = null;
                $accessUsername = '';
            }

            $update = $pdo->prepare(
                'UPDATE share_links SET
                    name_ar = :name,
                    access_policy_id = :policy_id,
                    require_password = CASE WHEN :require_password = 1 THEN TRUE ELSE FALSE END,
                    access_username = :access_username,
                    password_hash = :password_hash,
                    keyword = :keyword,
                    min_quantity = :min_quantity,
                    expires_at = :expires_at,
                    is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END,
                    updated_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([
                'id' => $id,
                'name' => $name,
                'policy_id' => $accessPolicyId,
                'require_password' => $requirePassword ? 1 : 0,
                'access_username' => $requirePassword ? ($accessUsername !== '' ? $accessUsername : null) : null,
                'password_hash' => $nextPasswordHash,
                'keyword' => $keyword !== '' ? $keyword : null,
                'min_quantity' => max(0, $minQuantity),
                'expires_at' => $expiresAt,
                'is_active' => $isActive ? 1 : 0,
            ]);
            self::saveLinkFilters(
                $id,
                $forcedMaterialTypes,
                $forcedAgeCategories,
                $forcedManufacturers,
                $forcedSizeRanges,
                $forcedCountryOrigins,
                $forcedStoreGuids,
                $forcedGroupGuids,
                $forcedIsAvailable,
                $forcedHasImage,
                $forcedMinWarehouseQuantity,
                $forcedMaxWarehouseQuantity,
                $forcedMinUnitSalePriceSyp,
                $forcedMaxUnitSalePriceSyp,
                $forcedMinUnitSalePriceUsd,
                $forcedMaxUnitSalePriceUsd,
                $forcedMinUnitPurchasePriceUsd,
                $forcedMaxUnitPurchasePriceUsd,
                $showImages,
                $priceMode,
                $allowClientFilters,
                $allowSorting,
                $includeResultFilters,
                $visibleClientFilters,
                $clientSortFields,
                $defaultSort,
                $defaultGroupBy
            );
            $pdo->commit();

            return [
                'ok' => true,
                'message' => 'تم تحديث رابط المشاركة.',
                'id' => $id,
            ];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'ok' => false,
                'message' => 'تعذر حفظ رابط المشاركة: ' . $exception->getMessage(),
            ];
        }
    }

    public static function setActive(string $id, bool $isActive): bool
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE share_links
             SET is_active = CASE WHEN :is_active = 1 THEN TRUE ELSE FALSE END, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'is_active' => $isActive ? 1 : 0,
        ]);

        return $stmt->rowCount() > 0;
    }

    /** @return array{ok: bool, message: string} */
    public static function delete(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return ['ok' => false, 'message' => 'المعرّف غير صالح.'];
        }

        try {
            $stmt = Database::pdo()->prepare('DELETE FROM share_links WHERE id = :id');
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() === 0) {
                return ['ok' => false, 'message' => 'الرابط غير موجود أو تم حذفه مسبقًا.'];
            }

            return ['ok' => true, 'message' => 'تم حذف رابط المشاركة.'];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'message' => 'تعذر حذف الرابط. قد يكون مرتبطًا بطلبات سابقة. استخدم الإيقاف بدل الحذف عند الحاجة.',
            ];
        }
    }

    public static function countActive(): int
    {
        $value = Database::pdo()->query(
            'SELECT COUNT(*)::int FROM share_links WHERE is_active = TRUE'
        )->fetchColumn();

        return (int) $value;
    }

    private static function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @return array<string, mixed> */
    private static function hydrateLink(array $row): array
    {
        $filters = self::loadLinkFilters((string) ($row['id'] ?? ''));
        $row['forced_material_types'] = $filters['forced_material_types'];
        $row['forced_age_categories'] = $filters['forced_age_categories'];
        $row['forced_manufacturers'] = $filters['forced_manufacturers'];
        $row['forced_size_ranges'] = $filters['forced_size_ranges'];
        $row['forced_country_origins'] = $filters['forced_country_origins'];
        $row['forced_store_guids'] = $filters['forced_store_guids'];
        $row['forced_group_guids'] = $filters['forced_group_guids'];
        $row['constraints'] = $filters['constraints'];
        $row['options'] = $filters['options'];

        return $row;
    }

    /**
     * @return array{
     *   forced_material_types: list<string>,
     *   forced_age_categories: list<string>,
     *   forced_manufacturers: list<string>,
     *   forced_size_ranges: list<string>,
     *   forced_country_origins: list<string>,
     *   forced_store_guids: list<string>,
     *   forced_group_guids: list<string>,
     *   constraints: array{
     *      is_available: bool|null,
     *      min_warehouse_quantity: float|null,
     *      max_warehouse_quantity: float|null,
     *      min_unit_sale_price_syp: float|null,
     *      max_unit_sale_price_syp: float|null,
     *      min_unit_sale_price_usd: float|null,
     *      max_unit_sale_price_usd: float|null,
     *      min_unit_purchase_price_usd: float|null,
     *      max_unit_purchase_price_usd: float|null
     *   },
     *   options: array{
     *      show_images: bool,
     *      price_mode: string,
     *      allow_client_filters: bool,
     *      allow_sorting: bool,
     *      include_result_filters: bool,
     *      visible_client_filters: list<string>,
     *      client_sort_fields: list<string>,
     *      default_sort: string,
     *      default_group_by: string
     *   }
     * }
     */
    private static function loadLinkFilters(string $linkId): array
    {
        $defaults = [
            'show_images' => true,
            'price_mode' => 'both',
            'allow_client_filters' => true,
            'allow_sorting' => true,
            'include_result_filters' => true,
            'visible_client_filters' => self::DEFAULT_VISIBLE_CLIENT_FILTERS,
            'client_sort_fields' => ['number', 'materialType', 'manufacturer'],
            'default_sort' => 'number:asc',
            'default_group_by' => 'none',
        ];
        $constraintDefaults = [
            'is_available' => null,
            'has_image' => null,
            'min_warehouse_quantity' => null,
            'max_warehouse_quantity' => null,
            'min_unit_sale_price_syp' => null,
            'max_unit_sale_price_syp' => null,
            'min_unit_sale_price_usd' => null,
            'max_unit_sale_price_usd' => null,
            'min_unit_purchase_price_usd' => null,
            'max_unit_purchase_price_usd' => null,
        ];
        if ($linkId === '') {
            return [
                'forced_material_types' => [],
                'forced_age_categories' => [],
                'forced_manufacturers' => [],
                'forced_size_ranges' => [],
                'forced_country_origins' => [],
                'forced_store_guids' => [],
                'forced_group_guids' => [],
                'constraints' => $constraintDefaults,
                'options' => $defaults,
            ];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT filter_type, value_ar
             FROM share_link_filters
             WHERE link_id = :link_id'
        );
        $stmt->execute(['link_id' => $linkId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [
            'forced_material_types' => [],
            'forced_age_categories' => [],
            'forced_manufacturers' => [],
            'forced_size_ranges' => [],
            'forced_country_origins' => [],
            'forced_store_guids' => [],
            'forced_group_guids' => [],
            'constraints' => $constraintDefaults,
            'options' => $defaults,
        ];
        $hasVisibleClientFilters = false;
        $hasClientSortFields = false;

        foreach ($rows as $row) {
            $type = trim((string) ($row['filter_type'] ?? ''));
            $value = trim((string) ($row['value_ar'] ?? ''));
            if ($type === '' || $value === '') {
                continue;
            }

            switch ($type) {
                case self::FILTER_MATERIAL_TYPE:
                    $result['forced_material_types'][] = $value;
                    break;
                case self::FILTER_AGE_CATEGORY:
                case 'target_category':
                    $result['forced_age_categories'][] = $value;
                    break;
                case self::FILTER_MANUFACTURER:
                    $result['forced_manufacturers'][] = $value;
                    break;
                case self::FILTER_SIZE_RANGE:
                    $result['forced_size_ranges'][] = $value;
                    break;
                case self::FILTER_COUNTRY_ORIGIN:
                    $result['forced_country_origins'][] = $value;
                    break;
                case self::FILTER_STORE_GUID:
                    $result['forced_store_guids'][] = $value;
                    break;
                case self::FILTER_GROUP_GUID:
                    $result['forced_group_guids'][] = $value;
                    break;
                case self::FILTER_IS_AVAILABLE:
                    $result['constraints']['is_available'] = self::toNullableBool($value);
                    break;
                case self::FILTER_HAS_IMAGE:
                    $result['constraints']['has_image'] = self::toNullableBool($value);
                    break;
                case self::FILTER_MIN_WAREHOUSE_QUANTITY:
                    $result['constraints']['min_warehouse_quantity'] = self::toNullableFloat($value);
                    break;
                case self::FILTER_MAX_WAREHOUSE_QUANTITY:
                    $result['constraints']['max_warehouse_quantity'] = self::toNullableFloat($value);
                    break;
                case self::FILTER_MIN_UNIT_SALE_PRICE_SYP:
                    $result['constraints']['min_unit_sale_price_syp'] = self::toNullableFloat($value);
                    break;
                case self::FILTER_MAX_UNIT_SALE_PRICE_SYP:
                    $result['constraints']['max_unit_sale_price_syp'] = self::toNullableFloat($value);
                    break;
                case self::FILTER_MIN_UNIT_SALE_PRICE_USD:
                    $result['constraints']['min_unit_sale_price_usd'] = self::toNullableFloat($value);
                    break;
                case self::FILTER_MAX_UNIT_SALE_PRICE_USD:
                    $result['constraints']['max_unit_sale_price_usd'] = self::toNullableFloat($value);
                    break;
                case self::FILTER_MIN_UNIT_PURCHASE_PRICE_USD:
                    $result['constraints']['min_unit_purchase_price_usd'] = self::toNullableFloat($value);
                    break;
                case self::FILTER_MAX_UNIT_PURCHASE_PRICE_USD:
                    $result['constraints']['max_unit_purchase_price_usd'] = self::toNullableFloat($value);
                    break;
                case self::OPTION_SHOW_IMAGES:
                    $result['options']['show_images'] = self::toBool($value, true);
                    break;
                case self::OPTION_PRICE_MODE:
                    $result['options']['price_mode'] = in_array($value, ['both', 'syp', 'usd', 'none'], true) ? $value : 'both';
                    break;
                case self::OPTION_ALLOW_CLIENT_FILTERS:
                    $result['options']['allow_client_filters'] = self::toBool($value, true);
                    break;
                case self::OPTION_ALLOW_SORTING:
                    $result['options']['allow_sorting'] = self::toBool($value, true);
                    break;
                case self::OPTION_INCLUDE_RESULT_FILTERS:
                    $result['options']['include_result_filters'] = self::toBool($value, true);
                    break;
                case self::OPTION_VISIBLE_CLIENT_FILTER:
                    if (!$hasVisibleClientFilters) {
                        $result['options']['visible_client_filters'] = [];
                        $hasVisibleClientFilters = true;
                    }
                    if (in_array($value, self::ALLOWED_VISIBLE_CLIENT_FILTERS, true)) {
                        $result['options']['visible_client_filters'][] = $value;
                    }
                    break;
                case self::OPTION_DEFAULT_SORT:
                    $result['options']['default_sort'] = $value;
                    break;
                case self::OPTION_CLIENT_SORT_FIELD:
                    if (!$hasClientSortFields) {
                        $result['options']['client_sort_fields'] = [];
                        $hasClientSortFields = true;
                    }
                    if (in_array($value, self::ALLOWED_CLIENT_SORT_FIELDS, true)) {
                        $result['options']['client_sort_fields'][] = $value;
                    }
                    break;
                case self::OPTION_DEFAULT_GROUP_BY:
                    $result['options']['default_group_by'] = in_array($value, ['none', 'ageCategory', 'sizeRange', 'materialType', 'manufacturer', 'countryOfOrigin', 'group'], true)
                        ? $value
                        : 'none';
                    break;
            }
        }

        $result['forced_material_types'] = self::normalizeFilterValues($result['forced_material_types']);
        $result['forced_age_categories'] = self::normalizeFilterValues($result['forced_age_categories']);
        $result['forced_manufacturers'] = self::normalizeFilterValues($result['forced_manufacturers']);
        $result['forced_size_ranges'] = self::normalizeFilterValues($result['forced_size_ranges']);
        $result['forced_country_origins'] = self::normalizeFilterValues($result['forced_country_origins']);
        $result['forced_store_guids'] = self::normalizeGuidFilterValues($result['forced_store_guids']);
        $result['forced_group_guids'] = self::normalizeGuidFilterValues($result['forced_group_guids']);
        $result['options']['visible_client_filters'] = self::normalizeVisibleClientFilters($result['options']['visible_client_filters'] ?? []);
        $result['options']['client_sort_fields'] = self::normalizeClientSortFields($result['options']['client_sort_fields'] ?? []);
        if (!$hasClientSortFields) {
            $result['options']['client_sort_fields'] = self::clientSortFieldsFromDefaultSort((string) ($result['options']['default_sort'] ?? 'number:asc'));
        }

        return $result;
    }

    private static function saveLinkFilters(
        string $linkId,
        array $forcedMaterialTypes,
        array $forcedAgeCategories,
        array $forcedManufacturers,
        array $forcedSizeRanges,
        array $forcedCountryOrigins,
        array $forcedStoreGuids,
        array $forcedGroupGuids,
        ?bool $forcedIsAvailable,
        ?bool $forcedHasImage,
        ?float $forcedMinWarehouseQuantity,
        ?float $forcedMaxWarehouseQuantity,
        ?float $forcedMinUnitSalePriceSyp,
        ?float $forcedMaxUnitSalePriceSyp,
        ?float $forcedMinUnitSalePriceUsd,
        ?float $forcedMaxUnitSalePriceUsd,
        ?float $forcedMinUnitPurchasePriceUsd,
        ?float $forcedMaxUnitPurchasePriceUsd,
        bool $showImages,
        string $priceMode,
        bool $allowClientFilters,
        bool $allowSorting,
        bool $includeResultFilters,
        array $visibleClientFilters,
        array $clientSortFields,
        ?string $defaultSort,
        ?string $defaultGroupBy
    ): void {
        $delete = Database::pdo()->prepare(
            'DELETE FROM share_link_filters WHERE link_id = :link_id'
        );
        $delete->execute(['link_id' => $linkId]);

        $insert = Database::pdo()->prepare(
            'INSERT INTO share_link_filters (link_id, filter_type, value_ar)
             VALUES (:link_id, :filter_type, :value_ar)
             ON CONFLICT DO NOTHING'
        );
        $insertValues = static function (string $type, array $values) use ($insert, $linkId): void {
            foreach ($values as $value) {
                $insert->execute([
                    'link_id' => $linkId,
                    'filter_type' => $type,
                    'value_ar' => $value,
                ]);
            }
        };

        $insertValues(self::FILTER_MATERIAL_TYPE, $forcedMaterialTypes);
        $insertValues(self::FILTER_AGE_CATEGORY, $forcedAgeCategories);
        $insertValues(self::FILTER_MANUFACTURER, $forcedManufacturers);
        $insertValues(self::FILTER_SIZE_RANGE, $forcedSizeRanges);
        $insertValues(self::FILTER_COUNTRY_ORIGIN, $forcedCountryOrigins);
        $insertValues(self::FILTER_STORE_GUID, $forcedStoreGuids);
        $insertValues(self::FILTER_GROUP_GUID, $forcedGroupGuids);

        if ($forcedIsAvailable !== null) {
            $insert->execute([
                'link_id' => $linkId,
                'filter_type' => self::FILTER_IS_AVAILABLE,
                'value_ar' => $forcedIsAvailable ? '1' : '0',
            ]);
        }
        if ($forcedHasImage !== null) {
            $insert->execute([
                'link_id' => $linkId,
                'filter_type' => self::FILTER_HAS_IMAGE,
                'value_ar' => $forcedHasImage ? '1' : '0',
            ]);
        }

        $insertNumber = static function (string $type, ?float $value) use ($insert, $linkId): void {
            if ($value === null) {
                return;
            }

            $insert->execute([
                'link_id' => $linkId,
                'filter_type' => $type,
                'value_ar' => rtrim(rtrim(sprintf('%.8F', $value), '0'), '.'),
            ]);
        };
        $insertNumber(self::FILTER_MIN_WAREHOUSE_QUANTITY, $forcedMinWarehouseQuantity);
        $insertNumber(self::FILTER_MAX_WAREHOUSE_QUANTITY, $forcedMaxWarehouseQuantity);
        $insertNumber(self::FILTER_MIN_UNIT_SALE_PRICE_SYP, $forcedMinUnitSalePriceSyp);
        $insertNumber(self::FILTER_MAX_UNIT_SALE_PRICE_SYP, $forcedMaxUnitSalePriceSyp);
        $insertNumber(self::FILTER_MIN_UNIT_SALE_PRICE_USD, $forcedMinUnitSalePriceUsd);
        $insertNumber(self::FILTER_MAX_UNIT_SALE_PRICE_USD, $forcedMaxUnitSalePriceUsd);
        $insertNumber(self::FILTER_MIN_UNIT_PURCHASE_PRICE_USD, $forcedMinUnitPurchasePriceUsd);
        $insertNumber(self::FILTER_MAX_UNIT_PURCHASE_PRICE_USD, $forcedMaxUnitPurchasePriceUsd);
        $insertValues(self::OPTION_VISIBLE_CLIENT_FILTER, $visibleClientFilters);
        $insertValues(self::OPTION_CLIENT_SORT_FIELD, $clientSortFields);

        $optionValues = [
            self::OPTION_SHOW_IMAGES => $showImages ? '1' : '0',
            self::OPTION_PRICE_MODE => $priceMode,
            self::OPTION_ALLOW_CLIENT_FILTERS => $allowClientFilters ? '1' : '0',
            self::OPTION_ALLOW_SORTING => $allowSorting ? '1' : '0',
            self::OPTION_INCLUDE_RESULT_FILTERS => $includeResultFilters ? '1' : '0',
            self::OPTION_DEFAULT_SORT => trim((string) $defaultSort) !== '' ? trim((string) $defaultSort) : 'number:asc',
            self::OPTION_DEFAULT_GROUP_BY => trim((string) $defaultGroupBy) !== ''
                && in_array(trim((string) $defaultGroupBy), ['ageCategory', 'sizeRange', 'materialType', 'manufacturer', 'countryOfOrigin', 'group'], true)
                ? trim((string) $defaultGroupBy)
                : 'none',
        ];

        foreach ($optionValues as $type => $value) {
            $insert->execute([
                'link_id' => $linkId,
                'filter_type' => $type,
                'value_ar' => $value,
            ]);
        }
    }

    /** @param array<int, mixed> $values
     *  @return list<string>
     */
    private static function normalizeFilterValues(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $chunks = is_array($value)
                ? $value
                : (preg_split('/[,|\n]+/u', (string) $value) ?: []);
            foreach ($chunks as $chunk) {
                $item = trim((string) $chunk);
                if ($item === '') {
                    continue;
                }
                $normalized[] = $item;
            }
        }

        $normalized = array_values(array_unique($normalized));
        if (count($normalized) > 40) {
            $normalized = array_slice($normalized, 0, 40);
        }

        return $normalized;
    }

    /** @param array<int, mixed> $values
     *  @return list<string>
     */
    private static function normalizeGuidFilterValues(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $chunks = is_array($value)
                ? $value
                : (preg_split('/[,|\n]+/u', (string) $value) ?: []);
            foreach ($chunks as $chunk) {
                $item = trim((string) $chunk);
                if ($item === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $item)) {
                    continue;
                }
                $normalized[] = strtolower($item);
            }
        }

        $normalized = array_values(array_unique($normalized));
        if (count($normalized) > 40) {
            $normalized = array_slice($normalized, 0, 40);
        }

        return $normalized;
    }

    private static function normalizeNullableNumber(?float $value): ?float
    {
        if ($value === null || !is_finite($value)) {
            return null;
        }

        return max(0, $value);
    }

    private static function isValidRange(?float $min, ?float $max): bool
    {
        if ($min === null || $max === null) {
            return true;
        }

        return $min <= $max;
    }

    private static function toNullableFloat(string $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function toNullableBool(string $value): ?bool
    {
        $value = trim(strtolower($value));
        return match ($value) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    /** @param array<int, mixed> $values
     *  @return list<string>
     */
    private static function normalizeVisibleClientFilters(array $values): array
    {
        $normalized = self::normalizeFilterValues($values);
        $allowed = array_flip(self::ALLOWED_VISIBLE_CLIENT_FILTERS);
        $filtered = [];
        foreach ($normalized as $value) {
            if (isset($allowed[$value])) {
                $filtered[] = $value;
            }
        }

        if ($filtered === []) {
            return self::DEFAULT_VISIBLE_CLIENT_FILTERS;
        }

        return array_values(array_unique($filtered));
    }

    /** @param array<int, mixed> $values
     *  @return list<string>
     */
    private static function normalizeClientSortFields(array $values): array
    {
        $normalized = self::normalizeFilterValues($values);
        $allowed = array_flip(self::ALLOWED_CLIENT_SORT_FIELDS);
        $filtered = [];
        foreach ($normalized as $value) {
            if (isset($allowed[$value])) {
                $filtered[] = $value;
            }
        }

        if ($filtered === []) {
            return ['number', 'materialType', 'manufacturer'];
        }

        return array_values(array_unique($filtered));
    }

    /** @return list<string> */
    private static function clientSortFieldsFromDefaultSort(string $defaultSort): array
    {
        $fields = [];
        foreach (array_filter(array_map('trim', explode(',', $defaultSort))) as $clause) {
            if ($clause === '') {
                continue;
            }
            if (str_starts_with($clause, '-')) {
                $field = trim(substr($clause, 1));
            } else {
                $parts = explode(':', $clause, 2);
                $field = trim($parts[0]);
            }
            if ($field !== '' && in_array($field, self::ALLOWED_CLIENT_SORT_FIELDS, true)) {
                $fields[] = $field;
            }
        }

        return self::normalizeClientSortFields($fields);
    }

    private static function toBool(string $value, bool $default): bool
    {
        $value = trim(strtolower($value));
        return match ($value) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }
}
