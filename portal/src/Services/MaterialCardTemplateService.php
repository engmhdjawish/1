<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;
use Portal\Database;
use PDO;
use Throwable;

final class MaterialCardTemplateService
{
    public const FIELD_KINDS = ['photo', 'product_name', 'packaging', 'barcode'];

    /** @var array<string, string> */
    public const FIELD_LABELS = [
        'photo' => 'منطقة الصورة',
        'product_name' => 'اسم المنتج',
        'packaging' => 'التعبئة',
        'barcode' => 'الباركود',
    ];

    private const MAX_BYTES = 8_388_608; // 8 MB
    private const REF_W = 1492;
    private const REF_H = 785;

    public static function publicUrl(string $id): string
    {
        return '/media/material-card-template.php?id=' . rawurlencode(trim($id));
    }

    public static function storageDir(): string
    {
        return rtrim(Config::storagePath(), '/\\') . DIRECTORY_SEPARATOR . 'material-card-templates';
    }

    /** @return list<array<string, mixed>> */
    public static function listTemplates(): array
    {
        self::ensureDefaultTemplate();

        $stmt = Database::pdo()->query(
            "SELECT
                id::text AS id,
                name_ar,
                file_name,
                storage_path,
                mime_type,
                file_size_bytes,
                canvas_width,
                canvas_height,
                is_active,
                is_default,
                created_at,
                updated_at
             FROM material_card_templates
             ORDER BY is_default DESC, updated_at DESC, created_at DESC"
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::hydrateTemplate($row, false);
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function getById(string $id, bool $withFields = true): ?array
    {
        $id = trim($id);
        if ($id === '' || preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            "SELECT
                id::text AS id,
                name_ar,
                file_name,
                storage_path,
                mime_type,
                file_size_bytes,
                canvas_width,
                canvas_height,
                is_active,
                is_default,
                created_at,
                updated_at
             FROM material_card_templates
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::hydrateTemplate($row, $withFields);
    }

    /** @return array<string, mixed>|null */
    public static function getDefault(bool $withFields = true): ?array
    {
        self::ensureDefaultTemplate();

        $stmt = Database::pdo()->query(
            "SELECT
                id::text AS id,
                name_ar,
                file_name,
                storage_path,
                mime_type,
                file_size_bytes,
                canvas_width,
                canvas_height,
                is_active,
                is_default,
                created_at,
                updated_at
             FROM material_card_templates
             WHERE is_default = TRUE AND is_active = TRUE
             ORDER BY updated_at DESC
             LIMIT 1"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $stmt = Database::pdo()->query(
                "SELECT
                    id::text AS id,
                    name_ar,
                    file_name,
                    storage_path,
                    mime_type,
                    file_size_bytes,
                    canvas_width,
                    canvas_height,
                    is_active,
                    is_default,
                    created_at,
                    updated_at
                 FROM material_card_templates
                 WHERE is_active = TRUE
                 ORDER BY updated_at DESC
                 LIMIT 1"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!is_array($row)) {
            return null;
        }

        return self::hydrateTemplate($row, $withFields);
    }

    public static function absolutePath(array $template): ?string
    {
        $relative = trim((string) ($template['storage_path'] ?? ''));
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }
        $path = rtrim(Config::storagePath(), '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $file
     * @return array{ok: bool, message: string, template?: array<string, mixed>}
     */
    public static function upload(array $file, string $nameAr, ?string $userId, bool $makeDefault = false): array
    {
        $nameAr = trim($nameAr);
        if ($nameAr === '') {
            $nameAr = 'قالب بطاقة';
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => self::uploadErrorMessage($error)];
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'message' => 'ملف الرفع غير صالح.'];
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return ['ok' => false, 'message' => 'حجم الملف يجب أن يكون أقل من 8 ميجابايت.'];
        }

        $mime = self::detectMime($tmpPath, (string) ($file['type'] ?? ''));
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return ['ok' => false, 'message' => 'استخدم PNG أو JPG أو WebP لملف القالب.'];
        }

        $info = @getimagesize($tmpPath);
        if (!is_array($info) || (int) ($info[0] ?? 0) <= 0 || (int) ($info[1] ?? 0) <= 0) {
            return ['ok' => false, 'message' => 'تعذر قراءة أبعاد صورة القالب.'];
        }

        $dir = self::storageDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => 'تعذر إنشاء مجلد القوالب.'];
        }

        $id = self::generateUuid();
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
        $storedName = $id . '.' . $ext;
        $absolute = $dir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($tmpPath, $absolute)) {
            return ['ok' => false, 'message' => 'تعذر حفظ ملف القالب.'];
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        $relative = 'material-card-templates/' . $storedName;
        $originalName = trim((string) ($file['name'] ?? $storedName));
        $userId = $userId !== null && trim($userId) !== '' ? trim($userId) : null;

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            if ($makeDefault) {
                $pdo->exec('UPDATE material_card_templates SET is_default = FALSE WHERE is_default = TRUE');
            } else {
                $hasDefault = (int) $pdo->query('SELECT COUNT(*) FROM material_card_templates WHERE is_default = TRUE')->fetchColumn();
                $makeDefault = $hasDefault === 0;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO material_card_templates (
                    id, name_ar, file_name, storage_path, mime_type, file_size_bytes,
                    canvas_width, canvas_height, is_active, is_default, uploaded_by_web_user_id
                 ) VALUES (
                    :id, :name_ar, :file_name, :storage_path, :mime_type, :file_size_bytes,
                    :canvas_width, :canvas_height, TRUE, :is_default, :uploaded_by
                 )'
            );
            $stmt->execute([
                'id' => $id,
                'name_ar' => $nameAr,
                'file_name' => $originalName !== '' ? $originalName : $storedName,
                'storage_path' => $relative,
                'mime_type' => $mime,
                'file_size_bytes' => $size,
                'canvas_width' => $width,
                'canvas_height' => $height,
                'is_default' => $makeDefault ? 1 : 0,
                'uploaded_by' => $userId,
            ]);

            self::insertDefaultFields($pdo, $id, $width, $height);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($absolute);

            return ['ok' => false, 'message' => 'تعذر حفظ القالب: ' . $e->getMessage()];
        }

        $template = self::getById($id);

        return [
            'ok' => true,
            'message' => 'تم رفع القالب. اضبط أماكن الحقول ثم احفظ.',
            'template' => $template,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, message: string, template?: array<string, mixed>}
     */
    public static function update(string $id, array $payload): array
    {
        $template = self::getById($id, false);
        if ($template === null) {
            return ['ok' => false, 'message' => 'القالب غير موجود.'];
        }

        $nameAr = trim((string) ($payload['name_ar'] ?? $template['name_ar'] ?? ''));
        if ($nameAr === '') {
            return ['ok' => false, 'message' => 'اسم القالب مطلوب.'];
        }

        $isActive = !empty($payload['is_active']);
        $isDefault = !empty($payload['is_default']);
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : null;

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            if ($isDefault) {
                $pdo->exec('UPDATE material_card_templates SET is_default = FALSE WHERE is_default = TRUE');
            }

            $stmt = $pdo->prepare(
                'UPDATE material_card_templates
                 SET name_ar = :name_ar,
                     is_active = :is_active,
                     is_default = :is_default,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'name_ar' => $nameAr,
                'is_active' => $isActive ? 1 : 0,
                'is_default' => $isDefault ? 1 : 0,
            ]);

            if ($fields !== null) {
                $canvasW = (int) ($template['canvas_width'] ?? 0);
                $canvasH = (int) ($template['canvas_height'] ?? 0);
                self::upsertFields($pdo, $id, $fields, $canvasW, $canvasH);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'message' => 'تعذر حفظ الإعدادات: ' . $e->getMessage()];
        }

        return [
            'ok' => true,
            'message' => 'تم حفظ إعدادات القالب.',
            'template' => self::getById($id),
        ];
    }

    /** @return array{ok: bool, message: string} */
    public static function delete(string $id): array
    {
        $template = self::getById($id, false);
        if ($template === null) {
            return ['ok' => false, 'message' => 'القالب غير موجود.'];
        }

        $path = self::absolutePath($template);
        $stmt = Database::pdo()->prepare('DELETE FROM material_card_templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if ($path !== null) {
            @unlink($path);
        }

        return ['ok' => true, 'message' => 'تم حذف القالب.'];
    }

    /** @return array{ok: bool, gd: bool, freetype: bool, font_path: string|null, has_template: bool, message: string} */
    public static function processingRequirements(): array
    {
        $gd = function_exists('imagecreatetruecolor');
        $freetype = function_exists('imagettftext');
        $fontPath = MaterialImageStorageService::resolveDetailsFontPath();
        $template = null;
        try {
            $template = self::getDefault(false);
        } catch (Throwable) {
            $template = null;
        }
        $hasTemplate = is_array($template) && self::absolutePath($template) !== null;

        $missing = [];
        if (!$gd) {
            $missing[] = 'امتداد GD';
        }
        if (!$freetype) {
            $missing[] = 'GD مع FreeType';
        }
        if ($fontPath === null) {
            $missing[] = 'خط TrueType';
        }
        if (!$hasTemplate) {
            $missing[] = 'قالب افتراضي محفوظ';
        }

        return [
            'ok' => $gd && $freetype && $fontPath !== null && $hasTemplate,
            'gd' => $gd,
            'freetype' => $freetype,
            'font_path' => $fontPath,
            'has_template' => $hasTemplate,
            'message' => $missing === [] ? 'جاهز' : ('تطبيق القالب يتطلب: ' . implode('، ', $missing) . '.'),
        ];
    }

    public static function ensureDefaultTemplate(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $count = (int) Database::pdo()->query('SELECT COUNT(*) FROM material_card_templates')->fetchColumn();
        } catch (Throwable) {
            return;
        }
        if ($count > 0) {
            return;
        }

        $bundled = dirname(__DIR__, 2) . '/resources/branding/material-card-template.png';
        if (!is_file($bundled) || !is_readable($bundled)) {
            return;
        }

        $info = @getimagesize($bundled);
        if (!is_array($info)) {
            return;
        }

        $dir = self::storageDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $id = self::generateUuid();
        $storedName = $id . '.png';
        $absolute = $dir . DIRECTORY_SEPARATOR . $storedName;
        if (!@copy($bundled, $absolute)) {
            return;
        }

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO material_card_templates (
                    id, name_ar, file_name, storage_path, mime_type, file_size_bytes,
                    canvas_width, canvas_height, is_active, is_default
                 ) VALUES (
                    :id, :name_ar, :file_name, :storage_path, :mime_type, :file_size_bytes,
                    :canvas_width, :canvas_height, TRUE, TRUE
                 )'
            );
            $stmt->execute([
                'id' => $id,
                'name_ar' => 'قالب جاويش الافتراضي',
                'file_name' => 'material-card-template.png',
                'storage_path' => 'material-card-templates/' . $storedName,
                'mime_type' => 'image/png',
                'file_size_bytes' => (int) filesize($absolute),
                'canvas_width' => (int) $info[0],
                'canvas_height' => (int) $info[1],
            ]);
            self::insertDefaultFields($pdo, $id, (int) $info[0], (int) $info[1]);
            $pdo->commit();
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($absolute);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function hydrateTemplate(array $row, bool $withFields): array
    {
        $id = (string) ($row['id'] ?? '');
        $template = [
            'id' => $id,
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'file_name' => (string) ($row['file_name'] ?? ''),
            'storage_path' => (string) ($row['storage_path'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? 'image/png'),
            'file_size_bytes' => (int) ($row['file_size_bytes'] ?? 0),
            'canvas_width' => (int) ($row['canvas_width'] ?? 0),
            'canvas_height' => (int) ($row['canvas_height'] ?? 0),
            'is_active' => (bool) ($row['is_active'] ?? false),
            'is_default' => (bool) ($row['is_default'] ?? false),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'url' => $id !== '' ? self::publicUrl($id) : '',
        ];

        if ($withFields) {
            $template['fields'] = self::fieldsForTemplate($id);
        }

        return $template;
    }

    /** @return list<array<string, mixed>> */
    private static function fieldsForTemplate(string $templateId): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT
                id::text AS id,
                field_kind::text AS field_kind,
                x, y, w, h,
                font_size,
                color_hex,
                align::text AS align,
                z_index,
                meta_json
             FROM material_card_template_fields
             WHERE template_id = :template_id
             ORDER BY z_index ASC, field_kind ASC"
        );
        $stmt->execute(['template_id' => $templateId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $meta = $row['meta_json'] ?? [];
            if (is_string($meta)) {
                $decoded = json_decode($meta, true);
                $meta = is_array($decoded) ? $decoded : [];
            }
            $out[] = [
                'id' => (string) ($row['id'] ?? ''),
                'field_kind' => (string) ($row['field_kind'] ?? ''),
                'label_ar' => self::FIELD_LABELS[(string) ($row['field_kind'] ?? '')] ?? (string) ($row['field_kind'] ?? ''),
                'x' => (int) ($row['x'] ?? 0),
                'y' => (int) ($row['y'] ?? 0),
                'w' => (int) ($row['w'] ?? 0),
                'h' => (int) ($row['h'] ?? 0),
                'font_size' => $row['font_size'] !== null ? (float) $row['font_size'] : null,
                'color_hex' => $row['color_hex'] !== null ? (string) $row['color_hex'] : null,
                'align' => $row['align'] !== null ? (string) $row['align'] : null,
                'z_index' => (int) ($row['z_index'] ?? 0),
                'meta' => is_array($meta) ? $meta : [],
            ];
        }

        return $out;
    }

    private static function insertDefaultFields(PDO $pdo, string $templateId, int $width, int $height): void
    {
        $scaleX = $width / self::REF_W;
        $scaleY = $height / self::REF_H;
        $defs = [
            ['photo', 736, 12, 744, 458, null, null, null, 0],
            ['product_name', 732, 495, 330, 91, 22.0, '#1C1C1E', 'right', 1],
            ['packaging', 732, 595, 330, 59, 16.0, '#F5F5F7', 'right', 2],
            ['barcode', 1085, 500, 210, 150, 14.0, '#141414', 'center', 3],
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO material_card_template_fields (
                template_id, field_kind, x, y, w, h, font_size, color_hex, align, z_index, meta_json
             ) VALUES (
                :template_id, :field_kind, :x, :y, :w, :h, :font_size, :color_hex, :align, :z_index, :meta_json::jsonb
             )'
        );

        foreach ($defs as $def) {
            [$kind, $x, $y, $w, $h, $font, $color, $align, $z] = $def;
            $meta = $kind === 'packaging' ? ['icon_inset_right' => 58] : [];
            $stmt->execute([
                'template_id' => $templateId,
                'field_kind' => $kind,
                'x' => (int) round($x * $scaleX),
                'y' => (int) round($y * $scaleY),
                'w' => max(1, (int) round($w * $scaleX)),
                'h' => max(1, (int) round($h * $scaleY)),
                'font_size' => $font,
                'color_hex' => $color,
                'align' => $align,
                'z_index' => $z,
                'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>>|array<string, array<string, mixed>> $fields
     */
    private static function upsertFields(PDO $pdo, string $templateId, array $fields, int $canvasW, int $canvasH): void
    {
        $normalized = [];
        foreach ($fields as $key => $field) {
            if (!is_array($field)) {
                continue;
            }
            $kind = trim((string) ($field['field_kind'] ?? $key));
            if (!in_array($kind, self::FIELD_KINDS, true)) {
                continue;
            }
            $x = max(0, (int) ($field['x'] ?? 0));
            $y = max(0, (int) ($field['y'] ?? 0));
            $w = max(1, (int) ($field['w'] ?? 1));
            $h = max(1, (int) ($field['h'] ?? 1));
            if ($canvasW > 0) {
                $w = min($w, max(1, $canvasW - $x));
            }
            if ($canvasH > 0) {
                $h = min($h, max(1, $canvasH - $y));
            }

            $fontSize = null;
            if (isset($field['font_size']) && $field['font_size'] !== '' && $field['font_size'] !== null) {
                $fontSize = max(8, min(96, (float) $field['font_size']));
            }
            $color = null;
            $rawColor = trim((string) ($field['color_hex'] ?? ''));
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $rawColor) === 1) {
                $color = strtoupper($rawColor);
            }
            $align = trim((string) ($field['align'] ?? ''));
            if (!in_array($align, ['right', 'center', 'left'], true)) {
                $align = $kind === 'barcode' ? 'center' : 'right';
            }
            if ($kind === 'photo') {
                $fontSize = null;
                $color = null;
                $align = null;
            }

            $meta = is_array($field['meta'] ?? null) ? $field['meta'] : [];
            $normalized[$kind] = [
                'field_kind' => $kind,
                'x' => $x,
                'y' => $y,
                'w' => $w,
                'h' => $h,
                'font_size' => $fontSize,
                'color_hex' => $color,
                'align' => $align,
                'z_index' => (int) ($field['z_index'] ?? 0),
                'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ];
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException('لا توجد حقول صالحة للحفظ.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO material_card_template_fields (
                template_id, field_kind, x, y, w, h, font_size, color_hex, align, z_index, meta_json
             ) VALUES (
                :template_id, :field_kind, :x, :y, :w, :h, :font_size, :color_hex, :align, :z_index, :meta_json::jsonb
             )
             ON CONFLICT (template_id, field_kind) DO UPDATE SET
                x = EXCLUDED.x,
                y = EXCLUDED.y,
                w = EXCLUDED.w,
                h = EXCLUDED.h,
                font_size = EXCLUDED.font_size,
                color_hex = EXCLUDED.color_hex,
                align = EXCLUDED.align,
                z_index = EXCLUDED.z_index,
                meta_json = EXCLUDED.meta_json'
        );

        foreach ($normalized as $row) {
            $stmt->execute([
                'template_id' => $templateId,
                'field_kind' => $row['field_kind'],
                'x' => $row['x'],
                'y' => $row['y'],
                'w' => $row['w'],
                'h' => $row['h'],
                'font_size' => $row['font_size'],
                'color_hex' => $row['color_hex'],
                'align' => $row['align'],
                'z_index' => $row['z_index'],
                'meta_json' => $row['meta_json'],
            ]);
        }
    }

    private static function detectMime(string $path, string $fallback): string
    {
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            }
        }
        if ($mime === '') {
            $mime = $fallback;
        }

        return strtolower(trim($mime));
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'الملف أكبر من الحد المسموح.',
            UPLOAD_ERR_PARTIAL => 'اكتمل رفع الملف جزئياً فقط.',
            UPLOAD_ERR_NO_FILE => 'لم يتم اختيار ملف.',
            default => 'فشل رفع الملف.',
        };
    }
}
