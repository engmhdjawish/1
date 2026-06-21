<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Database;
use PDO;

final class MaterialImageDisplayTemplateService
{
    public const SETTINGS_KEY = 'material_image_display_template';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @return array<string, mixed> */
    public static function defaultTemplate(): array
    {
        return [
            'version' => 1,
            'enabled' => true,
            'photo' => [
                'background' => '#f3f4f6',
            ],
            'footer' => [
                'enabled' => true,
                'background' => 'linear-gradient(180deg, #454545 0%, #3a3a3a 100%)',
                'accent_color' => '#d81921',
                'accent_width_rem' => 0.28,
                'padding_rem' => 0.6,
            ],
            'elements' => [
                [
                    'id' => 'product_line',
                    'type' => 'text',
                    'field' => 'material.product_line',
                    'region' => 'footer',
                    'x_pct' => 3.5,
                    'y_pct' => 18,
                    'width_pct' => 58,
                    'height_pct' => 38,
                    'z_index' => 2,
                    'align' => 'start',
                    'valign' => 'center',
                    'style' => [
                        'color' => '#ffffff',
                        'font_size_rem' => 0.78,
                        'font_weight' => 800,
                        'nowrap' => true,
                    ],
                ],
                [
                    'id' => 'packaging_line',
                    'type' => 'text',
                    'field' => 'material.packaging_line',
                    'region' => 'footer',
                    'x_pct' => 3.5,
                    'y_pct' => 56,
                    'width_pct' => 58,
                    'height_pct' => 34,
                    'z_index' => 2,
                    'align' => 'start',
                    'valign' => 'start',
                    'style' => [
                        'color' => 'rgba(255,255,255,0.88)',
                        'font_size_rem' => 0.68,
                        'font_weight' => 400,
                        'nowrap' => true,
                    ],
                ],
                [
                    'id' => 'company_logo',
                    'type' => 'image',
                    'field' => 'business.company_logo',
                    'region' => 'footer',
                    'x_pct' => 70,
                    'y_pct' => 14,
                    'width_pct' => 12,
                    'height_pct' => 72,
                    'z_index' => 3,
                    'align' => 'center',
                    'valign' => 'center',
                    'style' => [
                        'object_fit' => 'contain',
                        'background' => 'rgba(255,255,255,0.95)',
                        'border_radius_rem' => 0.35,
                        'padding_rem' => 0.12,
                    ],
                ],
                [
                    'id' => 'company_name',
                    'type' => 'text',
                    'field' => 'business.company_name',
                    'region' => 'footer',
                    'x_pct' => 58,
                    'y_pct' => 22,
                    'width_pct' => 38,
                    'height_pct' => 30,
                    'z_index' => 2,
                    'align' => 'end',
                    'valign' => 'center',
                    'style' => [
                        'color' => '#ffffff',
                        'font_size_rem' => 0.62,
                        'font_weight' => 800,
                        'nowrap' => true,
                    ],
                ],
                [
                    'id' => 'company_phone',
                    'type' => 'text',
                    'field' => 'business.company_phone',
                    'region' => 'footer',
                    'x_pct' => 58,
                    'y_pct' => 54,
                    'width_pct' => 38,
                    'height_pct' => 30,
                    'z_index' => 2,
                    'align' => 'end',
                    'valign' => 'start',
                    'style' => [
                        'color' => 'rgba(255,255,255,0.82)',
                        'font_size_rem' => 0.6,
                        'font_weight' => 700,
                        'nowrap' => true,
                        'direction' => 'ltr',
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, list<array{key: string, label: string, type: string}>> */
    public static function fieldCatalog(): array
    {
        return [
            'material' => [
                ['key' => 'material.product_line', 'label' => 'رمز - اسم المنتج', 'type' => 'text'],
                ['key' => 'material.packaging_line', 'label' => 'سطر التعبئة', 'type' => 'text'],
                ['key' => 'material.code', 'label' => 'رمز المادة', 'type' => 'text'],
                ['key' => 'material.name', 'label' => 'اسم المادة', 'type' => 'text'],
                ['key' => 'material.manufacturer', 'label' => 'الشركة المصنعة', 'type' => 'text'],
                ['key' => 'material.material_type', 'label' => 'نوع المادة', 'type' => 'text'],
                ['key' => 'material.age_category', 'label' => 'الفئة العمرية', 'type' => 'text'],
                ['key' => 'material.size_range', 'label' => 'القياس', 'type' => 'text'],
                ['key' => 'material.country_of_origin', 'label' => 'بلد المنشأ', 'type' => 'text'],
                ['key' => 'material.group_name', 'label' => 'المجموعة', 'type' => 'text'],
            ],
            'business' => [
                ['key' => 'business.company_name', 'label' => 'اسم الشركة', 'type' => 'text'],
                ['key' => 'business.company_phone', 'label' => 'هاتف الشركة', 'type' => 'text'],
                ['key' => 'business.company_mobile', 'label' => 'جوال الشركة', 'type' => 'text'],
                ['key' => 'business.company_whatsapp', 'label' => 'واتساب', 'type' => 'text'],
                ['key' => 'business.company_email', 'label' => 'البريد الإلكتروني', 'type' => 'text'],
                ['key' => 'business.company_address', 'label' => 'العنوان', 'type' => 'text'],
                ['key' => 'business.company_logo', 'label' => 'شعار الشركة (من الإعدادات)', 'type' => 'image'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function getTemplate(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT value_ar FROM company_settings WHERE key = :key LIMIT 1'
        );
        $stmt->execute(['key' => self::SETTINGS_KEY]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || trim($raw) === '') {
            self::$cache = self::defaultTemplate();

            return self::$cache;
        }

        $decoded = json_decode($raw, true);
        self::$cache = self::normalizeTemplate(is_array($decoded) ? $decoded : []);

        return self::$cache;
    }

    /** @param array<string, mixed> $template */
    public static function saveTemplate(array $template, ?string $updatedByUserId): void
    {
        $normalized = self::normalizeTemplate($template);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \InvalidArgumentException('تعذر ترميز القالب.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO company_settings (key, value_ar, updated_by_user_id)
             VALUES (:key, :value_ar, :updated_by_user_id)
             ON CONFLICT (key)
             DO UPDATE SET
                value_ar = EXCLUDED.value_ar,
                updated_at = NOW(),
                updated_by_user_id = EXCLUDED.updated_by_user_id'
        );
        $stmt->execute([
            'key' => self::SETTINGS_KEY,
            'value_ar' => $json,
            'updated_by_user_id' => $updatedByUserId ?: null,
        ]);

        self::$cache = $normalized;
    }

    public static function resetTemplate(?string $updatedByUserId): void
    {
        self::saveTemplate(self::defaultTemplate(), $updatedByUserId);
    }

    /** @param array<string, mixed> $template */
    public static function normalizeTemplate(array $template): array
    {
        $default = self::defaultTemplate();
        $footer = is_array($template['footer'] ?? null) ? $template['footer'] : [];
        $photo = is_array($template['photo'] ?? null) ? $template['photo'] : [];
        $elements = [];

        foreach (is_array($template['elements'] ?? null) ? $template['elements'] : [] as $element) {
            if (!is_array($element)) {
                continue;
            }
            $normalized = self::normalizeElement($element);
            if ($normalized !== null) {
                $elements[] = $normalized;
            }
        }

        if ($elements === []) {
            $elements = $default['elements'];
        }

        usort($elements, static fn (array $a, array $b): int => ((int) ($a['z_index'] ?? 0)) <=> ((int) ($b['z_index'] ?? 0)));

        return [
            'version' => 1,
            'enabled' => (bool) ($template['enabled'] ?? true),
            'photo' => [
                'background' => self::sanitizeColor((string) ($photo['background'] ?? $default['photo']['background'])),
            ],
            'footer' => [
                'enabled' => (bool) ($footer['enabled'] ?? $default['footer']['enabled']),
                'background' => trim((string) ($footer['background'] ?? $default['footer']['background'])),
                'accent_color' => self::sanitizeColor((string) ($footer['accent_color'] ?? $default['footer']['accent_color'])),
                'accent_width_rem' => self::clampFloat($footer['accent_width_rem'] ?? $default['footer']['accent_width_rem'], 0, 1),
                'padding_rem' => self::clampFloat($footer['padding_rem'] ?? $default['footer']['padding_rem'], 0, 2),
            ],
            'elements' => $elements,
        ];
    }

    /**
     * @param array<string, mixed> $element
     * @return array<string, mixed>|null
     */
    private static function normalizeElement(array $element): ?array
    {
        $type = (string) ($element['type'] ?? '');
        if (!in_array($type, ['text', 'image'], true)) {
            return null;
        }

        $region = (string) ($element['region'] ?? 'footer');
        if (!in_array($region, ['photo', 'footer'], true)) {
            $region = 'footer';
        }

        $field = trim((string) ($element['field'] ?? ''));
        $imageUrl = trim((string) ($element['image_url'] ?? ''));
        if ($type === 'image' && $field === 'image.fixed' && $imageUrl === '') {
            return null;
        }
        if ($type === 'text' && $field === '') {
            return null;
        }
        if ($type === 'image' && $field === '' && $imageUrl === '') {
            return null;
        }

        $style = is_array($element['style'] ?? null) ? $element['style'] : [];
        $id = trim((string) ($element['id'] ?? ''));
        if ($id === '') {
            $id = 'el_' . bin2hex(random_bytes(4));
        }

        return [
            'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?: ('el_' . bin2hex(random_bytes(4))),
            'type' => $type,
            'field' => $field !== '' ? $field : ($type === 'image' ? 'image.fixed' : 'material.product_line'),
            'image_url' => $imageUrl,
            'region' => $region,
            'x_pct' => self::clampFloat($element['x_pct'] ?? 0, 0, 100),
            'y_pct' => self::clampFloat($element['y_pct'] ?? 0, 0, 100),
            'width_pct' => self::clampFloat($element['width_pct'] ?? 20, 1, 100),
            'height_pct' => self::clampFloat($element['height_pct'] ?? 20, 1, 100),
            'z_index' => max(0, min(99, (int) ($element['z_index'] ?? 1))),
            'align' => in_array(($element['align'] ?? 'start'), ['start', 'center', 'end'], true)
                ? (string) $element['align']
                : 'start',
            'valign' => in_array(($element['valign'] ?? 'center'), ['start', 'center', 'end'], true)
                ? (string) $element['valign']
                : 'center',
            'style' => self::normalizeStyle($style, $type),
        ];
    }

    /** @param array<string, mixed> $style */
    /** @return array<string, mixed> */
    private static function normalizeStyle(array $style, string $type): array
    {
        $base = $type === 'image'
            ? [
                'object_fit' => 'contain',
                'background' => '',
                'border_radius_rem' => 0,
                'padding_rem' => 0,
                'opacity' => 1,
            ]
            : [
                'color' => '#ffffff',
                'font_size_rem' => 0.72,
                'font_weight' => 700,
                'background' => '',
                'border_radius_rem' => 0,
                'padding_rem' => 0,
                'opacity' => 1,
                'nowrap' => true,
                'direction' => 'rtl',
            ];

        foreach ($base as $key => $defaultValue) {
            if (!array_key_exists($key, $style)) {
                $base[$key] = $defaultValue;
                continue;
            }
            if ($key === 'nowrap') {
                $base[$key] = (bool) $style[$key];
            } elseif ($key === 'font_weight') {
                $base[$key] = max(100, min(900, (int) $style[$key]));
            } elseif ($key === 'opacity') {
                $base[$key] = self::clampFloat($style[$key], 0, 1);
            } elseif (str_ends_with($key, '_rem')) {
                $base[$key] = self::clampFloat($style[$key], 0, 4);
            } elseif ($key === 'direction') {
                $base[$key] = in_array($style[$key], ['rtl', 'ltr'], true) ? (string) $style[$key] : 'rtl';
            } elseif ($key === 'object_fit') {
                $fit = (string) $style[$key];
                $base[$key] = in_array($fit, ['contain', 'cover', 'fill'], true) ? $fit : 'contain';
            } else {
                $base[$key] = trim((string) $style[$key]);
            }
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $material
     * @param array<string, string>|null $company
     * @return array<string, string>
     */
    public static function resolveFieldMap(array $material, ?array $company = null): array
    {
        $company ??= PortalSettingsService::companySettings();
        $logoUrl = PortalSettingsService::companyLogoUrl($company) ?? '';
        $phone = trim((string) ($company['company_phone'] ?? ''));
        if ($phone === '') {
            $phone = trim((string) ($company['company_mobile'] ?? ''));
        }

        $packaging = ShareCartService::packaging($material);
        $packagingLine = '';
        if ($packaging > 0) {
            $unit = ShareCartService::primaryUnitLabel($material);
            $qty = rtrim(rtrim(number_format($packaging, 2, '.', ''), '0'), '.');
            $packagingLine = 'التعبئة : ' . $qty . ' ' . $unit;
        }

        $code = trim((string) ($material['materialCode'] ?? $material['code'] ?? $material['material_code'] ?? ''));
        $name = trim((string) ($material['name'] ?? $material['Name'] ?? ''));
        $productLine = $code !== '' && $name !== '' ? $code . ' - ' . $name : ($code !== '' ? $code : $name);

        return [
            'material.product_line' => $productLine,
            'material.packaging_line' => $packagingLine,
            'material.code' => $code,
            'material.name' => $name,
            'material.manufacturer' => trim((string) ($material['manufacturer'] ?? '')),
            'material.material_type' => trim((string) ($material['materialType'] ?? $material['material_type'] ?? '')),
            'material.age_category' => trim((string) ($material['ageCategory'] ?? $material['age_category'] ?? '')),
            'material.size_range' => trim((string) ($material['sizeRange'] ?? $material['size_range'] ?? '')),
            'material.country_of_origin' => trim((string) ($material['countryOfOrigin'] ?? $material['country_of_origin'] ?? '')),
            'material.group_name' => trim((string) ($material['groupName'] ?? $material['group_name'] ?? '')),
            'business.company_name' => trim((string) ($company['company_name'] ?? '')) ?: 'جاويش للتجارة',
            'business.company_phone' => $phone,
            'business.company_mobile' => trim((string) ($company['company_mobile'] ?? '')),
            'business.company_whatsapp' => trim((string) ($company['company_whatsapp'] ?? '')),
            'business.company_email' => trim((string) ($company['company_email'] ?? '')),
            'business.company_address' => trim((string) ($company['company_address'] ?? '')),
            'business.company_logo' => $logoUrl,
        ];
    }

    /**
     * @param array<string, mixed> $material
     * @param array<string, string>|null $company
     * @return list<array<string, mixed>>
     */
    public static function resolvedElements(array $material, ?array $company = null): array
    {
        $template = self::getTemplate();
        if (!(bool) ($template['enabled'] ?? true)) {
            return [];
        }

        $fieldMap = self::resolveFieldMap($material, $company);
        $result = [];

        foreach ($template['elements'] as $element) {
            if (!is_array($element)) {
                continue;
            }
            $resolved = self::resolveElement($element, $fieldMap);
            if ($resolved !== null) {
                $result[] = $resolved;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<string, string> $fieldMap
     * @return array<string, mixed>|null
     */
    public static function resolveElement(array $element, array $fieldMap): ?array
    {
        $type = (string) ($element['type'] ?? '');
        $field = (string) ($element['field'] ?? '');

        if ($type === 'text') {
            $text = trim((string) ($fieldMap[$field] ?? ''));
            if ($text === '') {
                return null;
            }

            return array_merge($element, ['text' => $text]);
        }

        if ($type === 'image') {
            $url = '';
            if ($field === 'image.fixed') {
                $url = trim((string) ($element['image_url'] ?? ''));
            } else {
                $url = trim((string) ($fieldMap[$field] ?? ''));
            }
            if ($url === '') {
                return null;
            }

            return array_merge($element, ['image_url' => $url]);
        }

        return null;
    }

    /** @return array<string, string> */
    public static function sampleFieldMap(): array
    {
        return self::resolveFieldMap([
            'materialCode' => 'A-1024',
            'name' => 'حذاء رياضي أطفال',
            'manufacturer' => 'JAWISH',
            'materialType' => 'أحذية',
            'ageCategory' => '3-6 سنوات',
            'sizeRange' => '28-32',
            'countryOfOrigin' => 'الصين',
            'groupName' => 'أطفال',
            'Unit2Fact' => 8,
            'unit2Name' => 'زوج',
        ]);
    }

    /**
     * @param array<string, mixed> $element
     * @return array<string, string>
     */
    public static function elementInlineStyle(array $element): array
    {
        $style = is_array($element['style'] ?? null) ? $element['style'] : [];
        $css = [
            'left' => self::formatPct((float) ($element['x_pct'] ?? 0)),
            'top' => self::formatPct((float) ($element['y_pct'] ?? 0)),
            'width' => self::formatPct((float) ($element['width_pct'] ?? 20)),
            'height' => self::formatPct((float) ($element['height_pct'] ?? 20)),
            'z-index' => (string) ((int) ($element['z_index'] ?? 1)),
            'opacity' => (string) self::clampFloat($style['opacity'] ?? 1, 0, 1),
        ];

        $align = (string) ($element['align'] ?? 'start');
        $css['text-align'] = match ($align) {
            'center' => 'center',
            'end' => 'right',
            default => 'left',
        };

        $valign = (string) ($element['valign'] ?? 'center');
        $css['justify-content'] = match ($valign) {
            'start' => 'flex-start',
            'end' => 'flex-end',
            default => 'center',
        };

        if (($element['type'] ?? '') === 'text') {
            $css['color'] = (string) ($style['color'] ?? '#ffffff');
            $css['font-size'] = self::formatRem((float) ($style['font_size_rem'] ?? 0.72));
            $css['font-weight'] = (string) ((int) ($style['font_weight'] ?? 700));
            if (!empty($style['nowrap'])) {
                $css['white-space'] = 'nowrap';
                $css['overflow'] = 'hidden';
                $css['text-overflow'] = 'ellipsis';
            }
            if (!empty($style['direction'])) {
                $css['direction'] = (string) $style['direction'];
            }
        }

        if (($element['type'] ?? '') === 'image') {
            $css['object-fit'] = (string) ($style['object_fit'] ?? 'contain');
            if (!empty($style['background'])) {
                $css['background'] = (string) $style['background'];
            }
            if ((float) ($style['border_radius_rem'] ?? 0) > 0) {
                $css['border-radius'] = self::formatRem((float) $style['border_radius_rem']);
            }
            if ((float) ($style['padding_rem'] ?? 0) > 0) {
                $css['padding'] = self::formatRem((float) $style['padding_rem']);
            }
        }

        if (!empty($style['background']) && ($element['type'] ?? '') === 'text') {
            $css['background'] = (string) $style['background'];
            if ((float) ($style['border_radius_rem'] ?? 0) > 0) {
                $css['border-radius'] = self::formatRem((float) $style['border_radius_rem']);
            }
            if ((float) ($style['padding_rem'] ?? 0) > 0) {
                $css['padding'] = self::formatRem((float) $style['padding_rem']);
            }
        }

        return $css;
    }

    public static function cssMapToString(array $css): string
    {
        $parts = [];
        foreach ($css as $property => $value) {
            if ($value === '') {
                continue;
            }
            $parts[] = $property . ':' . $value;
        }

        return implode(';', $parts);
    }

    private static function formatPct(float $value): string
    {
        return rtrim(rtrim(number_format(self::clampFloat($value, 0, 100), 2, '.', ''), '0'), '.') . '%';
    }

    private static function formatRem(float $value): string
    {
        return rtrim(rtrim(number_format(self::clampFloat($value, 0, 4), 3, '.', ''), '0'), '.') . 'rem';
    }

    private static function sanitizeColor(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '#3a3a3a';
        }
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1) {
            return $value;
        }
        if (str_starts_with($value, 'rgb') || str_starts_with($value, 'linear-gradient')) {
            return $value;
        }

        return '#3a3a3a';
    }

    private static function clampFloat(mixed $value, float $min, float $max): float
    {
        if (!is_numeric($value)) {
            return $min;
        }

        return max($min, min($max, (float) $value));
    }
}
