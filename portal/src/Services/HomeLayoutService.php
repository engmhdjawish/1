<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use Portal\Support\ResponseCache;
use Throwable;

/**
 * Layout of the trial homepage (/home-next.php): which blocks show, in what order, with what
 * titles and options. Stored as one JSON document; unknown keys are dropped on read.
 */
final class HomeLayoutService
{
    private const CACHE_KEY = 'home_layout_v1';

    public const SHOWCASE_STYLES = [
        'mosaic' => 'فسيفساء (بطاقة كبيرة وبطاقات أصغر)',
        'cards' => 'بطاقات متساوية',
        'banners' => 'شرائط عريضة',
    ];

    /**
     * label: shown in the dashboard. title: default customer heading ('' = no editable heading).
     * options: name => [type, default, label, extra].
     *
     * @return array<string, array{label: string, icon: string, title: string, options: array<string, array<string, mixed>>}>
     */
    public static function definitions(): array
    {
        return [
            'stories' => [
                'label' => 'شريط التصفح السريع (الدوائر)',
                'icon' => 'radio_button_checked',
                'title' => '',
                'options' => [
                    'season' => ['type' => 'bool', 'default' => true, 'label' => 'الموسم'],
                    'offers' => ['type' => 'bool', 'default' => true, 'label' => 'العروض'],
                    'sections' => ['type' => 'bool', 'default' => true, 'label' => 'الأقسام'],
                    'ages' => ['type' => 'bool', 'default' => true, 'label' => 'الفئات العمرية'],
                    'max' => ['type' => 'int', 'default' => 16, 'label' => 'أقصى عدد', 'min' => 4, 'max' => 30],
                ],
            ],
            'ads' => [
                'label' => 'الإعلانات المتحركة',
                'icon' => 'view_carousel',
                'title' => '',
                'options' => [],
            ],
            'offers' => [
                'label' => 'العروض الخاصة',
                'icon' => 'local_offer',
                'title' => '',
                'options' => [
                    'limit' => ['type' => 'int', 'default' => 2, 'label' => 'عدد العروض', 'min' => 1, 'max' => 4],
                ],
            ],
            'season' => [
                'label' => 'تشكيلة الموسم',
                'icon' => 'partly_cloudy_day',
                'title' => '',
                'options' => [
                    'chips' => ['type' => 'int', 'default' => 8, 'label' => 'اختصارات الفئات', 'min' => 0, 'max' => 16],
                ],
            ],
            'showcase' => [
                'label' => 'الأقسام الرئيسية',
                'icon' => 'dashboard',
                'title' => 'تسوّق الأقسام',
                'options' => [
                    'style' => ['type' => 'choice', 'default' => 'mosaic', 'label' => 'شكل العرض', 'choices' => self::SHOWCASE_STYLES],
                    'limit' => ['type' => 'int', 'default' => 6, 'label' => 'عدد الأقسام', 'min' => 2, 'max' => 12],
                ],
            ],
            'guide' => [
                'label' => 'تسوّق خطوة بخطوة',
                'icon' => 'touch_app',
                'title' => 'تسوّق خطوة بخطوة',
                'options' => [
                    'ages' => ['type' => 'bool', 'default' => true, 'label' => 'خطوة لمن؟'],
                    'types' => ['type' => 'bool', 'default' => true, 'label' => 'خطوة النوع'],
                    'sizes' => ['type' => 'bool', 'default' => true, 'label' => 'خطوة القياس'],
                ],
            ],
            'shop' => [
                'label' => 'تسوّق حسب (تبويبات)',
                'icon' => 'category',
                'title' => 'تسوّق حسب',
                'options' => [],
            ],
            'rails' => [
                'label' => 'منتجات الأقسام',
                'icon' => 'view_week',
                'title' => '',
                'options' => [],
            ],
            'bands' => [
                'label' => 'أشرطة «تسوّق حسب» المستقلة',
                'icon' => 'view_stream',
                'title' => '',
                'options' => [],
            ],
            'viewed' => [
                'label' => 'شاهدتها مؤخراً',
                'icon' => 'history',
                'title' => 'شاهدتها مؤخراً',
                'options' => [],
            ],
            'feed' => [
                'label' => 'كل المواد',
                'icon' => 'grid_view',
                'title' => 'كل المواد',
                'options' => [
                    'limit' => ['type' => 'int', 'default' => 12, 'label' => 'عدد المواد', 'min' => 4, 'max' => 24],
                ],
            ],
            'help' => [
                'label' => 'المساعدة والتواصل',
                'icon' => 'support_agent',
                'title' => 'تحتاج مساعدة؟',
                'options' => [],
            ],
        ];
    }

    /** @return array{brand: bool, tagline: string, currency: bool, search_placeholder: string, dock: bool} */
    public static function generalDefaults(): array
    {
        return [
            'brand' => true,
            'tagline' => '',
            'currency' => true,
            'search_placeholder' => 'ابحث عن مادة أو شركة',
            'dock' => true,
        ];
    }

    /** @return array{general: array<string, mixed>, blocks: list<array<string, mixed>>} */
    public static function defaults(): array
    {
        return self::normalize([]);
    }

    /** @return array{general: array<string, mixed>, blocks: list<array<string, mixed>>} */
    public static function config(): array
    {
        if (self::$memoReady) {
            return self::$memo;
        }
        try {
            $stored = ResponseCache::remember(self::CACHE_KEY, 300, static fn (): array => ['config' => self::storedConfig()]);
            $raw = is_array($stored['config'] ?? null) ? $stored['config'] : [];
        } catch (Throwable) {
            $raw = [];
        }
        self::$memo = self::normalize($raw);
        self::$memoReady = true;

        return self::$memo;
    }

    /** @var array{general: array<string, mixed>, blocks: list<array<string, mixed>>} */
    private static array $memo = ['general' => [], 'blocks' => []];

    private static bool $memoReady = false;

    /** @return list<array{key: string, on: bool, title: string, options: array<string, mixed>}> */
    public static function enabledBlocks(?array $config = null): array
    {
        $config ??= self::config();

        return array_values(array_filter($config['blocks'], static fn (array $block): bool => $block['on']));
    }

    /** @return array<string, mixed>|null */
    public static function block(string $key, ?array $config = null): ?array
    {
        $config ??= self::config();
        foreach ($config['blocks'] as $block) {
            if ($block['key'] === $key) {
                return $block;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{general: array<string, mixed>, blocks: list<array<string, mixed>>}
     */
    public static function normalize(array $raw): array
    {
        $definitions = self::definitions();
        $generalRaw = is_array($raw['general'] ?? null) ? $raw['general'] : [];
        $general = [];
        foreach (self::generalDefaults() as $name => $default) {
            $value = $generalRaw[$name] ?? $default;
            $general[$name] = is_bool($default) ? self::bool($value) : self::text((string) $value, $name === 'tagline' ? 120 : 80);
        }
        if ($general['search_placeholder'] === '') {
            $general['search_placeholder'] = self::generalDefaults()['search_placeholder'];
        }

        $blocks = [];
        $seen = [];
        $rawBlocks = is_array($raw['blocks'] ?? null) ? $raw['blocks'] : [];
        foreach ($rawBlocks as $rawBlock) {
            $key = is_array($rawBlock) ? (string) ($rawBlock['key'] ?? '') : '';
            if (!isset($definitions[$key]) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $blocks[] = self::normalizeBlock($key, $rawBlock);
        }
        foreach (array_keys($definitions) as $key) {
            if (!isset($seen[$key])) {
                $blocks[] = self::normalizeBlock($key, []);
            }
        }

        return ['general' => $general, 'blocks' => $blocks];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{key: string, on: bool, title: string, options: array<string, mixed>}
     */
    private static function normalizeBlock(string $key, array $raw): array
    {
        $definition = self::definitions()[$key];
        $title = $definition['title'] !== '' ? self::text((string) ($raw['title'] ?? ''), 80) : '';
        $rawOptions = is_array($raw['options'] ?? null) ? $raw['options'] : [];
        $options = [];
        foreach ($definition['options'] as $name => $spec) {
            $value = $rawOptions[$name] ?? $spec['default'];
            $options[$name] = match ($spec['type']) {
                'bool' => self::bool($value),
                'int' => max((int) $spec['min'], min((int) $spec['max'], is_numeric($value) ? (int) $value : (int) $spec['default'])),
                'choice' => isset($spec['choices'][(string) $value]) ? (string) $value : (string) $spec['default'],
                default => $value,
            };
        }

        return [
            'key' => $key,
            'on' => array_key_exists('on', $raw) ? self::bool($raw['on']) : true,
            'title' => $title !== '' ? $title : $definition['title'],
            'options' => $options,
        ];
    }

    /**
     * Turns the dashboard form into a config. Checkboxes that are absent mean "off".
     *
     * @param array<string, mixed> $post
     * @return array{general: array<string, mixed>, blocks: list<array<string, mixed>>}
     */
    public static function fromForm(array $post): array
    {
        $definitions = self::definitions();
        $generalPost = is_array($post['general'] ?? null) ? $post['general'] : [];
        $general = [];
        foreach (self::generalDefaults() as $name => $default) {
            $general[$name] = is_bool($default) ? isset($generalPost[$name]) : (string) ($generalPost[$name] ?? '');
        }
        $order = array_values(array_map('strval', (array) ($post['order'] ?? [])));
        $blocks = [];
        foreach ($order as $key) {
            if (!isset($definitions[$key])) {
                continue;
            }
            $optionsPost = is_array($post['opt'][$key] ?? null) ? $post['opt'][$key] : [];
            $options = [];
            foreach ($definitions[$key]['options'] as $name => $spec) {
                $options[$name] = $spec['type'] === 'bool' ? isset($optionsPost[$name]) : ($optionsPost[$name] ?? $spec['default']);
            }
            $blocks[] = [
                'key' => $key,
                'on' => isset($post['on'][$key]),
                'title' => (string) ($post['title'][$key] ?? ''),
                'options' => $options,
            ];
        }

        return self::normalize(['general' => $general, 'blocks' => $blocks]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{ok: bool, message: string}
     */
    public static function save(array $config, ?string $userId): array
    {
        $config = self::normalize($config);
        if (self::enabledBlocks($config) === []) {
            return ['ok' => false, 'message' => 'فعّل قسماً واحداً على الأقل.'];
        }
        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO home_layout_settings (id, config, updated_by_user_id)
                 VALUES (1, CAST(:config AS jsonb), CAST(NULLIF(:user_id, \'\') AS uuid))
                 ON CONFLICT (id) DO UPDATE SET config = EXCLUDED.config, updated_at = NOW(), updated_by_user_id = EXCLUDED.updated_by_user_id'
            );
            $stmt->execute([
                'config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'user_id' => (string) ($userId ?? ''),
            ]);
        } catch (Throwable $exception) {
            error_log('HomeLayoutService::save: ' . $exception->getMessage());

            return ['ok' => false, 'message' => 'تعذر الحفظ. تأكد من تشغيل ترحيل قاعدة البيانات.'];
        }
        self::forgetCache();

        return ['ok' => true, 'message' => 'تم الحفظ.'];
    }

    /** @return array{ok: bool, message: string} */
    public static function reset(?string $userId): array
    {
        return self::save(self::defaults(), $userId);
    }

    public static function tableReady(): bool
    {
        try {
            Database::pdo()->query('SELECT 1 FROM home_layout_settings LIMIT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public static function storedConfig(): array
    {
        try {
            $value = Database::pdo()->query('SELECT config FROM home_layout_settings WHERE id = 1')->fetchColumn();
        } catch (Throwable) {
            return [];
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    public static function forgetCache(): void
    {
        ResponseCache::forget(self::CACHE_KEY);
        self::$memoReady = false;
    }

    private static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 't', 'on', 'yes'], true);
    }

    private static function text(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_substr($value, 0, $max);
    }
}
