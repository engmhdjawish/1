<?php

declare(strict_types=1);

namespace Portal\Services;

use Portal\Config;
use Throwable;

final class MaterialImageTemplateService
{
    /** @var array<string, mixed>|null */
    private static ?array $configCache = null;

    public static function templatesDirectory(): string
    {
        return rtrim(Config::storagePath(), '/\\') . DIRECTORY_SEPARATOR . 'material-image-templates';
    }

    public static function isAvailable(): bool
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
            return false;
        }

        return is_file(self::templatesDirectory() . DIRECTORY_SEPARATOR . 'template.json')
            && MaterialImageStorageService::resolveDetailsFontPath() !== null;
    }

    /**
     * @param array<string, mixed>|null $material
     * @return array{product_name: string, manufacturer: string, packaging: string}
     */
    public static function materialFieldValues(?array $material, string $line1Override = '', string $line2Override = ''): array
    {
        $code = trim((string) ($material['material_code'] ?? $material['materialCode'] ?? $material['code'] ?? ''));
        $name = trim((string) ($material['name'] ?? $material['Name'] ?? ''));
        $productName = trim($line1Override);
        if ($productName === '') {
            $productName = trim($code . ' ' . $name);
        }

        $manufacturer = trim((string) (
            $material['company']
            ?? $material['Company']
            ?? $material['manufacturer']
            ?? $material['Manufacturer']
            ?? $material['provenance']
            ?? $material['Provenance']
            ?? ''
        ));

        $packaging = trim($line2Override);
        if ($packaging === '' && is_array($material)) {
            $packQty = ShareCartService::packaging($material);
            $primaryUnit = ShareCartService::primaryUnitLabel($material);
            $packageUnit = ShareCartService::packageUnitLabel($material);
            if ($packQty > 1) {
                $packaging = rtrim(rtrim(number_format($packQty, 2, '.', ''), '0'), '.')
                    . ' '
                    . $primaryUnit
                    . ' / '
                    . $packageUnit;
            }
        }

        return [
            'product_name' => $productName,
            'manufacturer' => $manufacturer,
            'packaging' => $packaging,
        ];
    }

    /**
     * @param array<string, mixed>|null $material
     */
    public static function render(string $sourcePath, ?array $material, string $line1Override = '', string $line2Override = ''): ?string
    {
        if (!self::isAvailable() || !is_file($sourcePath)) {
            return null;
        }

        $font = MaterialImageStorageService::resolveDetailsFontPath();
        if ($font === null) {
            return null;
        }

        $config = self::loadConfig();
        $width = max(400, (int) ($config['width'] ?? 1080));
        $height = max(400, (int) ($config['height'] ?? 1080));
        $fields = self::materialFieldValues($material, $line1Override, $line2Override);

        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            return null;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $footerColor = self::allocateHexColor($canvas, (string) ($config['footer']['color'] ?? '#3f3f3f'));
        $textColor = imagecolorallocate($canvas, 255, 255, 255);

        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);

        $photo = $config['photo'] ?? [];
        $photoX = (int) ($photo['x'] ?? 40);
        $photoY = (int) ($photo['y'] ?? 40);
        $photoW = (int) ($photo['width'] ?? ($width - 80));
        $photoH = (int) ($photo['height'] ?? 730);
        $photoBg = self::allocateHexColor($canvas, (string) ($photo['background'] ?? '#ffffff'));
        imagefilledrectangle($canvas, $photoX, $photoY, $photoX + $photoW, $photoY + $photoH, $photoBg);

        $productImage = MaterialImageStorageService::loadGdImagePublic($sourcePath);
        if ($productImage !== false) {
            self::pasteContained($canvas, $productImage, $photoX, $photoY, $photoW, $photoH);
            imagedestroy($productImage);
        }

        $footerY = (int) ($config['footer']['y'] ?? 770);
        $footerH = (int) ($config['footer']['height'] ?? ($height - $footerY));
        $roundR = (int) ($config['footer']['round_right_radius'] ?? 0);
        self::drawFooterBar($canvas, 0, $footerY, $width, $footerH, $footerColor, $roundR);

        self::overlayLayer($canvas, $config, 'accent');
        self::overlayLayer($canvas, $config, 'logo');
        self::overlayLayer($canvas, $config, 'footer_overlay');

        $columns = is_array($config['columns'] ?? null) ? $config['columns'] : [];
        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }
            $key = (string) ($column['key'] ?? '');
            $colX = (int) ($column['x'] ?? 0);
            $colW = (int) ($column['width'] ?? 200);
            $fontSize = (int) ($column['font_size'] ?? 34);
            $value = trim((string) ($fields[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            self::drawCenteredWrappedText(
                $canvas,
                $font,
                $fontSize,
                $value,
                $colX,
                $footerY + 70,
                $colW,
                $footerH - 90,
                $textColor
            );
        }

        $settings = MaterialImageStorageService::settings();
        $directory = $settings['images_dir'] . DIRECTORY_SEPARATOR . '_processed';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($canvas);

            return null;
        }

        $dest = $directory . DIRECTORY_SEPARATOR . ('template_' . bin2hex(random_bytes(8)) . '.jpg');
        $saved = imagejpeg($canvas, $dest, 92);
        imagedestroy($canvas);

        return $saved ? $dest : null;
    }

    /** @return array<string, mixed> */
    private static function loadConfig(): array
    {
        if (self::$configCache !== null) {
            return self::$configCache;
        }

        $path = self::templatesDirectory() . DIRECTORY_SEPARATOR . 'template.json';
        if (!is_file($path)) {
            self::$configCache = [];

            return self::$configCache;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        self::$configCache = is_array($decoded) ? $decoded : [];

        return self::$configCache;
    }

    /** @param array<string, mixed> $config */
    private static function overlayLayer(\GdImage $canvas, array $config, string layerKey): void
    {
        $layers = is_array($config['layers'] ?? null) ? $config['layers'] : [];
        $fileName = trim((string) ($layers[$layerKey] ?? ''));
        if ($fileName === '') {
            return;
        }

        $path = self::templatesDirectory() . DIRECTORY_SEPARATOR . basename($fileName);
        if (!is_file($path)) {
            return;
        }

        $layer = MaterialImageStorageService::loadGdImagePublic($path);
        if ($layer === false) {
            return;
        }

        $positions = is_array($config['layer_positions'] ?? null) ? $config['layer_positions'] : [];
        $pos = is_array($positions[$layerKey] ?? null) ? $positions[$layerKey] : [];
        $targetX = (int) ($pos['x'] ?? 0);
        $targetY = (int) ($pos['y'] ?? 0);
        $maxWidth = (int) ($pos['max_width'] ?? 0);

        $layerW = imagesx($layer);
        $layerH = imagesy($layer);
        if ($layerW <= 0 || $layerH <= 0) {
            imagedestroy($layer);

            return;
        }

        if ($maxWidth > 0 && $layerW > $maxWidth) {
            $scale = $maxWidth / $layerW;
            $newW = max(1, (int) round($layerW * $scale));
            $newH = max(1, (int) round($layerH * $scale));
            $scaled = imagecreatetruecolor($newW, $newH);
            if ($scaled !== false) {
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                imagecopyresampled($scaled, $layer, 0, 0, 0, 0, $newW, $newH, $layerW, $layerH);
                imagedestroy($layer);
                $layer = $scaled;
                $layerW = $newW;
                $layerH = $newH;
            }
        }

        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
        imagealphablending($layer, true);
        imagesavealpha($layer, true);
        imagecopy($canvas, $layer, $targetX, $targetY, 0, 0, $layerW, $layerH);
        imagedestroy($layer);
    }

    private static function drawFooterBar(
        \GdImage $canvas,
        int $x,
        int $y,
        int $width,
        int $height,
        int $color,
        int $roundRadius
    ): void {
        $roundRadius = max(0, min($roundRadius, (int) floor($height / 2)));
        if ($roundRadius <= 0) {
            imagefilledrectangle($canvas, $x, $y, $x + $width, $y + $height, $color);

            return;
        }

        imagefilledrectangle($canvas, $x, $y, $x + $width - $roundRadius, $y + $height, $color);
        imagefilledellipse(
            $canvas,
            $x + $width - $roundRadius,
            $y + (int) floor($height / 2),
            $roundRadius * 2,
            $height,
            $color
        );
    }

    private static function pasteContained(
        \GdImage $canvas,
        \GdImage $source,
        int $boxX,
        int $boxY,
        int $boxW,
        int $boxH
    ): void {
        $srcW = imagesx($source);
        $srcH = imagesy($source);
        if ($srcW <= 0 || $srcH <= 0 || $boxW <= 0 || $boxH <= 0) {
            return;
        }

        $scale = min($boxW / $srcW, $boxH / $srcH);
        $destW = max(1, (int) floor($srcW * $scale));
        $destH = max(1, (int) floor($srcH * $scale));
        $destX = $boxX + (int) floor(($boxW - $destW) / 2);
        $destY = $boxY + (int) floor(($boxH - $destH) / 2);

        imagecopyresampled($canvas, $source, $destX, $destY, 0, 0, $destW, $destH, $srcW, $srcH);
    }

    private static function drawCenteredWrappedText(
        \GdImage $canvas,
        string $font,
        int $fontSize,
        string $text,
        int $x,
        int $y,
        int $width,
        int $maxHeight,
        int $color
    ): void {
        $lines = MaterialImageStorageService::wrapTtfTextLinesPublic($font, (float) $fontSize, $text, $width - 20);
        if ($lines === []) {
            return;
        }

        $lineHeight = (int) round($fontSize * 1.35);
        $totalHeight = count($lines) * $lineHeight;
        $startY = $y + max(0, (int) floor(($maxHeight - $totalHeight) / 2)) + $fontSize;

        foreach ($lines as $line) {
            $box = imagettfbbox($fontSize, 0, $font, $line) ?: [0, 0, 0, 0, 0, 0, 0, 0];
            $textWidth = abs($box[2] - $box[0]);
            $drawX = $x + (int) floor(($width - $textWidth) / 2);
            imagettftext($canvas, $fontSize, 0, $drawX, $startY, $color, $font, $line);
            $startY += $lineHeight;
        }
    }

    private static function allocateHexColor(\GdImage $canvas, string $hex): int
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return imagecolorallocate($canvas, 63, 63, 63);
        }

        return imagecolorallocate(
            $canvas,
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2))
        );
    }
}
