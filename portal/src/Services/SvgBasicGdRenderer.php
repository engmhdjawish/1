<?php

declare(strict_types=1);

namespace Portal\Services;

/**
 * SVG → GD rasterizer for logos (no ImageMagick/Inkscape required).
 */
final class SvgBasicGdRenderer
{
    /** @return \GdImage|false */
    public static function render(string $sourcePath, int $targetSize = 1024)
    {
        $xml = @file_get_contents($sourcePath);
        if (!is_string($xml) || trim($xml) === '') {
            return false;
        }

        $xml = self::sanitizeSvgXml($xml);
        libxml_use_internal_errors(true);
        $svg = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        if ($svg === false) {
            return false;
        }

        $svg->registerXPathNamespace('svg', 'http://www.w3.org/2000/svg');
        $svg->registerXPathNamespace('xlink', 'http://www.w3.org/1999/xlink');

        $attrs = $svg->attributes();
        $viewBox = self::parseViewBox(
            (string) ($attrs->viewBox ?? ''),
            self::parseLength((string) ($attrs->width ?? '0')),
            self::parseLength((string) ($attrs->height ?? '0'))
        );
        if ($viewBox['width'] <= 0 || $viewBox['height'] <= 0) {
            return false;
        }

        $canvas = imagecreatetruecolor($targetSize, $targetSize);
        if ($canvas === false) {
            return false;
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $targetSize, $targetSize, $transparent);
        imagealphablending($canvas, true);

        $scale = min($targetSize / $viewBox['width'], $targetSize / $viewBox['height']);
        $offsetX = ($targetSize - ($viewBox['width'] * $scale)) / 2;
        $offsetY = ($targetSize - ($viewBox['height'] * $scale)) / 2;

        $ctx = [
            'canvas' => $canvas,
            'root' => $svg,
            'scale' => $scale,
            'offsetX' => $offsetX,
            'offsetY' => $offsetY,
            'viewBox' => $viewBox,
            'defaultFill' => '',
            'defaultStroke' => '',
            'defaultStrokeWidth' => 1.0,
        ];

        self::drawNode($svg, $ctx);

        if (!self::hasVisibleInk($canvas)) {
            imagedestroy($canvas);

            return false;
        }

        return $canvas;
    }

    private static function sanitizeSvgXml(string $xml): string
    {
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml) ?? $xml;
        $xml = preg_replace('/<\?xml[^?]*\?>/i', '', $xml) ?? $xml;
        $xml = preg_replace('/<!DOCTYPE[^>]*>/i', '', $xml) ?? $xml;

        return trim($xml);
    }

    private static function parseLength(string $value): float
    {
        $value = trim(str_replace(',', '.', $value));
        if ($value === '') {
            return 0.0;
        }

        if (preg_match('/^[-+]?(?:\d*\.\d+|\d+)(?:e[-+]?\d+)?/i', $value, $matches) === 1) {
            return (float) $matches[0];
        }

        return 0.0;
    }

    /** @param \GdImage $canvas */
    private static function hasVisibleInk($canvas): bool
    {
        $width = imagesx($canvas);
        $height = imagesy($canvas);
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $stepX = max(1, (int) floor($width / 24));
        $stepY = max(1, (int) floor($height / 24));
        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                $alpha = (imagecolorat($canvas, $x, $y) >> 24) & 0x7F;
                if ($alpha < 120) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{x: float, y: float, width: float, height: float} */
    private static function parseViewBox(string $viewBox, float $width, float $height): array
    {
        $parts = preg_split('/[\s,]+/', trim($viewBox)) ?: [];
        if (count($parts) === 4) {
            return [
                'x' => (float) $parts[0],
                'y' => (float) $parts[1],
                'width' => max(1.0, (float) $parts[2]),
                'height' => max(1.0, (float) $parts[3]),
            ];
        }

        return [
            'x' => 0.0,
            'y' => 0.0,
            'width' => $width > 0 ? $width : 512.0,
            'height' => $height > 0 ? $height : 512.0,
        ];
    }

    /** @param array<string, mixed> $ctx */
    private static function drawNode(\SimpleXMLElement $node, array $ctx, int $depth = 0): void
    {
        if ($depth > 32) {
            return;
        }

        $name = strtolower($node->getName());
        if (in_array($name, ['defs', 'clippath', 'mask', 'metadata', 'title', 'desc'], true)) {
            return;
        }

        if ($name === 'g' || $name === 'svg') {
            $attrs = $node->attributes();
            $nextCtx = self::applyInheritedContext($ctx, $attrs);
            foreach (self::childElements($node) as $child) {
                self::drawNode($child, $nextCtx, $depth + 1);
            }

            return;
        }

        if ($name === 'use') {
            self::drawUseNode($node, $ctx, $depth);

            return;
        }

        if ($name === 'image') {
            self::drawImageNode($node, $ctx);

            return;
        }

        if ($name === 'text') {
            self::drawTextNode($node, $ctx);

            return;
        }

        $attrs = $node->attributes();
        $fillRaw = self::rawPaint($attrs, 'fill', $ctx, true);
        $strokeRaw = self::rawPaint($attrs, 'stroke', $ctx, false);
        $fill = self::resolvePaint($fillRaw, $ctx);
        $stroke = self::resolvePaint($strokeRaw, $ctx);

        if ($fill === null && $stroke === null) {
            if (in_array($name, ['path', 'rect', 'circle', 'ellipse', 'polygon'], true) && $fillRaw === '') {
                $fill = [0, 0, 0, 0];
            } else {
                return;
            }
        }

        /** @var \GdImage $canvas */
        $canvas = $ctx['canvas'];
        $fillColor = $fill !== null
            ? imagecolorallocatealpha($canvas, $fill[0], $fill[1], $fill[2], $fill[3])
            : null;
        $strokeColor = $stroke !== null
            ? imagecolorallocatealpha($canvas, $stroke[0], $stroke[1], $stroke[2], $stroke[3])
            : null;

        if ($name === 'rect') {
            $x = self::parseLength((string) ($attrs->x ?? '0'));
            $y = self::parseLength((string) ($attrs->y ?? '0'));
            $w = self::parseLength((string) ($attrs->width ?? '0'));
            $h = self::parseLength((string) ($attrs->height ?? '0'));
            if ($w > 0 && $h > 0 && $fillColor !== null) {
                imagefilledrectangle(
                    $canvas,
                    (int) self::tx($ctx, $x),
                    (int) self::ty($ctx, $y),
                    (int) self::tx($ctx, $x + $w),
                    (int) self::ty($ctx, $y + $h),
                    $fillColor
                );
            } elseif ($w > 0 && $h > 0 && $strokeColor !== null) {
                imagerectangle(
                    $canvas,
                    (int) self::tx($ctx, $x),
                    (int) self::ty($ctx, $y),
                    (int) self::tx($ctx, $x + $w),
                    (int) self::ty($ctx, $y + $h),
                    $strokeColor
                );
            }

            return;
        }

        if ($name === 'circle') {
            $cx = self::parseLength((string) ($attrs->cx ?? '0'));
            $cy = self::parseLength((string) ($attrs->cy ?? '0'));
            $r = self::parseLength((string) ($attrs->r ?? '0'));
            if ($r > 0 && $fillColor !== null) {
                imagefilledellipse(
                    $canvas,
                    (int) self::tx($ctx, $cx),
                    (int) self::ty($ctx, $cy),
                    (int) max(1, round($r * 2 * $ctx['scale'])),
                    (int) max(1, round($r * 2 * $ctx['scale'])),
                    $fillColor
                );
            }

            return;
        }

        if ($name === 'ellipse') {
            $cx = self::parseLength((string) ($attrs->cx ?? '0'));
            $cy = self::parseLength((string) ($attrs->cy ?? '0'));
            $rx = self::parseLength((string) ($attrs->rx ?? '0'));
            $ry = self::parseLength((string) ($attrs->ry ?? '0'));
            if ($rx > 0 && $ry > 0 && $fillColor !== null) {
                imagefilledellipse(
                    $canvas,
                    (int) self::tx($ctx, $cx),
                    (int) self::ty($ctx, $cy),
                    (int) max(1, round($rx * 2 * $ctx['scale'])),
                    (int) max(1, round($ry * 2 * $ctx['scale'])),
                    $fillColor
                );
            }

            return;
        }

        if ($name === 'polygon' || $name === 'polyline') {
            $raw = preg_split('/[\s,]+/', trim((string) ($attrs->points ?? ''))) ?: [];
            $points = [];
            for ($i = 0; $i < count($raw) - 1; $i += 2) {
                $points[] = (int) self::tx($ctx, self::parseLength((string) $raw[$i]));
                $points[] = (int) self::ty($ctx, self::parseLength((string) ($raw[$i + 1] ?? '0')));
            }
            if (count($points) >= 6) {
                if ($name === 'polygon' && $fillColor !== null) {
                    imagefilledpolygon($canvas, $points, $fillColor);
                } elseif ($strokeColor !== null) {
                    self::drawPolyline($canvas, $points, $strokeColor, $ctx, $name === 'polygon');
                }
            }

            return;
        }

        if ($name === 'path') {
            $polygon = self::pathToPolygon((string) ($attrs->d ?? ''), $ctx);
            if (count($polygon) >= 6) {
                if ($fillColor !== null) {
                    imagefilledpolygon($canvas, $polygon, $fillColor);
                } elseif ($strokeColor !== null) {
                    self::drawPolyline($canvas, $polygon, $strokeColor, $ctx, false);
                }
            }
        }
    }

    /** @param array<string, mixed> $ctx */
    private static function drawUseNode(\SimpleXMLElement $node, array $ctx, int $depth): void
    {
        $attrs = $node->attributes();
        $href = trim((string) ($attrs->href ?? $attrs->{'xlink:href'} ?? ''));
        if ($href === '' || !str_starts_with($href, '#')) {
            return;
        }

        $id = substr($href, 1);
        /** @var \SimpleXMLElement $root */
        $root = $ctx['root'];
        $matches = $root->xpath("//*[@id=" . self::xpathLiteral($id) . "]");
        if (!is_array($matches) || $matches === []) {
            return;
        }

        $nextCtx = self::applyInheritedContext($ctx, $attrs);
        $dx = self::parseLength((string) ($attrs->x ?? '0'));
        $dy = self::parseLength((string) ($attrs->y ?? '0'));
        if ($dx !== 0.0 || $dy !== 0.0) {
            $nextCtx['offsetX'] = (float) $nextCtx['offsetX'] + ($dx * (float) $nextCtx['scale']);
            $nextCtx['offsetY'] = (float) $nextCtx['offsetY'] + ($dy * (float) $nextCtx['scale']);
        }

        foreach ($matches as $target) {
            if (!($target instanceof \SimpleXMLElement)) {
                continue;
            }
            self::drawNode($target, $nextCtx, $depth + 1);
        }
    }

    /** @param array<string, mixed> $ctx */
    private static function drawImageNode(\SimpleXMLElement $node, array $ctx): void
    {
        $attrs = $node->attributes();
        $href = trim((string) ($attrs->href ?? $attrs->{'xlink:href'} ?? ''));
        if ($href === '' || !str_starts_with($href, 'data:image/')) {
            return;
        }

        if (!preg_match('/^data:image\/(png|jpe?g|webp);base64,(.+)$/i', $href, $matches)) {
            return;
        }

        $binary = base64_decode((string) ($matches[2] ?? ''), true);
        if ($binary === false || $binary === '') {
            return;
        }

        $embedded = @imagecreatefromstring($binary);
        if ($embedded === false) {
            return;
        }

        $x = self::parseLength((string) ($attrs->x ?? '0'));
        $y = self::parseLength((string) ($attrs->y ?? '0'));
        $w = self::parseLength((string) ($attrs->width ?? (string) imagesx($embedded)));
        $h = self::parseLength((string) ($attrs->height ?? (string) imagesy($embedded)));
        if ($w <= 0) {
            $w = (float) imagesx($embedded);
        }
        if ($h <= 0) {
            $h = (float) imagesy($embedded);
        }

        /** @var \GdImage $canvas */
        $canvas = $ctx['canvas'];
        $dstX = (int) self::tx($ctx, $x);
        $dstY = (int) self::ty($ctx, $y);
        $dstW = max(1, (int) round($w * (float) $ctx['scale']));
        $dstH = max(1, (int) round($h * (float) $ctx['scale']));
        imagecopyresampled($canvas, $embedded, $dstX, $dstY, 0, 0, $dstW, $dstH, imagesx($embedded), imagesy($embedded));
        imagedestroy($embedded);
    }

    /** @param array<string, mixed> $ctx */
    private static function drawTextNode(\SimpleXMLElement $node, array $ctx): void
    {
        $attrs = $node->attributes();
        $text = trim((string) $node);
        if ($text === '') {
            return;
        }

        $fillRaw = self::rawPaint($attrs, 'fill', $ctx, true);
        $fill = self::resolvePaint($fillRaw !== '' ? $fillRaw : '#000000', $ctx);
        if ($fill === null) {
            $fill = [0, 0, 0, 0];
        }

        $x = self::parseLength((string) ($attrs->x ?? '0'));
        $y = self::parseLength((string) ($attrs->y ?? '0'));
        $fontSize = max(8.0, self::parseLength((string) ($attrs->{'font-size'} ?? '16')));
        $fontSizePx = max(8, (int) round($fontSize * (float) $ctx['scale']));

        /** @var \GdImage $canvas */
        $canvas = $ctx['canvas'];
        $color = imagecolorallocatealpha($canvas, $fill[0], $fill[1], $fill[2], $fill[3]);
        $fontPath = self::systemFontPath();
        $drawX = (int) self::tx($ctx, $x);
        $drawY = (int) self::ty($ctx, $y);

        if ($fontPath !== null && function_exists('imagettftext')) {
            imagettftext($canvas, $fontSizePx, 0, $drawX, $drawY, $color, $fontPath, $text);

            return;
        }

        imagestring($canvas, 5, $drawX, max(0, $drawY - 12), substr($text, 0, 80), $color);
    }

    /** @param \SimpleXMLElement|null $attrs @param array<string, mixed> $ctx */
    private static function rawPaint($attrs, string $key, array $ctx, bool $isFill): string
    {
        $raw = self::styleValue($attrs, $key);
        if ($raw === '' && $attrs !== null && isset($attrs->{$key})) {
            $raw = trim((string) $attrs->{$key});
        }
        if ($raw === '' || strtolower($raw) === 'inherit') {
            $raw = (string) ($ctx[$isFill ? 'defaultFill' : 'defaultStroke'] ?? '');
        }

        return strtolower($raw) === 'none' ? '' : $raw;
    }

    /** @param array<string, mixed> $ctx @return array<string, mixed> */
    private static function applyInheritedContext(array $ctx, ?\SimpleXMLElement $attrs): array
    {
        $nextCtx = $ctx;
        if ($attrs === null) {
            return $nextCtx;
        }

        $fillAttr = self::styleValue($attrs, 'fill');
        if ($fillAttr === '' && isset($attrs->fill)) {
            $fillAttr = trim((string) $attrs->fill);
        }
        if ($fillAttr !== '' && strtolower($fillAttr) !== 'inherit') {
            $nextCtx['defaultFill'] = $fillAttr;
        }

        $strokeAttr = self::styleValue($attrs, 'stroke');
        if ($strokeAttr === '' && isset($attrs->stroke)) {
            $strokeAttr = trim((string) $attrs->stroke);
        }
        if ($strokeAttr !== '' && strtolower($strokeAttr) !== 'inherit') {
            $nextCtx['defaultStroke'] = $strokeAttr;
        }

        return $nextCtx;
    }

    /** @param array<string, mixed> $ctx @return array{0: int, 1: int, 2: int, 3: int}|null */
    private static function resolvePaint(string $raw, array $ctx): ?array
    {
        $raw = trim($raw);
        if ($raw === '' || strtolower($raw) === 'none' || strtolower($raw) === 'transparent') {
            return null;
        }

        if (preg_match('/^url\(#([^)]+)\)$/i', $raw, $matches) === 1) {
            return self::gradientFirstColor((string) ($matches[1] ?? ''), $ctx);
        }

        return self::parseColor($raw);
    }

    /** @param array<string, mixed> $ctx @return array{0: int, 1: int, 2: int, 3: int}|null */
    private static function gradientFirstColor(string $id, array $ctx): ?array
    {
        /** @var \SimpleXMLElement $root */
        $root = $ctx['root'];
        $matches = $root->xpath("//*[@id=" . self::xpathLiteral($id) . "]");
        if (!is_array($matches) || $matches === []) {
            return null;
        }

        $gradient = $matches[0];
        if (!($gradient instanceof \SimpleXMLElement)) {
            return null;
        }

        $stops = $gradient->xpath('.//*[local-name()="stop"]');
        if (!is_array($stops)) {
            return null;
        }

        foreach ($stops as $stop) {
            if (!($stop instanceof \SimpleXMLElement)) {
                continue;
            }
            $stopAttrs = $stop->attributes();
            $color = trim((string) ($stopAttrs->{'stop-color'} ?? ''));
            if ($color === '') {
                $color = self::styleValue($stopAttrs, 'stop-color');
            }
            $parsed = self::parseColor($color);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /** @param \GdImage $canvas @param list<int> $points */
    private static function drawPolyline($canvas, array $points, int $strokeColor, array $ctx, bool $close): void
    {
        imagesetthickness($canvas, max(1, (int) round((float) ($ctx['defaultStrokeWidth'] ?? 1) * (float) $ctx['scale'])));
        for ($i = 0; $i < count($points) - 2; $i += 2) {
            imageline($canvas, $points[$i], $points[$i + 1], $points[$i + 2], $points[$i + 3], $strokeColor);
        }
        if ($close && count($points) >= 4) {
            imageline(
                $canvas,
                $points[count($points) - 2],
                $points[count($points) - 1],
                $points[0],
                $points[1],
                $strokeColor
            );
        }
    }

    /** @param array<string, mixed> $ctx @return list<int> */
    private static function pathToPolygon(string $d, array $ctx): array
    {
        $tokens = preg_split('/((?:[MLHVCSQTAZmlhvcsqtaz])|[-+]?(?:\d*\.\d+|\d+)(?:[eE][-+]?\d+)?)/', $d, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $x = 0.0;
        $y = 0.0;
        $startX = 0.0;
        $startY = 0.0;
        $points = [];
        $i = 0;
        $cmd = '';

        $push = static function (float $px, float $py) use (&$points, $ctx): void {
            $points[] = (int) self::tx($ctx, $px);
            $points[] = (int) self::ty($ctx, $py);
        };

        while ($i < count($tokens)) {
            $token = $tokens[$i];
            if (preg_match('/^[MLHVCSQTAZmlhvcsqtaz]$/', $token) === 1) {
                $cmd = $token;
                $i++;
                continue;
            }

            $rel = strtolower($cmd) === $cmd && $cmd !== '';
            $upper = strtoupper($cmd);

            if ($upper === 'M') {
                $x = (float) $tokens[$i++];
                $y = (float) $tokens[$i++];
                if ($rel) {
                    $x += $startX;
                    $y += $startY;
                }
                $startX = $x;
                $startY = $y;
                $push($x, $y);
                $cmd = $rel ? 'l' : 'L';
                continue;
            }

            if ($upper === 'L') {
                $x = (float) $tokens[$i++];
                $y = (float) $tokens[$i++];
                if ($rel) {
                    $x += $startX;
                    $y += $startY;
                }
                $startX = $x;
                $startY = $y;
                $push($x, $y);
                continue;
            }

            if ($upper === 'H') {
                $x = (float) $tokens[$i++];
                if ($rel) {
                    $x += $startX;
                }
                $startX = $x;
                $push($x, $startY);
                continue;
            }

            if ($upper === 'V') {
                $y = (float) $tokens[$i++];
                if ($rel) {
                    $y += $startY;
                }
                $startY = $y;
                $push($startX, $y);
                continue;
            }

            if ($upper === 'Z') {
                $push($startX, $startY);
                $i++;
                continue;
            }

            if ($upper === 'C') {
                $x1 = (float) $tokens[$i++];
                $y1 = (float) $tokens[$i++];
                $x2 = (float) $tokens[$i++];
                $y2 = (float) $tokens[$i++];
                $x3 = (float) $tokens[$i++];
                $y3 = (float) $tokens[$i++];
                if ($rel) {
                    $x1 += $startX;
                    $y1 += $startY;
                    $x2 += $startX;
                    $y2 += $startY;
                    $x3 += $startX;
                    $y3 += $startY;
                }
                for ($t = 0.1; $t <= 1.0; $t += 0.1) {
                    $px = (1 - $t) ** 3 * $startX + 3 * (1 - $t) ** 2 * $t * $x1 + 3 * (1 - $t) * $t ** 2 * $x2 + $t ** 3 * $x3;
                    $py = (1 - $t) ** 3 * $startY + 3 * (1 - $t) ** 2 * $t * $y1 + 3 * (1 - $t) * $t ** 2 * $y2 + $t ** 3 * $y3;
                    $push($px, $py);
                }
                $startX = $x3;
                $startY = $y3;
                continue;
            }

            $i++;
        }

        return $points;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int}|null */
    private static function parseColor(string $value): ?array
    {
        $value = trim($value);
        if ($value === '' || $value === 'none' || $value === 'transparent') {
            return null;
        }

        if (preg_match('/^#([0-9a-f]{3})$/i', $value, $m) === 1) {
            $hex = $m[1];

            return [
                hexdec(str_repeat($hex[0], 2)),
                hexdec(str_repeat($hex[1], 2)),
                hexdec(str_repeat($hex[2], 2)),
                0,
            ];
        }

        if (preg_match('/^#([0-9a-f]{6})$/i', $value, $m) === 1) {
            $hex = $m[1];

            return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), 0];
        }

        if (preg_match('/^#([0-9a-f]{8})$/i', $value, $m) === 1) {
            $hex = $m[1];
            $alpha = hexdec(substr($hex, 6, 2));
            $gdAlpha = (int) round(127 - ($alpha / 255) * 127);

            return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), max(0, min(127, $gdAlpha))];
        }

        if (preg_match('/^rgba?\(([^)]+)\)$/i', $value, $m) === 1) {
            $parts = array_map('trim', explode(',', (string) ($m[1] ?? '')));
            if (count($parts) >= 3) {
                $r = (int) round((float) $parts[0]);
                $g = (int) round((float) $parts[1]);
                $b = (int) round((float) $parts[2]);
                $alpha = count($parts) >= 4 ? (float) $parts[3] : 1.0;
                $gdAlpha = (int) round(127 - max(0.0, min(1.0, $alpha)) * 127);

                return [max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)), max(0, min(127, $gdAlpha))];
            }
        }

        $named = [
            'black' => [0, 0, 0, 0],
            'white' => [255, 255, 255, 0],
            'red' => [220, 38, 38, 0],
            'green' => [22, 163, 74, 0],
            'blue' => [37, 99, 235, 0],
            'currentcolor' => [17, 24, 39, 0],
        ];
        $lower = strtolower($value);

        return $named[$lower] ?? null;
    }

    /** @param array<string, mixed> $ctx */
    private static function tx(array $ctx, float $x): float
    {
        return $ctx['offsetX'] + (($x - $ctx['viewBox']['x']) * $ctx['scale']);
    }

    /** @param array<string, mixed> $ctx */
    private static function ty(array $ctx, float $y): float
    {
        return $ctx['offsetY'] + (($y - $ctx['viewBox']['y']) * $ctx['scale']);
    }

    /** @return iterable<int, \SimpleXMLElement> */
    private static function childElements(\SimpleXMLElement $node): iterable
    {
        $namespaced = $node->children('http://www.w3.org/2000/svg');
        if (count($namespaced) > 0) {
            return $namespaced;
        }

        return $node->children();
    }

    /** @param \SimpleXMLElement|null $attrs */
    private static function styleValue($attrs, string $key): string
    {
        if ($attrs === null) {
            return '';
        }

        $style = trim((string) ($attrs->style ?? ''));
        if ($style === '') {
            return '';
        }

        foreach (explode(';', $style) as $rule) {
            $parts = explode(':', $rule, 2);
            if (count($parts) !== 2) {
                continue;
            }
            if (trim($parts[0]) === $key) {
                return trim($parts[1]);
            }
        }

        return '';
    }

    private static function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'{$value}'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }

        $parts = explode("'", $value);

        return 'concat(' . implode(", \"'\", ", array_map(static fn (string $part): string => "'{$part}'", $parts)) . ')';
    }

    private static function systemFontPath(): ?string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached !== '' ? $cached : null;
        }

        $candidates = [
            'C:\\Windows\\Fonts\\arial.ttf',
            'C:\\Windows\\Fonts\\segoeui.ttf',
            'C:\\Windows\\Fonts\\tahoma.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $cached = $candidate;

                return $candidate;
            }
        }

        $cached = '';

        return null;
    }
}
