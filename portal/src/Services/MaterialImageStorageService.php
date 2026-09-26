<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Support\ArabicGdText;
use Portal\Config;
use Portal\Database;
use Throwable;

final class MaterialImageStorageService
{
    private const MAX_BYTES = 10_485_760; // 10 MB
    private const THUMB_MAX = 300;

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** @var list<string> */
    private const LISTABLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    private static bool $settingsReady = false;

    /** @var array<string, list<string>> */
    private static array $fileNameByGuid = [];

    public static function ensureSettings(): void
    {
        if (self::$settingsReady) {
            return;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO company_settings (key, value_ar)
             VALUES (:key, :value_ar)
             ON CONFLICT (key) DO NOTHING'
        );
        foreach (['material_images_dir', 'material_thumbnails_dir'] as $key) {
            $stmt->execute(['key' => $key, 'value_ar' => '']);
        }

        self::$settingsReady = true;
    }

    /**
     * Ensure PHP and portal temp directories exist for uploads.
     *
     * @return array{configured: string, active: string, exists: bool, writable: bool, created: bool}
     */
    public static function ensurePhpUploadTempDir(): array
    {
        $configured = trim((string) ini_get('upload_tmp_dir'));
        $candidates = [];
        if ($configured !== '') {
            $candidates[] = $configured;
        }
        $candidates[] = rtrim(Config::storagePath(), '/\\') . DIRECTORY_SEPARATOR . 'php-upload-tmp';

        $created = false;
        $active = '';
        foreach ($candidates as $path) {
            if (!is_dir($path)) {
                if (@mkdir($path, 0775, true) && is_dir($path)) {
                    $created = true;
                }
            }
            if (is_dir($path) && is_writable($path)) {
                $active = $path;
                break;
            }
        }

        if ($active === '') {
            $active = $configured !== '' ? $configured : $candidates[count($candidates) - 1];
        }

        return [
            'configured' => $configured !== '' ? $configured : $active,
            'active' => $active,
            'exists' => is_dir($active),
            'writable' => is_dir($active) && is_writable($active),
            'created' => $created,
        ];
    }

    public static function writableTempDir(): string
    {
        $status = self::ensurePhpUploadTempDir();
        if ($status['writable']) {
            return $status['active'];
        }

        $fallback = sys_get_temp_dir();
        if (is_dir($fallback) && is_writable($fallback)) {
            return $fallback;
        }

        return $status['active'];
    }

    /** @return array{images_dir: string, thumbnails_dir: string} */
    public static function settings(): array
    {
        self::ensureSettings();
        $map = PortalSettingsService::companySettings();

        return [
            'images_dir' => self::resolveDirectory((string) ($map['material_images_dir'] ?? ''), 'material-images'),
            'thumbnails_dir' => self::resolveDirectory((string) ($map['material_thumbnails_dir'] ?? ''), 'material-images/thumbnails'),
        ];
    }

    /** @param array{material_images_dir?: string, material_thumbnails_dir?: string} $values */
    public static function saveSettings(array $values, ?string $updatedByUserId): void
    {
        $current = PortalSettingsService::companySettings();
        PortalSettingsService::saveCompanySettings([
            'company_name' => (string) ($current['company_name'] ?? ''),
            'company_phone' => (string) ($current['company_phone'] ?? ''),
            'company_mobile' => (string) ($current['company_mobile'] ?? ''),
            'company_whatsapp' => (string) ($current['company_whatsapp'] ?? ''),
            'company_email' => (string) ($current['company_email'] ?? ''),
            'company_address' => (string) ($current['company_address'] ?? ''),
            'company_logo' => (string) ($current['company_logo'] ?? ''),
            'about_us_title_ar' => (string) ($current['about_us_title_ar'] ?? ''),
            'about_us_ar' => (string) ($current['about_us_ar'] ?? ''),
            'material_images_dir' => trim((string) ($values['material_images_dir'] ?? '')),
            'material_thumbnails_dir' => trim((string) ($values['material_thumbnails_dir'] ?? '')),
        ], $updatedByUserId);
    }

    /**
     * @param array<string, mixed> $file single $_FILES entry
     * @return array{ok: bool, message: string, file_name?: string, replaced?: bool, local_path?: string}
     */
    public static function uploadSingleFromBinary(string $binary, string $originalName, ?string $uploadedByUserId = null): array
    {
        $settings = self::settings();
        if (!self::ensureDirectory($settings['images_dir']) || !self::ensureDirectory($settings['thumbnails_dir'])) {
            return ['ok' => false, 'message' => 'تعذر إنشاء مجلدات التخزين.'];
        }

        $size = strlen($binary);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return ['ok' => false, 'message' => 'الحجم يجب أن يكون أقل من 10 ميجابايت.'];
        }

        $tmpPath = tempnam(self::writableTempDir(), 'miu_');
        if ($tmpPath === false) {
            return ['ok' => false, 'message' => 'تعذر إنشاء ملف مؤقت على الخادم.'];
        }

        if (@file_put_contents($tmpPath, $binary) === false) {
            @unlink($tmpPath);

            return ['ok' => false, 'message' => 'تعذر كتابة الملف المؤقت على الخادم.'];
        }

        $result = self::uploadOneFromTempPath(
            $tmpPath,
            $originalName,
            $size,
            $settings['images_dir'],
            $settings['thumbnails_dir'],
            false
        );
        if (!($result['ok'] ?? false)) {
            return $result;
        }

        return self::finalizeUploadedFile($result, $settings, $uploadedByUserId);
    }

    public static function uploadSingle(array $file, ?string $uploadedByUserId = null): array
    {
        $settings = self::settings();
        if (!self::ensureDirectory($settings['images_dir']) || !self::ensureDirectory($settings['thumbnails_dir'])) {
            return ['ok' => false, 'message' => 'تعذر إنشاء مجلدات التخزين.'];
        }

        $result = self::uploadOne($file, $settings['images_dir'], $settings['thumbnails_dir']);
        if (!($result['ok'] ?? false)) {
            return $result;
        }

        return self::finalizeUploadedFile($result, $settings, $uploadedByUserId);
    }

    /**
     * @param array{ok: bool, message: string, file_name?: string, replaced?: bool, renamed?: bool, requested_name?: string} $result
     * @param array{images_dir: string, thumbnails_dir: string} $settings
     * @return array{ok: bool, message: string, file_name?: string, replaced?: bool, renamed?: bool, requested_name?: string, local_path?: string}
     */
    private static function finalizeUploadedFile(array $result, array $settings, ?string $uploadedByUserId): array
    {
        $fileName = (string) ($result['file_name'] ?? '');
        $localPath = self::safeJoin($settings['images_dir'], $fileName) ?? '';
        $thumbPath = self::safeJoin($settings['thumbnails_dir'], $fileName);

        try {
            MaterialImageSyncService::enqueue($fileName, $localPath, $thumbPath, $uploadedByUserId);
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => 'تم حفظ الصورة محلياً لكن تعذر إضافتها لطابور المزامنة: ' . $exception->getMessage(),
                'file_name' => $fileName,
                'replaced' => (bool) ($result['replaced'] ?? false),
                'local_path' => $localPath,
            ];
        }

        return [
            'ok' => true,
            'message' => ($result['renamed'] ?? false)
                ? ('تم حفظ الصورة باسم «' . $fileName . '» (تعارض الاسم) وإضافتها لطابور مزامنة الأمين.')
                : 'تم حفظ الصورة على الموقع وإضافتها لطابور مزامنة الأمين.',
            'file_name' => $fileName,
            'replaced' => false,
            'renamed' => (bool) ($result['renamed'] ?? false),
            'requested_name' => (string) ($result['requested_name'] ?? $fileName),
            'local_path' => $localPath,
        ];
    }

    /**
     * @param list<array<string, mixed>> $files from $_FILES['files']
     * @return array{ok: bool, message: string, uploaded: list<string>, replaced: list<string>, failed: list<string>}
     */
    public static function uploadMany(array $files, ?string $uploadedByUserId = null): array
    {
        $settings = self::settings();
        if (!self::ensureDirectory($settings['images_dir']) || !self::ensureDirectory($settings['thumbnails_dir'])) {
            return [
                'ok' => false,
                'message' => 'تعذر إنشاء مجلدات تخزين صور المواد. راجع المسارات في الإعدادات.',
                'uploaded' => [],
                'replaced' => [],
                'failed' => [],
            ];
        }

        $uploaded = [];
        $replaced = [];
        $failed = [];

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $result = self::uploadOne($file, $settings['images_dir'], $settings['thumbnails_dir']);
            if (!$result['ok']) {
                $failed[] = ((string) ($file['name'] ?? 'file')) . ': ' . $result['message'];
                continue;
            }

            $fileName = (string) ($result['file_name'] ?? '');
            $localPath = self::safeJoin($settings['images_dir'], $fileName) ?? '';
            $thumbPath = self::safeJoin($settings['thumbnails_dir'], $fileName);

            try {
                MaterialImageSyncService::enqueue($fileName, $localPath, $thumbPath, $uploadedByUserId);
            } catch (Throwable $exception) {
                $failed[] = $fileName . ': تعذر إضافة الصورة لطابور المزامنة: ' . $exception->getMessage();
                continue;
            }

            $uploaded[] = $fileName;
        }

        if ($uploaded === [] && $replaced === [] && $failed !== []) {
            return [
                'ok' => false,
                'message' => 'لم يُرفع أي ملف.',
                'uploaded' => [],
                'replaced' => [],
                'failed' => $failed,
            ];
        }

        $parts = [];
        if ($uploaded !== []) {
            $parts[] = count($uploaded) . ' ملف جديد';
        }
        if ($replaced !== []) {
            $parts[] = count($replaced) . ' ملف مستبدل';
        }
        if ($failed !== []) {
            $parts[] = count($failed) . ' فشل';
        }

        return [
            'ok' => true,
            'message' => 'تم الرفع: ' . implode('، ', $parts) . '.',
            'uploaded' => $uploaded,
            'replaced' => $replaced,
            'failed' => $failed,
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function listLocalFiles(): array
    {
        $settings = self::settings();
        $dir = $settings['images_dir'];
        if (!is_dir($dir)) {
            return [];
        }

        $rows = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }
            if (!self::isListableFileName($entry)) {
                continue;
            }

            $thumbPath = self::findFileInDirectory($settings['thumbnails_dir'], $entry);
            $fullPreviewPath = self::findFileInDirectory($settings['images_dir'], $entry);
            $rows[] = [
                'file_name' => $entry,
                'local_path' => $path,
                'size_bytes' => filesize($path) ?: 0,
                'modified_at' => date('Y-m-d H:i:s', (int) filemtime($path)),
                'has_thumbnail' => $thumbPath !== null,
                'is_previewable' => $fullPreviewPath !== null,
                'preview_url' => self::publicUrl($entry, false),
                'preview_thumb_url' => self::publicUrl($entry, true),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['modified_at'], (string) $a['modified_at']));

        return $rows;
    }

    /**
     * @return array{ok: bool, message: string, file_name?: string}
     */
    public static function saveProcessedUpload(string $tmpPath, string $originalName = 'linked.jpg'): ?string
    {
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return null;
        }

        $mime = self::detectMime($tmpPath);
        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        $settings = self::settings();
        $directory = $settings['images_dir'] . DIRECTORY_SEPARATOR . '_processed';
        if (!self::ensureDirectory($directory)) {
            return null;
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $extension = 'jpg';
        }

        $dest = $directory . DIRECTORY_SEPARATOR . ('proc_' . bin2hex(random_bytes(8)) . '.' . $extension);
        if (is_uploaded_file($tmpPath)) {
            if (!@move_uploaded_file($tmpPath, $dest)) {
                return null;
            }
        } elseif (!@copy($tmpPath, $dest)) {
            return null;
        }

        return $dest;
    }

    /**
     * Composite a product photo onto the Jawish card template with fixed slots
     * for name, packaging, and a material-code barcode.
     */
    public static function renderImageWithDetailsBanner(
        string $sourcePath,
        string $line1,
        string $line2,
        ?string $barcodeValue = null
    ): ?string {
        if (!is_file($sourcePath) || !function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            return null;
        }

        $font = self::resolveDetailsFontPath();
        $templatePath = self::resolveCardTemplatePath();
        if ($font === null || $templatePath === null) {
            return null;
        }

        $line1 = self::normalizeProductBannerLine($line1);
        $line2 = trim($line2);
        $productParts = $line1 !== '' ? self::splitProductBannerLine($line1) : null;
        $productName = $productParts['name'] ?? ($line1 !== '' ? $line1 : '');
        $materialCode = trim((string) ($barcodeValue ?? ''));
        if ($materialCode === '' && $productParts !== null) {
            $materialCode = $productParts['code'];
        }

        $template = self::loadGdImage($templatePath);
        if ($template === false) {
            return null;
        }

        $canvasW = imagesx($template);
        $canvasH = imagesy($template);
        if ($canvasW <= 0 || $canvasH <= 0) {
            return null;
        }

        $canvas = imagecreatetruecolor($canvasW, $canvasH);
        if ($canvas === false) {
            return null;
        }
        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);
        imagecopy($canvas, $template, 0, 0, 0, 0, $canvasW, $canvasH);

        $photo = self::cardTemplateSlot('photo');
        $product = self::loadGdImage($sourcePath);
        if ($product !== false) {
            self::coverFitImageIntoRect(
                $canvas,
                $product,
                (int) $photo['x'],
                (int) $photo['y'],
                (int) $photo['w'],
                (int) $photo['h']
            );
            // Restore branding chrome that overlaps the photo slot (red flourish, etc.).
            self::restoreTemplateNonWhiteOverRect($canvas, $template, $photo);
        }

        $nameSlot = self::cardTemplateSlot('name');
        self::fillRoundedSlot(
            $canvas,
            (int) $nameSlot['x'],
            (int) $nameSlot['y'],
            (int) $nameSlot['w'],
            (int) $nameSlot['h'],
            253,
            251,
            251,
            18
        );
        if ($productName !== '') {
            self::drawClippedTextInSlot(
                $canvas,
                $font,
                $productName,
                $nameSlot,
                18.0,
                28.0,
                28,
                28,
                30,
                true,
                14,
                10
            );
        }

        $packSlot = self::cardTemplateSlot('pack');
        self::fillRoundedSlot(
            $canvas,
            (int) $packSlot['x'],
            (int) $packSlot['y'],
            (int) $packSlot['w'],
            (int) $packSlot['h'],
            99,
            99,
            99,
            14
        );
        if ($line2 !== '') {
            $packText = $line2;
            if (preg_match('/^التعبئة\s*:\s*(.+)$/u', $line2, $m) === 1) {
                $packText = trim((string) $m[1]);
            }
            // Leave room for the packaging icon drawn on the template's right edge.
            $packTextSlot = [
                'x' => (int) $packSlot['x'] + 12,
                'y' => (int) $packSlot['y'] + 8,
                'w' => max(80, (int) $packSlot['w'] - 70),
                'h' => max(20, (int) $packSlot['h'] - 16),
            ];
            self::drawClippedTextInSlot(
                $canvas,
                $font,
                $packText,
                $packTextSlot,
                14.0,
                20.0,
                245,
                245,
                247,
                false,
                8,
                6
            );
        }
        // Re-stamp packaging icon area from template so the box glyph stays visible.
        self::restoreTemplateRegion(
            $canvas,
            $template,
            (int) $packSlot['x'] + (int) $packSlot['w'] - 58,
            (int) $packSlot['y'],
            58,
            (int) $packSlot['h']
        );

        if ($materialCode !== '') {
            self::drawMaterialBarcode($canvas, $font, $materialCode, self::cardTemplateSlot('barcode'));
        }

        $settings = self::settings();
        $directory = $settings['images_dir'] . DIRECTORY_SEPARATOR . '_processed';
        if (!self::ensureDirectory($directory)) {
            return null;
        }

        $dest = $directory . DIRECTORY_SEPARATOR . ('detail_' . bin2hex(random_bytes(8)) . '.jpg');
        $saved = imagejpeg($canvas, $dest, 92);

        return $saved ? $dest : null;
    }

    public static function resolveCardTemplatePath(): ?string
    {
        $configured = trim((string) (Config::get('PORTAL_MATERIAL_CARD_TEMPLATE') ?? ''));
        if ($configured !== '' && is_file($configured) && is_readable($configured)) {
            return $configured;
        }

        $default = dirname(__DIR__, 2) . '/resources/branding/material-card-template.png';
        if (is_file($default) && is_readable($default)) {
            return $default;
        }

        return null;
    }

    /** @return array{x: int, y: int, w: int, h: int} */
    private static function cardTemplateSlot(string $name): array
    {
        return match ($name) {
            // Large white photo plane to the right of the red flourish.
            'photo' => ['x' => 736, 'y' => 12, 'w' => 744, 'h' => 458],
            // White product-name capsule.
            'name' => ['x' => 732, 'y' => 495, 'w' => 330, 'h' => 91],
            // Grey packaging capsule (icon sits on the right inside the capsule).
            'pack' => ['x' => 732, 'y' => 595, 'w' => 330, 'h' => 59],
            // Barcode plate to the right of name/pack, before the brand mark.
            'barcode' => ['x' => 1085, 'y' => 500, 'w' => 210, 'h' => 150],
            default => ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0],
        };
    }

    private static function coverFitImageIntoRect(
        \GdImage $canvas,
        \GdImage $source,
        int $destX,
        int $destY,
        int $destW,
        int $destH
    ): void {
        if ($destW <= 0 || $destH <= 0) {
            return;
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        if ($srcW <= 0 || $srcH <= 0) {
            return;
        }

        $scale = max($destW / $srcW, $destH / $srcH);
        $scaledW = max(1, (int) round($srcW * $scale));
        $scaledH = max(1, (int) round($srcH * $scale));
        $offsetX = (int) floor(($scaledW - $destW) / 2);
        $offsetY = (int) floor(($scaledH - $destH) / 2);

        $scaled = imagecreatetruecolor($scaledW, $scaledH);
        if ($scaled === false) {
            return;
        }
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $transparent = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefilledrectangle($scaled, 0, 0, $scaledW, $scaledH, $transparent);
        imagealphablending($scaled, true);
        imagecopyresampled($scaled, $source, 0, 0, 0, 0, $scaledW, $scaledH, $srcW, $srcH);

        imagecopy($canvas, $scaled, $destX, $destY, $offsetX, $offsetY, $destW, $destH);
    }

    /** @param array{x: int, y: int, w: int, h: int} $rect */
    private static function restoreTemplateNonWhiteOverRect(\GdImage $canvas, \GdImage $template, array $rect): void
    {
        $x0 = max(0, (int) $rect['x']);
        $y0 = max(0, (int) $rect['y']);
        $x1 = min(imagesx($template) - 1, $x0 + (int) $rect['w'] - 1);
        $y1 = min(imagesy($template) - 1, $y0 + (int) $rect['h'] - 1);

        for ($y = $y0; $y <= $y1; $y++) {
            for ($x = $x0; $x <= $x1; $x++) {
                $color = imagecolorat($template, $x, $y);
                $r = ($color >> 16) & 0xFF;
                $g = ($color >> 8) & 0xFF;
                $b = $color & 0xFF;
                // Keep branding (red flourish / non-white chrome) above the photo.
                if ($r > 245 && $g > 245 && $b > 245) {
                    continue;
                }
                imagesetpixel($canvas, $x, $y, $color);
            }
        }
    }

    private static function restoreTemplateRegion(
        \GdImage $canvas,
        \GdImage $template,
        int $x,
        int $y,
        int $w,
        int $h
    ): void {
        if ($w <= 0 || $h <= 0) {
            return;
        }
        imagecopy($canvas, $template, $x, $y, $x, $y, $w, $h);
    }

    private static function fillRoundedSlot(
        \GdImage $canvas,
        int $x,
        int $y,
        int $w,
        int $h,
        int $r,
        int $g,
        int $b,
        int $radius
    ): void {
        if ($w <= 0 || $h <= 0) {
            return;
        }

        $color = imagecolorallocate($canvas, $r, $g, $b);
        $radius = max(0, min($radius, (int) floor(min($w, $h) / 2)));
        imagefilledrectangle($canvas, $x + $radius, $y, $x + $w - $radius - 1, $y + $h - 1, $color);
        imagefilledrectangle($canvas, $x, $y + $radius, $x + $w - 1, $y + $h - $radius - 1, $color);
        if ($radius > 0 && function_exists('imagefilledellipse')) {
            imagefilledellipse($canvas, $x + $radius, $y + $radius, $radius * 2, $radius * 2, $color);
            imagefilledellipse($canvas, $x + $w - $radius - 1, $y + $radius, $radius * 2, $radius * 2, $color);
            imagefilledellipse($canvas, $x + $radius, $y + $h - $radius - 1, $radius * 2, $radius * 2, $color);
            imagefilledellipse($canvas, $x + $w - $radius - 1, $y + $h - $radius - 1, $radius * 2, $radius * 2, $color);
        }
    }

    /**
     * @param array{x: int, y: int, w: int, h: int} $slot
     */
    private static function drawClippedTextInSlot(
        \GdImage $canvas,
        string $font,
        string $text,
        array $slot,
        float $minSize,
        float $maxSize,
        int $red,
        int $green,
        int $blue,
        bool $bold,
        int $padX,
        int $padY
    ): void {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $innerX = (int) $slot['x'] + $padX;
        $innerY = (int) $slot['y'] + $padY;
        $innerW = max(20, (int) $slot['w'] - ($padX * 2));
        $innerH = max(16, (int) $slot['h'] - ($padY * 2));

        $fontSize = $maxSize;
        $lines = [];
        while ($fontSize >= $minSize) {
            $lines = self::wrapTtfTextLines($font, $fontSize, $text, $innerW);
            if ($lines === []) {
                $lines = [$text];
            }
            // Cap to two lines so text never escapes the fixed slot.
            if (count($lines) > 2) {
                $lines = array_slice($lines, 0, 2);
                $last = $lines[1];
                while ($last !== '' && self::ttfLineWidth($font, $fontSize, ArabicGdText::shape($last . '…')) > $innerW) {
                    $last = function_exists('mb_substr')
                        ? mb_substr($last, 0, -1, 'UTF-8')
                        : substr($last, 0, -1);
                }
                $lines[1] = $last === '' ? '…' : ($last . '…');
            }
            $lineStep = (int) round($fontSize * 1.2);
            $blockH = max(1, count($lines)) * $lineStep;
            $widest = 0;
            foreach ($lines as $line) {
                $widest = max($widest, self::ttfLineWidth($font, $fontSize, ArabicGdText::shape($line)));
            }
            if ($blockH <= $innerH && $widest <= $innerW) {
                break;
            }
            $fontSize -= 1.0;
        }

        if ($lines === []) {
            return;
        }

        $lineStep = (int) round($fontSize * 1.2);
        $blockH = count($lines) * $lineStep;
        $startY = $innerY + (int) floor(max(0, $innerH - $blockH) / 2);
        $right = $innerX + $innerW;

        foreach ($lines as $index => $line) {
            $baseline = $startY + (int) $fontSize + ($index * $lineStep);
            self::drawBannerColoredTextRight(
                $canvas,
                $font,
                $fontSize,
                $line,
                $innerX,
                $right,
                $baseline,
                $red,
                $green,
                $blue,
                $bold,
                true
            );
        }
    }

    /** @param array{x: int, y: int, w: int, h: int} $slot */
    private static function drawMaterialBarcode(\GdImage $canvas, string $font, string $code, array $slot): void
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/[^0-9A-Z\-. $\/+%]/', '', $code) ?? '';
        if ($code === '') {
            return;
        }

        $x = (int) $slot['x'];
        $y = (int) $slot['y'];
        $w = (int) $slot['w'];
        $h = (int) $slot['h'];
        if ($w < 40 || $h < 40) {
            return;
        }

        self::fillRoundedSlot($canvas, $x, $y, $w, $h, 255, 255, 255, 10);

        $pattern = self::code39Pattern('*' . $code . '*');
        if ($pattern === '') {
            return;
        }

        $barAreaX = $x + 10;
        $barAreaY = $y + 10;
        $barAreaW = $w - 20;
        $barAreaH = max(24, $h - 42);
        $moduleCount = strlen($pattern);
        $moduleW = max(1.0, $barAreaW / max(1, $moduleCount));
        $black = imagecolorallocate($canvas, 20, 20, 20);
        $cursor = 0.0;
        for ($i = 0; $i < $moduleCount; $i++) {
            $modW = $moduleW;
            if ($pattern[$i] === '1') {
                $drawX = (int) floor($barAreaX + $cursor);
                $drawW = max(1, (int) ceil($moduleW));
                imagefilledrectangle(
                    $canvas,
                    $drawX,
                    $barAreaY,
                    min($barAreaX + $barAreaW - 1, $drawX + $drawW - 1),
                    $barAreaY + $barAreaH - 1,
                    $black
                );
            }
            $cursor += $modW;
        }

        $labelSize = (float) max(11, min(16, (int) floor($w / 14)));
        $label = $code;
        $shaped = $label;
        $labelWidth = self::ttfLineWidth($font, $labelSize, $shaped);
        while ($labelWidth > $w - 16 && strlen($label) > 3) {
            $label = substr($label, 0, -1);
            $shaped = $label . '…';
            $labelWidth = self::ttfLineWidth($font, $labelSize, $shaped);
        }
        $labelX = $x + (int) floor(($w - $labelWidth) / 2);
        $labelY = $y + $h - 12;
        imagettftext($canvas, (int) $labelSize, 0, max($x + 4, $labelX), $labelY, $black, $font, $shaped);
    }

    private static function code39Pattern(string $text): string
    {
        $map = [
            '0' => '000110100',
            '1' => '100100001',
            '2' => '001100001',
            '3' => '101100000',
            '4' => '000110001',
            '5' => '100110000',
            '6' => '001110000',
            '7' => '000100101',
            '8' => '100100100',
            '9' => '001100100',
            'A' => '100001001',
            'B' => '001001001',
            'C' => '101001000',
            'D' => '000011001',
            'E' => '100011000',
            'F' => '001011000',
            'G' => '000001101',
            'H' => '100001100',
            'I' => '001001100',
            'J' => '000011100',
            'K' => '100000011',
            'L' => '001000011',
            'M' => '101000010',
            'N' => '000010011',
            'O' => '100010010',
            'P' => '001010010',
            'Q' => '000000111',
            'R' => '100000110',
            'S' => '001000110',
            'T' => '000010110',
            'U' => '110000001',
            'V' => '011000001',
            'W' => '111000000',
            'X' => '010010001',
            'Y' => '110010000',
            'Z' => '011010000',
            '-' => '010000101',
            '.' => '110000100',
            ' ' => '011000100',
            '$' => '010101000',
            '/' => '010100010',
            '+' => '010001010',
            '%' => '000101010',
            '*' => '010010100',
        ];

        $parts = [];
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if (!isset($map[$ch])) {
                continue;
            }
            // Each Code39 symbol is 9 modules; narrow=1 / wide=2 encoded as 0/1 then gap.
            $symbol = $map[$ch];
            $encoded = '';
            for ($b = 0; $b < 9; $b++) {
                $isBar = ($b % 2) === 0;
                $wide = $symbol[$b] === '1';
                $encoded .= str_repeat($isBar ? '1' : '0', $wide ? 2 : 1);
            }
            $parts[] = $encoded;
        }

        // Narrow white gap between symbols.
        return implode('0', $parts);
    }

    public static function normalizeProductBannerLine(string $line): string
    {
        $line = trim($line);
        if ($line === '') {
            return '';
        }

        $line = preg_replace('/\s*[—–−]\s*/u', ' - ', $line) ?? $line;
        $line = preg_replace('/\s+-\s+/u', ' - ', $line) ?? $line;

        return trim($line);
    }

    /** @return array{code: string, name: string}|null */
    public static function splitProductBannerLine(string $line): ?array
    {
        $line = self::normalizeProductBannerLine($line);
        if ($line === '' || !str_contains($line, ' - ')) {
            return null;
        }

        [$name, $code] = explode(' - ', $line, 2);
        $name = trim($name);
        $code = trim($code);
        if ($name === '' || $code === '') {
            return null;
        }

        return ['name' => $name, 'code' => $code];
    }

    /** @return array{name: string, phone: string} */
    public static function detailsBannerBranding(): array
    {
        $company = PortalSettingsService::companySettings();
        $name = trim((string) ($company['company_name'] ?? ''));
        if ($name === '') {
            $name = 'جاويش للتجارة';
        }

        $phone = trim((string) ($company['company_mobile'] ?? ''));
        if ($phone === '') {
            $phone = trim((string) ($company['company_phone'] ?? ''));
        }

        return [
            'name' => $name,
            'phone' => $phone,
        ];
    }

    /** @param array{name: string, phone: string} $branding */
    private static function detailsBannerBrandColumnWidth(
        string $font,
        float $brandNameSize,
        float $brandPhoneSize,
        array $branding,
        int $canvasWidth
    ): int {
        if ($branding['name'] === '' && $branding['phone'] === '') {
            return 0;
        }

        $phoneWidth = $branding['phone'] !== ''
            ? self::ttfLineWidth($font, $brandPhoneSize, $branding['phone'])
            : 0.0;
        $maxColumn = (int) max(120, round($canvasWidth * 0.4));
        $minForPhone = (int) ceil($phoneWidth) + 12;

        return min($maxColumn, max(120, $minForPhone));
    }

    /**
     * @param array{name: string, phone: string} $branding
     * @return list<string>
     */
    private static function detailsBannerBrandNameLines(
        string $font,
        float $brandNameSize,
        array $branding,
        int $brandColumnWidth
    ): array {
        if ($branding['name'] === '' || $brandColumnWidth <= 0) {
            return [];
        }

        $wrapWidth = max(72, $brandColumnWidth - 8);
        $lines = self::wrapTtfTextLines($font, $brandNameSize, $branding['name'], $wrapWidth);

        return $lines !== [] ? $lines : [$branding['name']];
    }

    /**
     * @param array{name: string, phone: string} $branding
     * @param list<string> $brandNameLines
     */
    private static function detailsBannerBrandBlockHeight(
        array $branding,
        float $brandNameSize,
        float $brandPhoneSize,
        array $brandNameLines
    ): int {
        if ($branding['name'] === '' && $branding['phone'] === '') {
            return 0;
        }

        $lineHeight = (int) round($brandNameSize * 1.2);
        $height = $brandNameLines !== []
            ? $lineHeight * count($brandNameLines)
            : ($branding['name'] !== '' ? (int) round($brandNameSize * 1.35) : 0);

        if ($branding['phone'] !== '') {
            if ($height > 0) {
                $height += (int) round($brandNameSize * 0.2);
            }
            $height += (int) round($brandPhoneSize * 1.35);
        }

        return $height;
    }

    /**
     * @param array{name: string, phone: string} $branding
     * @param list<string> $brandNameLines
     */
    private static function drawDetailsBannerBranding(
        \GdImage $canvas,
        string $font,
        array $branding,
        float $brandNameSize,
        float $brandPhoneSize,
        int $paddingLeft,
        int $baseY,
        int $brandColumnWidth,
        array $brandNameLines
    ): void {
        $baseline = $baseY + (int) $brandNameSize;
        $lineStep = (int) round($brandNameSize * 1.2);

        if ($brandNameLines !== []) {
            foreach ($brandNameLines as $nameLine) {
                self::drawBannerColoredText(
                    $canvas,
                    $font,
                    $brandNameSize,
                    $nameLine,
                    $paddingLeft,
                    $baseline,
                    232,
                    62,
                    72,
                    true,
                    true
                );
                $baseline += $lineStep;
            }
        } elseif ($branding['name'] !== '') {
            self::drawBannerColoredText(
                $canvas,
                $font,
                $brandNameSize,
                $branding['name'],
                $paddingLeft,
                $baseline,
                232,
                62,
                72,
                true,
                true
            );
            $baseline += $lineStep;
        }

        if ($branding['phone'] !== '') {
            if ($brandNameLines !== [] || $branding['name'] !== '') {
                $baseline += (int) round($brandNameSize * 0.15);
            }
            self::drawBannerColoredText(
                $canvas,
                $font,
                $brandPhoneSize,
                $branding['phone'],
                $paddingLeft,
                $baseline + (int) $brandPhoneSize,
                210,
                58,
                66,
                false,
                false
            );
        }
    }

    private static function bannerFramePaddingX(float $fontSize): int
    {
        return (int) max(6, round($fontSize * 0.34));
    }

    private static function bannerFramePaddingY(float $fontSize): int
    {
        return (int) max(3, round($fontSize * 0.18));
    }

    private static function bannerFramedTextWidth(string $font, float $fontSize, string $text, bool $shapeArabic): int
    {
        $drawText = $shapeArabic && ArabicGdText::containsArabic($text)
            ? ArabicGdText::shape($text)
            : $text;

        return self::ttfLineWidth($font, $fontSize, $drawText) + self::bannerFramePaddingX($fontSize) * 2;
    }

    private static function fillBannerRoundedRect(
        \GdImage $canvas,
        int $left,
        int $top,
        int $right,
        int $bottom,
        int $color
    ): void {
        if ($right <= $left || $bottom <= $top) {
            return;
        }

        $radius = (int) max(2, floor(($bottom - $top) / 2));
        imagefilledrectangle($canvas, $left + $radius, $top, $right - $radius, $bottom, $color);
        imagefilledellipse($canvas, $left + $radius, (int) floor(($top + $bottom) / 2), $radius * 2, $bottom - $top, $color);
        imagefilledellipse($canvas, $right - $radius, (int) floor(($top + $bottom) / 2), $radius * 2, $bottom - $top, $color);
    }

    private static function drawBannerSoftBadgeRight(
        \GdImage $canvas,
        string $font,
        float $fontSize,
        string $text,
        int $minX,
        int $maxRightX,
        int $baselineY
    ): int {
        return self::drawBannerFramedTextRight(
            $canvas,
            $font,
            $fontSize,
            $text,
            $minX,
            $maxRightX,
            $baselineY,
            52,
            54,
            60,
            255,
            255,
            255,
            216,
            25,
            33,
            false,
            true
        );
    }

    private static function drawBannerFramedTextRight(
        \GdImage $canvas,
        string $font,
        float $fontSize,
        string $text,
        int $minX,
        int $maxRightX,
        int $baselineY,
        int $fillRed,
        int $fillGreen,
        int $fillBlue,
        int $textRed,
        int $textGreen,
        int $textBlue,
        int $borderRed,
        int $borderGreen,
        int $borderBlue,
        bool $shapeArabic,
        bool $boldText = true
    ): int {
        $drawText = $shapeArabic && ArabicGdText::containsArabic($text)
            ? ArabicGdText::shape($text)
            : $text;
        if ($drawText === '') {
            return 0;
        }

        $textWidth = self::ttfLineWidth($font, $fontSize, $drawText);
        $padX = self::bannerFramePaddingX($fontSize);
        $padY = self::bannerFramePaddingY($fontSize);
        $frameWidth = $textWidth + $padX * 2;
        $frameHeight = (int) round($fontSize * 1.12) + $padY * 2;
        $frameRight = $maxRightX;
        $frameLeft = max($minX, $frameRight - $frameWidth);
        $frameTop = $baselineY - (int) round($fontSize * 0.82) - $padY;
        $frameBottom = $frameTop + $frameHeight;

        $fill = imagecolorallocate($canvas, $fillRed, $fillGreen, $fillBlue);
        $border = imagecolorallocate($canvas, $borderRed, $borderGreen, $borderBlue);
        self::fillBannerRoundedRect($canvas, $frameLeft, $frameTop, $frameRight, $frameBottom, $fill);
        if ($borderRed !== $fillRed || $borderGreen !== $fillGreen || $borderBlue !== $fillBlue) {
            imagerectangle($canvas, $frameLeft, $frameTop, $frameRight, $frameBottom, $border);
        }

        self::drawBannerColoredText(
            $canvas,
            $font,
            $fontSize,
            $text,
            $frameLeft + $padX,
            $baselineY,
            $textRed,
            $textGreen,
            $textBlue,
            $boldText,
            $shapeArabic
        );

        return $frameRight - $frameLeft;
    }

    private static function drawDetailsBannerHairline(\GdImage $canvas, int $x, int $y, int $width): void
    {
        $line = imagecolorallocate($canvas, 228, 62, 72);
        imageline($canvas, $x, $y, $x + $width - 1, $y, $line);
    }

    private static function drawDetailsBannerColumnDivider(\GdImage $canvas, int $x, int $topY, int $bottomY): void
    {
        $divider = imagecolorallocate($canvas, 70, 71, 76);
        imageline($canvas, $x, $topY, $x, $bottomY, $divider);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function bannerAccentRgb(): array
    {
        return [232, 62, 72];
    }

    private static function drawBannerTextLeft(
        \GdImage $canvas,
        string $font,
        float $fontSize,
        string $text,
        int $x,
        int $baselineY,
        bool $bold,
        bool $shapeArabic
    ): void {
        self::drawBannerColoredText(
            $canvas,
            $font,
            $fontSize,
            $text,
            $x,
            $baselineY,
            $bold ? 255 : 220,
            $bold ? 255 : 220,
            $bold ? 255 : 220,
            $bold,
            $shapeArabic
        );
    }

    private static function drawBannerColoredText(
        \GdImage $canvas,
        string $font,
        float $fontSize,
        string $text,
        int $x,
        int $baselineY,
        int $red,
        int $green,
        int $blue,
        bool $bold,
        bool $shapeArabic
    ): void {
        $drawText = $shapeArabic && ArabicGdText::containsArabic($text)
            ? ArabicGdText::shape($text)
            : $text;
        $color = imagecolorallocate($canvas, $red, $green, $blue);

        if ($bold) {
            imagettftext($canvas, (int) $fontSize, 0, $x + 1, $baselineY, $color, $font, $drawText);
        }
        imagettftext($canvas, (int) $fontSize, 0, $x, $baselineY, $color, $font, $drawText);
    }

    private static function drawBannerColoredTextRight(
        \GdImage $canvas,
        string $font,
        float $fontSize,
        string $text,
        int $minX,
        int $maxRightX,
        int $baselineY,
        int $red,
        int $green,
        int $blue,
        bool $bold,
        bool $shapeArabic
    ): void {
        $drawText = $shapeArabic && ArabicGdText::containsArabic($text)
            ? ArabicGdText::shape($text)
            : $text;
        $textWidth = self::ttfLineWidth($font, $fontSize, $drawText);
        $x = max($minX, $maxRightX - $textWidth);
        $color = imagecolorallocate($canvas, $red, $green, $blue);

        if ($bold) {
            imagettftext($canvas, (int) $fontSize, 0, $x + 1, $baselineY, $color, $font, $drawText);
        }
        imagettftext($canvas, (int) $fontSize, 0, $x, $baselineY, $color, $font, $drawText);
    }

    private static function fillDetailsBannerBackground(\GdImage $canvas, int $x, int $y, int $width, int $height): void
    {
        $fill = imagecolorallocate($canvas, 38, 39, 43);
        imagefilledrectangle($canvas, $x, $y, $x + $width - 1, $y + $height - 1, $fill);
    }

    /** @return array{label: string, value: string}|null */
    private static function splitPackagingBannerLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        if (preg_match('/^(التعبئة\s*:\s*)(.*)$/u', $line, $matches) === 1) {
            $value = trim((string) ($matches[2] ?? ''));
            if ($value === '') {
                return null;
            }

            return [
                'label' => 'التعبئة',
                'value' => $value,
            ];
        }

        return null;
    }

    private static function drawPackagingBannerLine(
        \GdImage $canvas,
        string $font,
        float $fontSize,
        string $line,
        int $minX,
        int $maxRightX,
        int $baselineY
    ): void {
        $parts = self::splitPackagingBannerLine($line);
        if ($parts === null) {
            self::drawBannerColoredTextRight(
                $canvas,
                $font,
                $fontSize,
                $line,
                $minX,
                $maxRightX,
                $baselineY,
                228,
                228,
                228,
                false,
                true
            );

            return;
        }

        $maxWidth = max(80, $maxRightX - $minX);
        $label = $parts['label'];
        $value = $parts['value'];
        $shapedLabel = ArabicGdText::shape($label);
        $shapedValue = ArabicGdText::shape($value);
        $labelWidth = self::ttfLineWidth($font, $fontSize, $shapedLabel);
        $valueWidth = self::ttfLineWidth($font, $fontSize, $shapedValue);
        $gap = 8;
        $lineWidth = $valueWidth + $gap + $labelWidth;

        if ($lineWidth > $maxWidth) {
            $availableForValue = max(40, $maxWidth - $labelWidth - $gap);
            $wrappedValue = self::wrapTtfTextLines($font, $fontSize, $value, $availableForValue);
            $shapedValue = ArabicGdText::shape($wrappedValue[0] ?? $value);
            $valueWidth = self::ttfLineWidth($font, $fontSize, $shapedValue);
            $lineWidth = $valueWidth + $gap + $labelWidth;
        }

        $labelColor = imagecolorallocate($canvas, 216, 25, 33);
        $valueColor = imagecolorallocate($canvas, 196, 198, 206);
        $valueRight = max($minX + $valueWidth, $maxRightX - $labelWidth - $gap);
        $valueX = $valueRight - $valueWidth;
        $labelX = $maxRightX - $labelWidth;

        imagettftext($canvas, (int) $fontSize, 0, $valueX, $baselineY, $valueColor, $font, $shapedValue);
        imagettftext($canvas, (int) $fontSize, 0, $labelX, $baselineY, $labelColor, $font, $shapedLabel . ' :');
    }

    private static function drawDetailsBannerTextLine(
        \GdImage $canvas,
        string $font,
        float $fontSize,
        string $line,
        int $paddingLeft,
        int $textRight,
        int $baselineY,
        bool $bold
    ): void {
        $shaped = ArabicGdText::shape($line);
        $textWidth = self::ttfLineWidth($font, $fontSize, $shaped);
        $x = max($paddingLeft, $textRight - (int) round($textWidth));
        $color = $bold
            ? imagecolorallocate($canvas, 255, 255, 255)
            : imagecolorallocate($canvas, 228, 228, 228);

        if ($bold) {
            imagettftext($canvas, (int) $fontSize, 0, $x + 1, $baselineY, $color, $font, $shaped);
        }
        imagettftext($canvas, (int) $fontSize, 0, $x, $baselineY, $color, $font, $shaped);
    }

    public static function canRenderDetailsBanner(): bool
    {
        return self::detailsBannerRequirements()['ok'];
    }

    public static function canProcessImageDetails(): bool
    {
        return self::canRenderDetailsBanner();
    }

    /**
     * @return array{
     *   ok: bool,
     *   gd: bool,
     *   freetype: bool,
     *   mbstring: bool,
     *   font_path: string|null,
     *   template_path: string|null,
     *   message: string
     * }
     */
    public static function detailsBannerRequirements(): array
    {
        $gd = function_exists('imagecreatetruecolor');
        $freetype = function_exists('imagettftext');
        $fontPath = self::resolveDetailsFontPath();
        $templatePath = self::resolveCardTemplatePath();

        $missing = [];
        if (!$gd) {
            $missing[] = 'امتداد GD (imagecreatetruecolor)';
        }
        if (!$freetype) {
            $missing[] = 'GD مع دعم FreeType (imagettftext) — فعّل php_gd2 مع freetype في php.ini';
        }
        if ($fontPath === null) {
            $missing[] = 'خط TrueType readable من PHP — انسخ tahoma.ttf إلى portal/storage/fonts/ أو عيّن PORTAL_DETAILS_FONT_PATH';
        }
        if ($templatePath === null) {
            $missing[] = 'ملف قالب البطاقة portal/resources/branding/material-card-template.png';
        }

        $message = $missing === []
            ? 'جاهز'
            : ('قالب صورة المادة يتطلب: ' . implode('، ', $missing) . '.');

        return [
            'ok' => $gd && $freetype && $fontPath !== null && $templatePath !== null,
            'gd' => $gd,
            'freetype' => $freetype,
            'mbstring' => function_exists('mb_strlen'),
            'font_path' => $fontPath,
            'template_path' => $templatePath,
            'message' => $message,
        ];
    }

    public static function resolveDetailsFontPath(): ?string
    {
        $configured = trim((string) (Config::get('PORTAL_DETAILS_FONT_PATH') ?? ''));
        if ($configured !== '' && is_file($configured) && is_readable($configured)) {
            return $configured;
        }

        $candidates = [];

        $storageFontsDir = rtrim(Config::storagePath(), '/\\') . DIRECTORY_SEPARATOR . 'fonts';
        foreach (['tahomabd.ttf', 'TahomaBd.ttf', 'tahoma.ttf', 'Tahoma.ttf', 'arialbd.ttf', 'arial.ttf', 'trado.ttf', 'DejaVuSans-Bold.ttf', 'DejaVuSans.ttf'] as $fileName) {
            $candidates[] = $storageFontsDir . DIRECTORY_SEPARATOR . $fileName;
        }

        $windowsFonts = self::windowsFontsDirectory();
        if ($windowsFonts !== null) {
            foreach (['tahomabd.ttf', 'TAHOMABD.TTF', 'tahoma.ttf', 'TAHOMA.TTF', 'arialbd.ttf', 'arial.ttf', 'trado.ttf'] as $fileName) {
                $candidates[] = $windowsFonts . DIRECTORY_SEPARATOR . $fileName;
            }
        }

        $candidates = array_merge($candidates, [
            'C:\\Windows\\Fonts\\tahomabd.ttf',
            'C:\\Windows\\Fonts\\TAHOMABD.TTF',
            'C:\\Windows\\Fonts\\tahoma.ttf',
            'C:\\Windows\\Fonts\\TAHOMA.TTF',
            'C:\\Windows\\Fonts\\arialbd.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
            'C:\\Windows\\Fonts\\trado.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        ]);

        foreach ($candidates as $path) {
            if ($path !== '' && is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        if ($storageFontsDir !== '' && is_dir($storageFontsDir)) {
            $matches = glob($storageFontsDir . DIRECTORY_SEPARATOR . '*.ttf') ?: [];
            foreach ($matches as $path) {
                if (is_readable($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private static function windowsFontsDirectory(): ?string
    {
        $windir = trim((string) (getenv('WINDIR') ?: getenv('SystemRoot') ?: ''));
        if ($windir === '') {
            return null;
        }

        $dir = rtrim(str_replace('/', '\\', $windir), '\\') . '\\Fonts';

        return is_dir($dir) ? $dir : null;
    }

    /** @return \GdImage|false */
    public static function loadGdImagePublic(string $sourcePath)
    {
        return self::loadGdImage($sourcePath);
    }

    /** @return list<string> */
    public static function wrapTtfTextLinesPublic(string $font, float $fontSize, string $text, int $maxWidth): array
    {
        return self::wrapTtfTextLines($font, $fontSize, $text, $maxWidth);
    }

    /** @return list<string> */
    private static function wrapTtfTextLines(string $font, float $fontSize, string $text, int $maxWidth): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $words = preg_split('/\s+/u', $text) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            $width = self::ttfLineWidth($font, $fontSize, ArabicGdText::shape($candidate));
            if ($width <= $maxWidth || $current === '') {
                $current = $candidate;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private static function ttfLineWidth(string $font, float $fontSize, string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $box = imagettfbbox($fontSize, 0, $font, $text) ?: [0, 0, 0, 0, 0, 0, 0, 0];

        return (int) abs($box[2] - $box[0]);
    }

    /** @return \GdImage|false */
    private static function loadGdImage(string $sourcePath)
    {
        $mime = self::detectMime($sourcePath);

        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default => false,
        };
    }

    public static function deleteTempProcessedFile(string $path): void
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || !str_contains($path, '/_processed/')) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return array{ok: bool, message: string, file_name?: string}
     */
    public static function copyLocalFromSource(string $sourcePath, string $targetFileName): array
    {
        if (!is_file($sourcePath)) {
            return ['ok' => false, 'message' => 'الصورة المصدر غير موجودة على الموقع.'];
        }

        $settings = self::settings();
        $targetFileName = self::sanitizeFileName($targetFileName);
        if ($targetFileName === '' || !self::isAllowedFileName($targetFileName)) {
            return ['ok' => false, 'message' => 'اسم الملف المستهدف غير صالح.'];
        }

        $targetPath = self::safeJoin($settings['images_dir'], $targetFileName);
        $thumbPath = self::safeJoin($settings['thumbnails_dir'], $targetFileName);
        if ($targetPath === null || $thumbPath === null) {
            return ['ok' => false, 'message' => 'مسار الملف غير آمن.'];
        }

        if (!self::ensureDirectory($settings['images_dir']) || !self::ensureDirectory($settings['thumbnails_dir'])) {
            return ['ok' => false, 'message' => 'تعذر إنشاء مجلدات التخزين.'];
        }

        $sourceReal = realpath($sourcePath);
        $targetReal = realpath($targetPath);
        if ($sourceReal !== false && $targetReal !== false && $sourceReal === $targetReal) {
            if (!self::generateThumbnail($targetPath, $thumbPath)) {
                @copy($targetPath, $thumbPath);
            }

            return ['ok' => true, 'message' => 'تم', 'file_name' => $targetFileName];
        }

        if (is_file($targetPath) && ($sourceReal === false || $targetReal === false || $sourceReal !== $targetReal)) {
            @unlink($targetPath);
        }

        if (!@copy($sourcePath, $targetPath)) {
            return ['ok' => false, 'message' => 'تعذر نسخ الصورة محلياً.'];
        }

        if (!self::generateThumbnail($targetPath, $thumbPath)) {
            @copy($targetPath, $thumbPath);
        }

        return ['ok' => true, 'message' => 'تم', 'file_name' => $targetFileName];
    }

    public static function deleteLocalFile(string $fileName): void
    {
        $fileName = basename(str_replace('\\', '/', trim($fileName)));
        if ($fileName === '' || str_contains($fileName, '..')) {
            return;
        }

        foreach ([false, true] as $thumb) {
            $path = self::resolveLocalPath($fileName, $thumb);
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }
    }

    public static function imageGuidUrl(string $imageGuid, bool $thumb = true): string
    {
        $imageGuid = trim($imageGuid);
        if ($imageGuid === '') {
            return '';
        }

        return '/api/image.php?id=' . rawurlencode($imageGuid) . ($thumb ? '&thumb=1' : '&thumb=0');
    }

    public static function publicUrl(string $fileName, bool $thumb = true): string
    {
        return '/media/material.php?file=' . rawurlencode(self::lookupFileName($fileName))
            . ($thumb ? '&thumb=1' : '&thumb=0');
    }

    /**
     * Dashboard preview URLs from the site images folder only (/media/material.php).
     *
     * @return array{
     *   preview_url: string,
     *   preview_full_url: string,
     *   stored_file_name: string,
     *   has_local: bool,
     *   local_path: string
     * }
     */
    public static function resolveSitePreviewUrls(string $imageGuid, string $fileName): array
    {
        $empty = [
            'preview_url' => '',
            'preview_full_url' => '',
            'stored_file_name' => '',
            'has_local' => false,
            'local_path' => '',
        ];

        $seen = [];
        $candidates = [];
        $addCandidate = static function (string $value) use (&$candidates, &$seen): void {
            foreach (self::fileNameCandidates($value) as $candidate) {
                if ($candidate === '' || isset($seen[$candidate])) {
                    continue;
                }
                $seen[$candidate] = true;
                $candidates[] = $candidate;
            }
        };

        $addCandidate($fileName);

        $imageGuid = strtolower(trim($imageGuid));
        if ($imageGuid !== '') {
            foreach (['.jpg', '.jpeg', '.png', '.webp'] as $extension) {
                $addCandidate($imageGuid . $extension);
            }

            $queuePath = MaterialImageSyncService::resolveLocalPathByAmineGuid($imageGuid, false, false);
            if ($queuePath !== null && is_file($queuePath)) {
                $addCandidate(basename($queuePath));
            }

            $resolvedPath = self::resolvePathForGuid($imageGuid, false, true);
            if ($resolvedPath === null) {
                $resolvedPath = self::resolvePathForGuid($imageGuid, false, false);
            }
            if ($resolvedPath !== null && is_file($resolvedPath)) {
                $addCandidate(basename($resolvedPath));
            }

            foreach (self::fileNamesFromAmineApi($imageGuid) as $candidate) {
                $addCandidate($candidate);
            }
        }

        foreach ($candidates as $candidate) {
            $localPath = self::resolveLocalPath($candidate, false);
            if ($localPath === null || !is_file($localPath)) {
                continue;
            }

            $stored = self::lookupFileName($candidate);
            if ($stored === '') {
                $stored = basename($localPath);
            }

            return [
                'preview_url' => self::publicUrl($stored, true),
                'preview_full_url' => self::publicUrl($stored, false),
                'stored_file_name' => $stored,
                'has_local' => true,
                'local_path' => $localPath,
            ];
        }

        return $empty;
    }

    public static function resolveLocalSourcePath(string $imageGuid, string $fileName): ?string
    {
        $preview = self::resolveSitePreviewUrls($imageGuid, $fileName);
        $path = trim((string) ($preview['local_path'] ?? ''));

        return ($path !== '' && is_file($path)) ? $path : null;
    }

    public static function mimeForPath(string $path): string
    {
        return self::detectMime($path);
    }

    public static function resolveLocalPath(string $fileName, bool $thumb = false): ?string
    {
        $settings = self::settings();
        $directory = $thumb ? $settings['thumbnails_dir'] : $settings['images_dir'];

        foreach (self::fileNameCandidates($fileName) as $candidate) {
            $path = self::findFileInDirectory($directory, $candidate);
            if ($path !== null) {
                return $path;
            }
        }

        if ($thumb) {
            return self::resolveLocalPath($fileName, false);
        }

        return null;
    }

    public static function resolvePathForGuid(string $imageGuid, bool $thumb = false, bool $localOnly = false): ?string
    {
        $imageGuid = trim($imageGuid);
        if ($imageGuid === '') {
            return null;
        }

        $fromQueue = MaterialImageSyncService::resolveLocalPathByAmineGuid($imageGuid, $thumb, !$localOnly);
        if ($fromQueue !== null) {
            return $fromQueue;
        }

        if ($localOnly) {
            return null;
        }

        foreach (self::fileNamesFromAmineApi($imageGuid) as $fileName) {
            $path = self::resolveLocalPath($fileName, $thumb);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   ok: bool,
     *   message: string,
     *   items: list<array<string, mixed>>,
     *   page: int,
     *   page_size: int,
     *   total_count: int|null,
     *   has_more: bool
     * }
     */
    public static function browseMaterials(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = max(1, min(48, (int) ($filters['page_size'] ?? 24)));
        $localStatus = (string) ($filters['local_status'] ?? 'all');
        if (!in_array($localStatus, ['all', 'on_site', 'missing'], true)) {
            $localStatus = 'all';
        }

        $apiQuery = self::buildMaterialsApiQuery($filters);
        if ($localStatus === 'all') {
            $apiQuery['page'] = $page;
            $apiQuery['pageSize'] = $pageSize;

            try {
                $response = ApiClient::get('/api/materials', $apiQuery);
            } catch (Throwable $exception) {
                return self::browseError('تعذر الاتصال بـ API المواد: ' . $exception->getMessage());
            }

            if (!($response['ok'] ?? false)) {
                return self::browseError('تعذر جلب المواد من API (رمز ' . (int) ($response['status'] ?? 0) . ').');
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $rows = is_array($data['items'] ?? null) ? $data['items'] : [];
            $totalCount = max(0, (int) ($data['totalCount'] ?? $data['TotalCount'] ?? 0));
            $items = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $items[] = self::mapBrowseRow($row);
            }

            return [
                'ok' => true,
                'message' => '',
                'items' => $items,
                'page' => $page,
                'page_size' => $pageSize,
                'total_count' => $totalCount,
                'has_more' => ($page * $pageSize) < $totalCount,
            ];
        }

        return self::browseMaterialsWithLocalFilter($apiQuery, $localStatus, $page, $pageSize);
    }

    /** @return array{local_count: int, thumbnail_count: int} */
    public static function stats(): array
    {
        $settings = self::settings();

        return [
            'local_count' => self::countListableFilesInDirectory($settings['images_dir']),
            'thumbnail_count' => self::countListableFilesInDirectory($settings['thumbnails_dir']),
        ];
    }

    /**
     * @param array<string, mixed> $file
     * @return array{ok: bool, message: string, file_name?: string, replaced?: bool}
     */
    private static function uploadOne(array $file, string $imagesDir, string $thumbnailsDir): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => self::uploadErrorMessage($error)];
        }

        return self::uploadOneFromTempPath(
            (string) ($file['tmp_name'] ?? ''),
            (string) ($file['name'] ?? ''),
            (int) ($file['size'] ?? 0),
            $imagesDir,
            $thumbnailsDir,
            true
        );
    }

    /**
     * @return array{ok: bool, message: string, file_name?: string, replaced?: bool, renamed?: bool, requested_name?: string}
     */
    private static function uploadOneFromTempPath(
        string $tmpPath,
        string $originalName,
        int $size,
        string $imagesDir,
        string $thumbnailsDir,
        bool $fromHttpUpload
    ): array {
        if ($tmpPath === '' || !is_file($tmpPath) || !is_readable($tmpPath)) {
            return ['ok' => false, 'message' => $fromHttpUpload
                ? 'ملف غير صالح (لم يصل الملف إلى PHP — تحقق من upload_tmp_dir وصلاحيات IIS).'
                : 'ملف غير صالح.'];
        }

        if ($size <= 0) {
            $size = (int) (filesize($tmpPath) ?: 0);
        }
        if ($size <= 0 || $size > self::MAX_BYTES) {
            if (!$fromHttpUpload) {
                @unlink($tmpPath);
            }

            return ['ok' => false, 'message' => 'الحجم يجب أن يكون أقل من 10 ميجابايت.'];
        }

        $requestedName = self::sanitizeFileName($originalName);
        if ($requestedName === '' || !self::isAllowedFileName($requestedName)) {
            if (!$fromHttpUpload) {
                @unlink($tmpPath);
            }

            return ['ok' => false, 'message' => 'اسم الملف أو الامتداد غير مدعوم.'];
        }

        if (!self::ensureDirectory($imagesDir) || !is_writable($imagesDir)) {
            if (!$fromHttpUpload) {
                @unlink($tmpPath);
            }

            return ['ok' => false, 'message' => 'مجلد الصور غير قابل للكتابة — راجع صلاحيات: ' . $imagesDir];
        }
        if (!self::ensureDirectory($thumbnailsDir) || !is_writable($thumbnailsDir)) {
            if (!$fromHttpUpload) {
                @unlink($tmpPath);
            }

            return ['ok' => false, 'message' => 'مجلد المصغّرات غير قابل للكتابة — راجع صلاحيات: ' . $thumbnailsDir];
        }

        $idempotent = self::resolveIdempotentUpload($tmpPath, $requestedName, $imagesDir, $thumbnailsDir, $fromHttpUpload);
        if ($idempotent !== null) {
            return $idempotent;
        }

        $fileName = self::availableFileName($imagesDir, $requestedName);
        $renamed = strcasecmp($fileName, $requestedName) !== 0;

        $targetPath = self::safeJoin($imagesDir, $fileName);
        $thumbPath = self::safeJoin($thumbnailsDir, $fileName);
        if ($targetPath === null || $thumbPath === null) {
            if (!$fromHttpUpload) {
                @unlink($tmpPath);
            }

            return ['ok' => false, 'message' => 'مسار الملف غير آمن.'];
        }

        $saved = false;
        if ($fromHttpUpload && is_uploaded_file($tmpPath)) {
            $saved = @move_uploaded_file($tmpPath, $targetPath);
        }
        if (!$saved) {
            $saved = @copy($tmpPath, $targetPath);
            if ($saved && ($fromHttpUpload || is_file($tmpPath))) {
                @unlink($tmpPath);
            }
        }
        if (!$saved) {
            if (!$fromHttpUpload) {
                @unlink($tmpPath);
            }

            return ['ok' => false, 'message' => 'تعذر حفظ الملف.'];
        }

        if (!self::generateThumbnail($targetPath, $thumbPath)) {
            @copy($targetPath, $thumbPath);
        }

        return [
            'ok' => true,
            'message' => 'تم',
            'file_name' => $fileName,
            'replaced' => false,
            'renamed' => $renamed,
            'requested_name' => $requestedName,
        ];
    }

    /**
     * Skip saving when the same bytes are already on disk or in the sync queue (resume-safe).
     *
     * @return array{ok: bool, message: string, file_name?: string, replaced?: bool, renamed?: bool, requested_name?: string, already_exists?: bool}|null
     */
    private static function resolveIdempotentUpload(
        string $tmpPath,
        string $requestedName,
        string $imagesDir,
        string $thumbnailsDir,
        bool $fromHttpUpload
    ): ?array {
        $incomingHash = hash_file('sha256', $tmpPath);
        if (!is_string($incomingHash) || $incomingHash === '') {
            return null;
        }
        $incomingHash = strtolower($incomingHash);

        $existingPath = self::safeJoin($imagesDir, $requestedName);
        if ($existingPath !== null && is_file($existingPath)) {
            $existingHash = hash_file('sha256', $existingPath);
            if (is_string($existingHash) && strtolower($existingHash) === $incomingHash) {
                if (!$fromHttpUpload) {
                    @unlink($tmpPath);
                }
                self::ensureThumbnailForPath($existingPath, $requestedName, $thumbnailsDir);

                return [
                    'ok' => true,
                    'message' => 'تم',
                    'file_name' => $requestedName,
                    'replaced' => false,
                    'renamed' => false,
                    'requested_name' => $requestedName,
                    'already_exists' => true,
                ];
            }
        }

        $queuedName = MaterialImageSyncService::findQueuedFileNameByHash($incomingHash);
        if ($queuedName !== null) {
            $queuedPath = self::safeJoin($imagesDir, $queuedName);
            if ($queuedPath !== null && is_file($queuedPath)) {
                $queuedHash = hash_file('sha256', $queuedPath);
                if (is_string($queuedHash) && strtolower($queuedHash) === $incomingHash) {
                    if (!$fromHttpUpload) {
                        @unlink($tmpPath);
                    }

                    return [
                        'ok' => true,
                        'message' => 'تم',
                        'file_name' => $queuedName,
                        'replaced' => false,
                        'renamed' => strcasecmp($queuedName, $requestedName) !== 0,
                        'requested_name' => $requestedName,
                        'already_exists' => true,
                    ];
                }
            }
        }

        $localName = self::findLocalFileNameByHashNearName($imagesDir, $incomingHash, $requestedName);
        if ($localName !== null) {
            if (!$fromHttpUpload) {
                @unlink($tmpPath);
            }

            return [
                'ok' => true,
                'message' => 'تم',
                'file_name' => $localName,
                'replaced' => false,
                'renamed' => strcasecmp($localName, $requestedName) !== 0,
                'requested_name' => $requestedName,
                'already_exists' => true,
            ];
        }

        return null;
    }

    private static function findLocalFileNameByHashNearName(string $directory, string $sha256, string $requestedName): ?string
    {
        if (!is_dir($directory)) {
            return null;
        }

        $base = pathinfo($requestedName, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($requestedName, PATHINFO_EXTENSION));
        if ($base === '') {
            return null;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!self::isListableFileName($entry)) {
                continue;
            }
            if ($extension !== '' && strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== $extension) {
                continue;
            }
            $entryBase = pathinfo($entry, PATHINFO_FILENAME);
            if ($entryBase !== $base && !str_starts_with($entryBase, $base . '_')) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }

            $hash = hash_file('sha256', $path);
            if (is_string($hash) && strtolower($hash) === $sha256) {
                return $entry;
            }
        }

        return null;
    }

    private static function ensureThumbnailForPath(string $sourcePath, string $fileName, string $thumbnailsDir): void
    {
        $thumbPath = self::safeJoin($thumbnailsDir, $fileName);
        if ($thumbPath === null || is_file($thumbPath)) {
            return;
        }

        try {
            if (!self::generateThumbnail($sourcePath, $thumbPath)) {
                @copy($sourcePath, $thumbPath);
            }
        } catch (Throwable) {
            @copy($sourcePath, $thumbPath);
        }
    }

    public static function renameLocalCopy(string $fromFileName, string $toFileName): bool
    {
        $fromFileName = self::sanitizeFileName($fromFileName);
        $toFileName = self::sanitizeFileName($toFileName);
        if ($fromFileName === '' || $toFileName === '' || strcasecmp($fromFileName, $toFileName) === 0) {
            return true;
        }

        $settings = self::settings();
        $ok = true;
        foreach ([
            [$settings['images_dir'], false],
            [$settings['thumbnails_dir'], true],
        ] as [$directory, $thumb]) {
            $fromPath = self::safeJoin($directory, $fromFileName);
            $toPath = self::safeJoin($directory, $toFileName);
            if ($fromPath === null || $toPath === null || !is_file($fromPath)) {
                continue;
            }
            if (is_file($toPath)) {
                continue;
            }
            if (!@rename($fromPath, $toPath)) {
                $ok = @copy($fromPath, $toPath) && @unlink($fromPath);
            }
        }

        return $ok;
    }

    private static function availableFileName(string $directory, string $fileName): string
    {
        $fileName = self::sanitizeFileName($fileName);
        if ($fileName === '') {
            return $fileName;
        }

        $base = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $candidate = $fileName;
        $counter = 1;

        while (true) {
            $path = self::safeJoin($directory, $candidate);
            if ($path === null || !is_file($path)) {
                return $candidate;
            }

            $suffix = '_' . $counter;
            $candidate = $extension !== ''
                ? $base . $suffix . '.' . $extension
                : $base . $suffix;
            $counter++;
        }
    }

    /** @return list<string> */
    private static function fileNamesFromAmineApi(string $imageGuid): array
    {
        if (isset(self::$fileNameByGuid[$imageGuid])) {
            return self::$fileNameByGuid[$imageGuid];
        }

        try {
            $response = ApiClient::get('/api/material-images/' . rawurlencode($imageGuid));
            if (!($response['ok'] ?? false)) {
                return [];
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $candidates = self::fileNameCandidates(
                (string) ($data['storedFileName'] ?? ''),
                (string) ($data['fileName'] ?? ''),
                (string) ($data['imagePath'] ?? ''),
                (string) ($data['thumbnailName'] ?? '')
            );
            if ($candidates === []) {
                return [];
            }

            self::$fileNameByGuid[$imageGuid] = $candidates;

            return $candidates;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    private static function fileNameCandidates(string ...$values): array
    {
        $candidates = [];
        foreach ($values as $value) {
            $lookup = self::lookupFileName($value);
            if ($lookup !== '') {
                $candidates[] = $lookup;
            }
            $sanitized = self::sanitizeFileName($value);
            if ($sanitized !== '' && $sanitized !== $lookup) {
                $candidates[] = $sanitized;
            }
        }

        return array_values(array_unique($candidates));
    }

    private static function lookupFileName(string $value): string
    {
        $value = str_replace('\\', '/', trim($value));
        $value = basename($value);
        if ($value === '' || str_contains($value, '..')) {
            return '';
        }

        return $value;
    }

    private static function findFileInDirectory(string $directory, string $fileName): ?string
    {
        $fileName = self::lookupFileName($fileName);
        if ($fileName === '' || !is_dir($directory)) {
            return null;
        }

        $path = self::safeJoin($directory, $fileName);
        if ($path !== null && is_file($path)) {
            return $path;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (strcasecmp($entry, $fileName) === 0) {
                $match = self::safeJoin($directory, $entry);
                if ($match !== null && is_file($match)) {
                    return $match;
                }
            }
        }

        return null;
    }

    private static function generateThumbnail(string $sourcePath, string $targetPath): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }

        $mime = self::detectMime($sourcePath);
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/gif' => @imagecreatefromgif($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default => false,
        };

        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);
            return false;
        }

        $ratio = min(self::THUMB_MAX / $width, self::THUMB_MAX / $height, 1.0);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        if ($thumb === false) {
            imagedestroy($image);
            return false;
        }

        if ($mime === 'image/png' || $mime === 'image/gif') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        $saved = match ($mime) {
            'image/jpeg' => imagejpeg($thumb, $targetPath, 85),
            'image/png' => imagepng($thumb, $targetPath),
            'image/gif' => imagegif($thumb, $targetPath),
            'image/webp' => function_exists('imagewebp') ? imagewebp($thumb, $targetPath, 85) : false,
            default => false,
        };
        imagedestroy($thumb);

        return (bool) $saved;
    }

    private static function sanitizeFileName(string $fileName): string
    {
        $fileName = str_replace('\\', '/', trim($fileName));
        $fileName = basename($fileName);
        $fileName = preg_replace('/[^\w\-. \x{0600}-\x{06FF}]+/u', '_', $fileName) ?? '';

        return trim($fileName);
    }

    private static function isAllowedFileName(string $fileName): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }

    private static function isListableFileName(string $fileName): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return in_array($extension, self::LISTABLE_EXTENSIONS, true);
    }

    private static function thumbnailPath(string $fileName, string $thumbnailsDir): string
    {
        return $thumbnailsDir . DIRECTORY_SEPARATOR . self::sanitizeFileName($fileName);
    }

    private static function resolveDirectory(string $configured, string $defaultRelative): string
    {
        $configured = trim($configured);
        if ($configured !== '') {
            return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured), DIRECTORY_SEPARATOR);
        }

        return rtrim(Config::storagePath(), '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $defaultRelative);
    }

    private static function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        return mkdir($directory, 0775, true) || is_dir($directory);
    }

    private static function safeJoin(string $directory, string $fileName): ?string
    {
        $directory = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory), DIRECTORY_SEPARATOR);
        $fileName = self::lookupFileName($fileName);
        if ($directory === '' || $fileName === '') {
            return null;
        }

        $full = $directory . DIRECTORY_SEPARATOR . $fileName;
        $realDir = realpath($directory);
        if ($realDir === false) {
            $realDir = $directory;
        }

        $realFull = realpath($full);
        if ($realFull === false) {
            $realFull = $full;
        }

        if (!str_starts_with(str_replace('\\', '/', $realFull), str_replace('\\', '/', $realDir))) {
            return null;
        }

        return $full;
    }

    private static function detectMime(string $path): string
    {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم الملف أكبر من المسموح.',
            UPLOAD_ERR_PARTIAL => 'رفع جزئي فقط.',
            UPLOAD_ERR_NO_FILE => 'لم يُرفع ملف.',
            default => 'فشل رفع الملف.',
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, int|string|bool|null>
     */
    private static function buildMaterialsApiQuery(array $filters): array
    {
        $query = [];

        $search = trim((string) ($filters['search'] ?? ($filters['keyword'] ?? '')));
        if ($search !== '') {
            $query['keyword'] = $search;
        }

        foreach ([
            'material_types' => 'materialTypes',
            'age_categories' => 'ageCategories',
            'manufacturers' => 'manufacturers',
            'size_ranges' => 'sizeRanges',
            'country_origins' => 'countryOfOrigins',
            'store_guids' => 'storeGuids',
            'group_guids' => 'groupGuids',
        ] as $inputKey => $apiKey) {
            $values = self::normalizeFilterValues($filters[$inputKey] ?? null);
            if ($values !== []) {
                $query[$apiKey] = implode(',', $values);
            }
        }

        $hasImage = $filters['has_image'] ?? true;
        if ($hasImage === true || $hasImage === '1' || $hasImage === 1 || $hasImage === 'true') {
            $query['hasImage'] = 'true';
        } elseif ($hasImage === false || $hasImage === '0' || $hasImage === 0 || $hasImage === 'false') {
            $query['hasImage'] = 'false';
        }

        $isAvailable = $filters['is_available'] ?? null;
        if ($isAvailable === true || $isAvailable === '1' || $isAvailable === 1 || $isAvailable === 'true') {
            $query['isAvailable'] = 'true';
        } elseif ($isAvailable === false || $isAvailable === '0' || $isAvailable === 0 || $isAvailable === 'false') {
            $query['isAvailable'] = 'false';
        }

        return $query;
    }

    /**
     * @param array<string, int|string|bool|null> $apiQuery
     * @return array{
     *   ok: bool,
     *   message: string,
     *   items: list<array<string, mixed>>,
     *   page: int,
     *   page_size: int,
     *   total_count: int|null,
     *   has_more: bool
     * }
     */
    private static function browseMaterialsWithLocalFilter(
        array $apiQuery,
        string $localStatus,
        int $page,
        int $pageSize
    ): array {
        $skip = ($page - 1) * $pageSize;
        $collected = [];
        $matchedIndex = 0;
        $apiPage = 1;
        $apiPageSize = 50;
        $hasMoreApi = true;
        $hasMore = false;

        while ($hasMoreApi && count($collected) < $pageSize && $apiPage <= 80) {
            $apiQuery['page'] = $apiPage;
            $apiQuery['pageSize'] = $apiPageSize;

            try {
                $response = ApiClient::get('/api/materials', $apiQuery);
            } catch (Throwable $exception) {
                return self::browseError('تعذر الاتصال بـ API المواد: ' . $exception->getMessage());
            }

            if (!($response['ok'] ?? false)) {
                return self::browseError('تعذر جلب المواد من API (رمز ' . (int) ($response['status'] ?? 0) . ').');
            }

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $rows = is_array($data['items'] ?? null) ? $data['items'] : [];
            $totalCount = max(0, (int) ($data['totalCount'] ?? $data['TotalCount'] ?? 0));
            $hasMoreApi = ($apiPage * $apiPageSize) < $totalCount;

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $mapped = self::mapBrowseRow($row);
                if (!self::matchesLocalStatus($mapped, $localStatus)) {
                    continue;
                }

                if ($matchedIndex >= $skip && count($collected) < $pageSize) {
                    $collected[] = $mapped;
                }
                $matchedIndex++;

                if (count($collected) === $pageSize) {
                    $hasMore = true;
                    break 2;
                }
            }

            $apiPage++;
        }

        return [
            'ok' => true,
            'message' => '',
            'items' => $collected,
            'page' => $page,
            'page_size' => $pageSize,
            'total_count' => null,
            'has_more' => $hasMore,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function mapBrowseRow(array $row): array
    {
        $materialGuid = trim((string) ($row['materialGuid'] ?? $row['MaterialGuid'] ?? ''));
        $imageGuid = trim((string) ($row['productImageGuid'] ?? $row['ProductImageGuid'] ?? ''));
        $name = trim((string) ($row['name'] ?? $row['Name'] ?? ''));
        $code = trim((string) ($row['materialCode'] ?? $row['MaterialCode'] ?? ''));
        $storedFileName = '';

        $localPath = null;
        if ($imageGuid !== '') {
            $localPath = self::resolvePathForGuid($imageGuid, false);
            $candidates = self::$fileNameByGuid[$imageGuid] ?? [];
            if ($candidates !== []) {
                $storedFileName = (string) $candidates[0];
            }
        }

        return [
            'material_guid' => $materialGuid,
            'image_guid' => $imageGuid,
            'name' => $name,
            'material_code' => $code,
            'material_type' => trim((string) ($row['materialType'] ?? $row['MaterialType'] ?? '')),
            'manufacturer' => trim((string) ($row['manufacturer'] ?? $row['Manufacturer'] ?? '')),
            'age_category' => trim((string) ($row['ageCategory'] ?? $row['AgeCategory'] ?? '')),
            'has_local' => $localPath !== null,
            'stored_file_name' => $storedFileName,
            'preview_url' => $imageGuid !== '' ? self::imageGuidUrl($imageGuid, true) : '',
            'local_preview_url' => $storedFileName !== '' ? self::publicUrl($storedFileName, true) : '',
        ];
    }

    /** @param array<string, mixed> $row */
    private static function matchesLocalStatus(array $row, string $localStatus): bool
    {
        return match ($localStatus) {
            'on_site' => !empty($row['has_local']),
            'missing' => !empty($row['image_guid']) && empty($row['has_local']),
            default => true,
        };
    }

    /** @return list<string> */
    private static function normalizeFilterValues(mixed $value): array
    {
        if (is_string($value)) {
            $parts = preg_split('/[,|\n]+/u', $value) ?: [];
        } elseif (is_array($value)) {
            $parts = $value;
        } else {
            return [];
        }

        $normalized = [];
        foreach ($parts as $part) {
            $item = trim((string) $part);
            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function countListableFilesInDirectory(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $count = 0;
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) && self::isListableFileName($entry)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{
     *   ok: bool,
     *   message: string,
     *   items: list<array<string, mixed>>,
     *   page: int,
     *   page_size: int,
     *   total_count: int|null,
     *   has_more: bool
     * }
     */
    private static function browseError(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
            'items' => [],
            'page' => 1,
            'page_size' => 24,
            'total_count' => 0,
            'has_more' => false,
        ];
    }
}
