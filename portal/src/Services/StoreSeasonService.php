<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use Portal\Support\ResponseCache;
use PDO;
use Throwable;

final class StoreSeasonService
{
    public const MODES = [
        'off' => 'إيقاف',
        'manual' => 'يدوي',
        'auto' => 'تلقائي حسب التاريخ',
    ];

    public const ICONS = [
        'wb_sunny' => 'شمس',
        'ac_unit' => 'ثلج',
        'eco' => 'ورقة',
        'local_florist' => 'زهرة',
        'beach_access' => 'مظلة',
        'umbrella' => 'شمسية',
        'school' => 'مدارس',
        'celebration' => 'عيد ومناسبات',
        'all_inclusive' => 'عام',
    ];

    public const MONTHS = [
        1 => 'كانون الثاني',
        2 => 'شباط',
        3 => 'آذار',
        4 => 'نيسان',
        5 => 'أيار',
        6 => 'حزيران',
        7 => 'تموز',
        8 => 'آب',
        9 => 'أيلول',
        10 => 'تشرين الأول',
        11 => 'تشرين الثاني',
        12 => 'كانون الأول',
    ];

    private const CACHE_KEY = 'store_season_current_v1';

    /**
     * The season in effect now, or null when seasons are off.
     *
     * @return array{id: string, name_ar: string, title_ar: string, icon: string, group_guids: list<string>, show_to_customers: bool, mode: string}|null
     */
    public static function current(): ?array
    {
        if (self::$memoReady) {
            return self::$memo;
        }
        self::$memo = self::loadCurrent();
        self::$memoReady = true;

        return self::$memo;
    }

    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

    private static bool $memoReady = false;

    private static function loadCurrent(): ?array
    {
        try {
            $cached = ResponseCache::remember(self::CACHE_KEY, 300, static function (): array {
                $season = self::resolve(self::seasons(), self::settings(), time());

                return ['season' => $season];
            });
        } catch (Throwable) {
            return null;
        }
        $season = is_array($cached) ? ($cached['season'] ?? null) : null;

        return is_array($season) ? $season : null;
    }

    /** @return list<string> */
    public static function priorityGroupGuids(): array
    {
        return self::current()['group_guids'] ?? [];
    }

    /** Current season when the customer should see it (it has groups and is not hidden). */
    public static function customerSeason(): ?array
    {
        $season = self::current();
        if ($season === null || $season['group_guids'] === [] || !$season['show_to_customers']) {
            return null;
        }

        return $season;
    }

    /**
     * @param list<array<string, mixed>> $seasons
     * @param array{mode: string, manual_season_id: string, show_to_customers: bool} $settings
     * @return array{id: string, name_ar: string, title_ar: string, icon: string, group_guids: list<string>, show_to_customers: bool, mode: string}|null
     */
    public static function resolve(array $seasons, array $settings, int $now): ?array
    {
        $mode = $settings['mode'] ?? 'off';
        $picked = null;
        if ($mode === 'manual') {
            foreach ($seasons as $season) {
                if ($season['id'] === ($settings['manual_season_id'] ?? '')) {
                    $picked = $season;
                    break;
                }
            }
        } elseif ($mode === 'auto') {
            $best = null;
            foreach ($seasons as $season) {
                $start = self::lastStart($season, $now);
                if ($start !== null && ($best === null || $start > $best)) {
                    $best = $start;
                    $picked = $season;
                }
            }
        }
        if ($picked === null) {
            return null;
        }

        return [
            'id' => $picked['id'],
            'name_ar' => $picked['name_ar'],
            'title_ar' => $picked['customer_title_ar'] !== '' ? $picked['customer_title_ar'] : $picked['name_ar'],
            'icon' => $picked['icon'],
            'group_guids' => $picked['group_guids'],
            'show_to_customers' => (bool) ($settings['show_to_customers'] ?? true),
            'mode' => $mode,
        ];
    }

    /**
     * Next automatic switch to a different season.
     *
     * @param list<array<string, mixed>> $seasons
     * @return array{season: array<string, mixed>, at: int}|null
     */
    public static function nextSwitch(array $seasons, ?string $currentId, int $now): ?array
    {
        $next = null;
        foreach ($seasons as $season) {
            if ($season['id'] === $currentId || $season['starts_month'] === null) {
                continue;
            }
            $start = self::startIn($season, (int) date('Y', $now));
            if ($start <= $now) {
                $start = self::startIn($season, (int) date('Y', $now) + 1);
            }
            if ($next === null || $start < $next['at']) {
                $next = ['season' => $season, 'at' => $start];
            }
        }

        return $next;
    }

    public static function startLabel(array $season): string
    {
        $month = $season['starts_month'] ?? null;
        if ($month === null) {
            return '';
        }

        return (int) ($season['starts_day'] ?? 1) . ' ' . (self::MONTHS[(int) $month] ?? '');
    }

    private static function lastStart(array $season, int $now): ?int
    {
        if (($season['starts_month'] ?? null) === null) {
            return null;
        }
        $year = (int) date('Y', $now);
        $start = self::startIn($season, $year);

        return $start <= $now ? $start : self::startIn($season, $year - 1);
    }

    private static function startIn(array $season, int $year): int
    {
        $month = (int) $season['starts_month'];
        $day = min((int) ($season['starts_day'] ?? 1), (int) date('t', mktime(0, 0, 0, $month, 1, $year)));

        return (int) mktime(0, 0, 0, $month, $day, $year);
    }

    public static function tableReady(): bool
    {
        try {
            Database::pdo()->query('SELECT 1 FROM store_seasons LIMIT 1');
            Database::pdo()->query('SELECT 1 FROM store_season_settings LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<array<string, mixed>> */
    public static function seasons(): array
    {
        try {
            $rows = Database::pdo()->query(
                'SELECT * FROM store_seasons ORDER BY sort_order, created_at'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }

        return array_map([self::class, 'normalize'], $rows);
    }

    /** @return array{mode: string, manual_season_id: string, show_to_customers: bool} */
    public static function settings(): array
    {
        $defaults = ['mode' => 'off', 'manual_season_id' => '', 'show_to_customers' => true];
        try {
            $row = Database::pdo()->query(
                'SELECT mode, manual_season_id::text AS manual_season_id, show_to_customers FROM store_season_settings WHERE id = 1'
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return $defaults;
        }
        if (!is_array($row)) {
            return $defaults;
        }
        $mode = (string) ($row['mode'] ?? 'off');

        return [
            'mode' => isset(self::MODES[$mode]) ? $mode : 'off',
            'manual_season_id' => (string) ($row['manual_season_id'] ?? ''),
            'show_to_customers' => self::bool($row['show_to_customers'] ?? true),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function getById(string $id): ?array
    {
        foreach (self::seasons() as $season) {
            if ($season['id'] === trim($id)) {
                return $season;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function normalize(array $row): array
    {
        $groups = $row['group_guids'] ?? [];
        if (is_string($groups)) {
            $groups = json_decode($groups, true);
        }
        $guids = [];
        foreach (is_array($groups) ? $groups : [] as $guid) {
            $guid = trim((string) $guid);
            if ($guid !== '' && !in_array($guid, $guids, true)) {
                $guids[] = $guid;
            }
        }
        $month = $row['starts_month'] ?? null;
        $month = $month === null || $month === '' ? null : max(1, min(12, (int) $month));
        $day = $row['starts_day'] ?? null;
        $day = $month === null ? null : max(1, min(31, (int) ($day ?: 1)));
        $icon = trim((string) ($row['icon'] ?? ''));

        return [
            'id' => (string) ($row['id'] ?? ''),
            'name_ar' => trim((string) ($row['name_ar'] ?? '')),
            'customer_title_ar' => trim((string) ($row['customer_title_ar'] ?? '')),
            'icon' => isset(self::ICONS[$icon]) ? $icon : 'wb_sunny',
            'group_guids' => $guids,
            'starts_month' => $month,
            'starts_day' => $day,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, message: string, id?: string}
     */
    public static function save(?string $id, array $input, ?string $userId): array
    {
        $season = self::normalize($input);
        if ($season['name_ar'] === '') {
            return ['ok' => false, 'message' => 'اسم الموسم مطلوب.'];
        }
        if ($season['group_guids'] === []) {
            return ['ok' => false, 'message' => 'اختر مجموعة واحدة على الأقل.'];
        }
        $params = [
            'name_ar' => mb_substr($season['name_ar'], 0, 120),
            'customer_title_ar' => mb_substr($season['customer_title_ar'], 0, 160),
            'icon' => $season['icon'],
            'group_guids' => json_encode($season['group_guids'], JSON_UNESCAPED_UNICODE),
            'starts_month' => $season['starts_month'],
            'starts_day' => $season['starts_day'],
            'sort_order' => $season['sort_order'],
            'user_id' => $userId ?: null,
        ];
        try {
            $pdo = Database::pdo();
            if ($id !== null && $id !== '') {
                $stmt = $pdo->prepare(
                    'UPDATE store_seasons SET name_ar = :name_ar, customer_title_ar = :customer_title_ar, icon = :icon,
                        group_guids = CAST(:group_guids AS jsonb), starts_month = :starts_month, starts_day = :starts_day,
                        sort_order = :sort_order, updated_at = NOW(), updated_by_user_id = :user_id
                     WHERE id::text = :id'
                );
                $stmt->execute($params + ['id' => $id]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO store_seasons (name_ar, customer_title_ar, icon, group_guids, starts_month, starts_day, sort_order, updated_by_user_id)
                     VALUES (:name_ar, :customer_title_ar, :icon, CAST(:group_guids AS jsonb), :starts_month, :starts_day, :sort_order, :user_id)
                     RETURNING id::text'
                );
                $stmt->execute($params);
                $id = (string) $stmt->fetchColumn();
            }
        } catch (Throwable $exception) {
            error_log('StoreSeasonService::save: ' . $exception->getMessage());

            return ['ok' => false, 'message' => 'تعذر حفظ الموسم.'];
        }
        self::forgetCache();

        return ['ok' => true, 'message' => 'تم حفظ الموسم.', 'id' => $id];
    }

    public static function delete(string $id): bool
    {
        try {
            $stmt = Database::pdo()->prepare('DELETE FROM store_seasons WHERE id::text = :id');
            $stmt->execute(['id' => trim($id)]);
        } catch (Throwable) {
            return false;
        }
        self::forgetCache();

        return $stmt->rowCount() > 0;
    }

    /** @param array<string, mixed> $input */
    public static function saveSettings(array $input, ?string $userId): array
    {
        $mode = (string) ($input['mode'] ?? 'off');
        if (!isset(self::MODES[$mode])) {
            $mode = 'off';
        }
        $manualId = trim((string) ($input['manual_season_id'] ?? ''));
        $seasons = self::seasons();
        $ids = array_column($seasons, 'id');
        if ($manualId !== '' && !in_array($manualId, $ids, true)) {
            $manualId = '';
        }
        if ($mode === 'manual' && $manualId === '') {
            return ['ok' => false, 'message' => 'اختر الموسم الحالي.'];
        }
        if ($mode === 'auto' && array_filter($seasons, static fn (array $s): bool => $s['starts_month'] !== null) === []) {
            return ['ok' => false, 'message' => 'حدّد تاريخ بداية لموسم واحد على الأقل.'];
        }
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO store_season_settings (id, mode, manual_season_id, show_to_customers, updated_by_user_id)
                 VALUES (1, :mode, CAST(NULLIF(:manual_id, \'\') AS uuid), :show, :user_id)
                 ON CONFLICT (id) DO UPDATE SET mode = EXCLUDED.mode, manual_season_id = EXCLUDED.manual_season_id,
                    show_to_customers = EXCLUDED.show_to_customers, updated_at = NOW(), updated_by_user_id = EXCLUDED.updated_by_user_id'
            );
            $stmt->bindValue('mode', $mode);
            $stmt->bindValue('manual_id', $manualId);
            $stmt->bindValue('show', !empty($input['show_to_customers']), PDO::PARAM_BOOL);
            $stmt->bindValue('user_id', $userId ?: null);
            $stmt->execute();
        } catch (Throwable $exception) {
            error_log('StoreSeasonService::saveSettings: ' . $exception->getMessage());

            return ['ok' => false, 'message' => 'تعذر حفظ الإعداد.'];
        }
        self::forgetCache();

        return ['ok' => true, 'message' => 'تم حفظ الموسم الحالي.'];
    }

    /**
     * Group guids whose name contains one of the keywords.
     *
     * @param list<array<string, mixed>> $groups
     * @param list<string> $keywords
     * @return list<string>
     */
    public static function groupsMatching(array $groups, array $keywords): array
    {
        $guids = [];
        foreach ($groups as $group) {
            $name = (string) ($group['name'] ?? '');
            foreach ($keywords as $keyword) {
                if ($name !== '' && mb_stripos($name, $keyword) !== false) {
                    $guids[] = (string) $group['guid'];
                    break;
                }
            }
        }

        return array_values(array_unique($guids));
    }

    /**
     * Summer and winter seasons built from group names, each with the general groups.
     *
     * @param list<array<string, mixed>> $groups
     * @return list<array<string, mixed>>
     */
    public static function suggestedSeasons(array $groups): array
    {
        $general = self::groupsMatching($groups, ['عام']);

        return [
            [
                'name_ar' => 'صيفي',
                'customer_title_ar' => 'تشكيلة الصيف',
                'icon' => 'wb_sunny',
                'group_guids' => array_values(array_unique(array_merge(self::groupsMatching($groups, ['صيف']), $general))),
                'starts_month' => 4,
                'starts_day' => 1,
                'sort_order' => 10,
            ],
            [
                'name_ar' => 'شتوي',
                'customer_title_ar' => 'تشكيلة الشتاء',
                'icon' => 'ac_unit',
                'group_guids' => array_values(array_unique(array_merge(self::groupsMatching($groups, ['شتو', 'شتاء']), $general))),
                'starts_month' => 10,
                'starts_day' => 1,
                'sort_order' => 20,
            ],
        ];
    }

    /** @param list<array<string, mixed>> $groups */
    public static function seedSuggested(array $groups, ?string $userId): array
    {
        $created = 0;
        foreach (self::suggestedSeasons($groups) as $season) {
            if ($season['group_guids'] !== [] && self::save(null, $season, $userId)['ok']) {
                $created++;
            }
        }

        return $created > 0
            ? ['ok' => true, 'message' => 'تم إنشاء ' . $created . ' موسم من أسماء المجموعات.']
            : ['ok' => false, 'message' => 'لا توجد مجموعات تحتوي «صيف» أو «شتو» أو «عام» في اسمها.'];
    }

    public static function forgetCache(): void
    {
        ResponseCache::forget(self::CACHE_KEY);
        self::$memoReady = false;
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
