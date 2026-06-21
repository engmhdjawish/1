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
            'version' => 2,
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
                'min_height_rem' => 3.2,
                'font_base_rem' => 1,
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
                        'font_size_em' => 0.95,
                        'font_weight' => 800,
                        'nowrap' => true,
                        'direction' => 'rtl',
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
                        'font_size_em' => 0.82,
                        'font_weight' => 400,
                        'nowrap' => true,
                        'direction' => 'rtl',
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
                        'image_scale' => 1,
                        'crop_x_pct' => 50,
                        'crop_y_pct' => 50,
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
                        'font_size_em' => 0.72,
                        'font_weight' => 800,
                        'nowrap' => true,
                        'direction' => 'rtl',
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
                        'font_size_em' => 0.68,
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
                ['key' => 'material.code', 'label' => 'رمز المادة (باركود)', 'type' => 'barcode'],
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

    /** @return list<array{key: string, label: string}> */
    public static function qrTargetCatalog(): array
    {
        return [
            ['key' => 'business.whatsapp', 'label' => 'واتساب الشركة'],
            ['key' => 'business.website', 'label' => 'الموقع الرئيسي'],
            ['key' => 'business.store', 'label' => 'صفحة المتجر'],
            ['key' => 'material.product_url', 'label' => 'صفحة المادة في المتجر'],
            ['key' => 'custom', 'label' => 'رابط مخصص'],
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
            'version' => 2,
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
                'min_height_rem' => self::clampFloat($footer['min_height_rem'] ?? $default['footer']['min_height_rem'], 2, 8),
                'font_base_rem' => self::clampFloat($footer['font_base_rem'] ?? $default['footer']['font_base_rem'], 0.75, 1.5),
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
        if (!in_array($type, ['text', 'image', 'barcode', 'qrcode'], true)) {
            return null;
        }

        $region = (string) ($element['region'] ?? 'footer');
        if (!in_array($region, ['photo', 'footer', 'frame'], true)) {
            $region = 'footer';
        }

        $field = trim((string) ($element['field'] ?? ''));
        $imageUrl = trim((string) ($element['image_url'] ?? ''));
        $qrTarget = trim((string) ($element['qr_target'] ?? 'business.whatsapp'));
        $qrCustomUrl = trim((string) ($element['qr_custom_url'] ?? ''));

        if ($type === 'image' && $field === 'image.fixed' && $imageUrl === '') {
            return null;
        }
        if ($type === 'text' && $field === '') {
            return null;
        }
        if ($type === 'image' && $field === '' && $imageUrl === '') {
            return null;
        }
        if ($type === 'barcode' && $field === '') {
            $field = 'material.code';
        }
        if ($type === 'qrcode' && $qrTarget === 'custom' && $qrCustomUrl === '') {
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
            'field' => $field !== '' ? $field : match ($type) {
                'image' => 'image.fixed',
                'barcode' => 'material.code',
                'qrcode' => '',
                default => 'material.product_line',
            },
            'image_url' => $imageUrl,
            'qr_target' => $qrTarget !== '' ? $qrTarget : 'business.whatsapp',
            'qr_custom_url' => $qrCustomUrl,
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
        if ($type === 'barcode') {
            return [
                'foreground' => self::sanitizeColor((string) ($style['foreground'] ?? '#000000')),
                'background' => trim((string) ($style['background'] ?? '#ffffff')),
                'opacity' => self::clampFloat($style['opacity'] ?? 1, 0, 1),
            ];
        }

        if ($type === 'qrcode') {
            return [
                'foreground' => self::sanitizeColor((string) ($style['foreground'] ?? '#000000')),
                'background' => self::sanitizeColor((string) ($style['background'] ?? '#ffffff')),
                'opacity' => self::clampFloat($style['opacity'] ?? 1, 0, 1),
            ];
        }

        $base = $type === 'image'
            ? [
                'object_fit' => 'contain',
                'background' => '',
                'border_radius_rem' => 0,
                'padding_rem' => 0,
                'opacity' => 1,
                'image_scale' => 1,
                'crop_x_pct' => 50,
                'crop_y_pct' => 50,
            ]
            : [
                'color' => '#ffffff',
                'font_size_em' => 0.78,
                'font_weight' => 700,
                'background' => '',
                'border_radius_rem' => 0,
                'padding_rem' => 0,
                'opacity' => 1,
                'nowrap' => true,
                'direction' => 'rtl',
            ];

        if ($type === 'text' && isset($style['font_size_rem']) && !isset($style['font_size_em'])) {
            $style['font_size_em'] = $style['font_size_rem'];
        }

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
            } elseif (str_ends_with($key, '_em')) {
                $base[$key] = self::clampFloat($style[$key], 0.4, 2.5);
            } elseif (in_array($key, ['image_scale'], true)) {
                $base[$key] = self::clampFloat($style[$key], 0.5, 4);
            } elseif (in_array($key, ['crop_x_pct', 'crop_y_pct'], true)) {
                $base[$key] = self::clampFloat($style[$key], 0, 100);
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
        $guid = trim((string) ($material['guid'] ?? $material['Guid'] ?? $material['materialGuid'] ?? ''));
        $productUrl = $guid !== '' ? '/store.php?guid=' . rawurlencode($guid) : '/store.php';

        $whatsapp = preg_replace('/\D+/', '', trim((string) ($company['company_whatsapp'] ?? '')));
        $whatsappUrl = $whatsapp !== '' ? 'https://wa.me/' . $whatsapp : '';

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
            'material.product_url' => $productUrl,
            'business.company_name' => trim((string) ($company['company_name'] ?? '')) ?: 'جاويش للتجارة',
            'business.company_phone' => $phone,
            'business.company_mobile' => trim((string) ($company['company_mobile'] ?? '')),
            'business.company_whatsapp' => trim((string) ($company['company_whatsapp'] ?? '')),
            'business.company_email' => trim((string) ($company['company_email'] ?? '')),
            'business.company_address' => trim((string) ($company['company_address'] ?? '')),
            'business.company_logo' => $logoUrl,
            'business.website' => '/index.php',
            'business.store' => '/store.php',
            'business.whatsapp' => $whatsappUrl,
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

        if ($type === 'barcode') {
            $code = trim((string) ($fieldMap[$field] ?? $fieldMap['material.code'] ?? ''));
            if ($code === '') {
                return null;
            }

            return array_merge($element, ['barcode_value' => $code]);
        }

        if ($type === 'qrcode') {
            $url = self::resolveQrUrl($element, $fieldMap);
            if ($url === '') {
                return null;
            }

            return array_merge($element, ['qr_url' => $url]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<string, string> $fieldMap
     */
    public static function resolveQrUrl(array $element, array $fieldMap): string
    {
        $target = trim((string) ($element['qr_target'] ?? 'business.whatsapp'));
        if ($target === 'custom') {
            return trim((string) ($element['qr_custom_url'] ?? ''));
        }

        $value = trim((string) ($fieldMap[$target] ?? ''));
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        if (str_starts_with($value, '/')) {
            $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            if ($host === '') {
                return $value;
            }

            return $scheme . '://' . $host . $value;
        }

        return $value;
    }

    public static function barcodeImageUrl(string $code, int $height = 48, string $foreground = '#000000', string $background = '#ffffff'): string
    {
        return '/api/barcode.php?' . http_build_query([
            'code' => $code,
            'h' => $height,
            'fg' => $foreground,
            'bg' => $background,
        ]);
    }

    public static function qrImageUrl(string $url, int $size = 120, string $foreground = '#000000', string $background = '#ffffff'): string
    {
        return '/api/qr.php?' . http_build_query([
            'd' => $url,
            's' => $size,
            'fg' => $foreground,
            'bg' => $background,
        ]);
    }

    /**
     * @param array<string, mixed> $element
     */
    public static function renderElementInnerHtml(array $element): string
    {
        $type = (string) ($element['type'] ?? '');
        if ($type === 'text') {
            return '<div class="material-image-frame__el-text">' . htmlspecialchars((string) ($element['text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        }

        if ($type === 'image') {
            $style = is_array($element['style'] ?? null) ? $element['style'] : [];
            $imgStyle = self::cssMapToString(self::imageInnerStyle($style));

            return '<div class="material-image-frame__el-imagebox"><img class="material-image-frame__el-image" src="'
                . htmlspecialchars((string) ($element['image_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" alt="" style="' . htmlspecialchars($imgStyle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></div>';
        }

        if ($type === 'barcode') {
            $style = is_array($element['style'] ?? null) ? $element['style'] : [];
            $src = self::barcodeImageUrl(
                (string) ($element['barcode_value'] ?? ''),
                64,
                (string) ($style['foreground'] ?? '#000000'),
                (string) ($style['background'] ?? '#ffffff')
            );

            return '<img class="material-image-frame__el-barcode" src="'
                . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" alt="" loading="lazy">';
        }

        if ($type === 'qrcode') {
            $style = is_array($element['style'] ?? null) ? $element['style'] : [];
            $src = self::qrImageUrl(
                (string) ($element['qr_url'] ?? ''),
                128,
                (string) ($style['foreground'] ?? '#000000'),
                (string) ($style['background'] ?? '#ffffff')
            );

            return '<img class="material-image-frame__el-qrcode" src="'
                . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" alt="" loading="lazy" data-mif-qrcode-fallback="'
                . htmlspecialchars((string) ($element['qr_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '">';
        }

        return '';
    }

    /** @param array<string, mixed> $style */
    /** @return array<string, string> */
    public static function imageInnerStyle(array $style): array
    {
        $scale = self::clampFloat($style['image_scale'] ?? 1, 0.5, 4);
        $cropX = self::clampFloat($style['crop_x_pct'] ?? 50, 0, 100);
        $cropY = self::clampFloat($style['crop_y_pct'] ?? 50, 0, 100);
        $fit = (string) ($style['object_fit'] ?? 'contain');

        return [
            'width' => '100%',
            'height' => '100%',
            'object-fit' => in_array($fit, ['contain', 'cover', 'fill'], true) ? $fit : 'contain',
            'object-position' => self::formatPct($cropX) . ' ' . self::formatPct($cropY),
            'transform' => 'scale(' . rtrim(rtrim(number_format($scale, 3, '.', ''), '0'), '.') . ')',
            'transform-origin' => self::formatPct($cropX) . ' ' . self::formatPct($cropY),
        ];
    }

    /** @return array<string, string> */
    public static function sampleFieldMap(): array
    {
        return self::resolveFieldMap([
            'materialCode' => 'A-1024',
            'name' => 'حذاء رياضي أطفال',
            'guid' => '00000000-0000-0000-0000-000000000001',
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
        $direction = (string) ($style['direction'] ?? 'rtl');
        $css['direction'] = $direction;
        $css['text-align'] = match ($align) {
            'center' => 'center',
            'end' => 'end',
            default => 'start',
        };

        $valign = (string) ($element['valign'] ?? 'center');
        $css['align-items'] = match ($align) {
            'center' => 'center',
            'end' => 'flex-end',
            default => 'flex-start',
        };
        $css['justify-content'] = match ($valign) {
            'start' => 'flex-start',
            'end' => 'flex-end',
            default => 'center',
        };

        if (($element['type'] ?? '') === 'text') {
            $fontEm = (float) ($style['font_size_em'] ?? $style['font_size_rem'] ?? 0.78);
            $css['--mif-el-font-em'] = rtrim(rtrim(number_format($fontEm, 3, '.', ''), '0'), '.');
            $css['color'] = (string) ($style['color'] ?? '#ffffff');
            $css['font-weight'] = (string) ((int) ($style['font_weight'] ?? 700));
            if (!empty($style['nowrap'])) {
                $css['white-space'] = 'nowrap';
                $css['overflow'] = 'hidden';
                $css['text-overflow'] = 'ellipsis';
            }
        }

        if (($element['type'] ?? '') === 'image') {
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

        if (($element['type'] ?? '') === 'barcode' || ($element['type'] ?? '') === 'qrcode') {
            $css['display'] = 'flex';
            $css['align-items'] = 'center';
            $css['justify-content'] = 'center';
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
