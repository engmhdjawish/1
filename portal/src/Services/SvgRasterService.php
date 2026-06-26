<?php

declare(strict_types=1);

namespace Portal\Services;

/**
 * Rasterize SVG to a GD image resource for icon generation.
 */
final class SvgRasterService
{
    private static ?string $lastError = null;

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /** @return \GdImage|false */
    public static function toGdImage(string $sourcePath, int $targetSize = 1024)
    {
        self::$lastError = null;
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            self::$lastError = 'ملف SVG غير قابل للقراءة.';

            return false;
        }

        $svgContent = @file_get_contents($sourcePath);
        if (!is_string($svgContent) || trim($svgContent) === '') {
            self::$lastError = 'ملف SVG فارغ.';

            return false;
        }

        $embedded = self::loadEmbeddedRaster($svgContent);
        if ($embedded !== false) {
            return self::resizeGdImage($embedded, $targetSize);
        }

        $gd = self::tryImagick($sourcePath, $svgContent, $targetSize);
        if ($gd !== false) {
            return $gd;
        }

        $pngPath = self::tryExternalRaster($sourcePath, $targetSize);
        if ($pngPath !== null) {
            $gd = @imagecreatefrompng($pngPath);
            @unlink($pngPath);
            if ($gd !== false) {
                return $gd;
            }
        }

        self::$lastError ??= 'تعذر تحويل SVG. ثبّت ImageMagick أو Inkscape، أو ارفع الشعار بصيغة PNG/JPG.';

        return false;
    }

    /** @return \GdImage|false */
    private static function loadEmbeddedRaster(string $svgContent)
    {
        if (!preg_match('/data:image\/(png|jpe?g|webp);base64,([A-Za-z0-9+\/=]+)/i', $svgContent, $matches)) {
            return false;
        }

        $binary = base64_decode((string) ($matches[2] ?? ''), true);
        if ($binary === false || $binary === '') {
            return false;
        }

        $gd = @imagecreatefromstring($binary);

        return $gd !== false ? $gd : false;
    }

    /** @return \GdImage|false */
    private static function tryImagick(string $sourcePath, string $svgContent, int $targetSize)
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            return false;
        }

        try {
            $image = new \Imagick();
            $image->setBackgroundColor(new \ImagickPixel('transparent'));
            $image->setResolution(384, 384);
            if (method_exists($image, 'setOption')) {
                $image->setOption('svg:xml-parse-huge', 'true');
            }

            $loaded = false;
            try {
                $image->readImage($sourcePath);
                $loaded = true;
            } catch (\Throwable) {
                $image->clear();
                $image->destroy();
                $image = new \Imagick();
                $image->setBackgroundColor(new \ImagickPixel('transparent'));
                $image->setResolution(384, 384);
            }

            if (!$loaded) {
                $image->readImageBlob($svgContent, 'logo.svg');
            }

            $image->setImageFormat('png');
            $width = max(1, (int) $image->getImageWidth());
            $height = max(1, (int) $image->getImageHeight());
            $maxDim = max($width, $height);
            if ($maxDim > $targetSize) {
                $image->resizeImage($targetSize, $targetSize, \Imagick::FILTER_LANCZOS, 1, true);
            }

            $blob = $image->getImageBlob();
            $image->clear();
            $image->destroy();
            $gd = @imagecreatefromstring($blob);

            return $gd !== false ? $gd : false;
        } catch (\Throwable $exception) {
            self::$lastError = 'Imagick: ' . $exception->getMessage();

            return false;
        }
    }

    private static function tryExternalRaster(string $sourcePath, int $targetSize): ?string
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'portal_svg_png_');
        if ($outputPath === false) {
            return null;
        }
        $pngPath = $outputPath . '.png';
        @unlink($outputPath);

        foreach (self::magickCommands($sourcePath, $pngPath, $targetSize) as $command) {
            @exec($command . ' 2>&1', $output, $exitCode);
            if ($exitCode === 0 && is_file($pngPath) && filesize($pngPath) > 0) {
                return $pngPath;
            }
        }

        foreach (self::inkscapeCommands($sourcePath, $pngPath, $targetSize) as $command) {
            @exec($command . ' 2>&1', $output, $exitCode);
            if ($exitCode === 0 && is_file($pngPath) && filesize($pngPath) > 0) {
                return $pngPath;
            }
        }

        if (is_file($pngPath)) {
            @unlink($pngPath);
        }

        return null;
    }

    /** @return list<string> */
    private static function magickCommands(string $sourcePath, string $pngPath, int $targetSize): array
    {
        $bins = self::discoverBinaries(['magick', 'convert']);
        $commands = [];
        foreach ($bins as $bin) {
            $commands[] = implode(' ', [
                escapeshellarg($bin),
                escapeshellarg($sourcePath),
                '-background', 'none',
                '-density', '300',
                '-resize', $targetSize . 'x' . $targetSize,
                escapeshellarg($pngPath),
            ]);
        }

        return $commands;
    }

    /** @return list<string> */
    private static function inkscapeCommands(string $sourcePath, string $pngPath, int $targetSize): array
    {
        $bins = self::discoverBinaries(['inkscape']);
        $commands = [];
        foreach ($bins as $bin) {
            $commands[] = implode(' ', [
                escapeshellarg($bin),
                escapeshellarg($sourcePath),
                '--export-type=png',
                '--export-filename=' . escapeshellarg($pngPath),
                '-w', (string) $targetSize,
                '-h', (string) $targetSize,
            ]);
        }

        return $commands;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function discoverBinaries(array $names): array
    {
        $found = [];
        $isWindows = PHP_OS_FAMILY === 'Windows';

        foreach ($names as $name) {
            if ($isWindows) {
                @exec('where ' . escapeshellarg($name) . ' 2>NUL', $whereOutput, $whereCode);
                if ($whereCode === 0) {
                    foreach ($whereOutput as $line) {
                        $line = trim((string) $line);
                        if ($line !== '' && is_file($line)) {
                            $found[] = $line;
                        }
                    }
                }

                $programFiles = [
                    getenv('ProgramFiles') ?: 'C:\\Program Files',
                    getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)',
                ];
                foreach ($programFiles as $root) {
                    $glob = glob($root . DIRECTORY_SEPARATOR . 'ImageMagick*' . DIRECTORY_SEPARATOR . $name . '.exe') ?: [];
                    foreach ($glob as $path) {
                        $found[] = $path;
                    }
                    $inkscape = $root . DIRECTORY_SEPARATOR . 'Inkscape' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $name . '.exe';
                    if (is_file($inkscape)) {
                        $found[] = $inkscape;
                    }
                }
            } else {
                foreach (['/usr/bin', '/usr/local/bin'] as $dir) {
                    $candidate = $dir . '/' . $name;
                    if (is_file($candidate) && is_executable($candidate)) {
                        $found[] = $candidate;
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }

    /** @param \GdImage $gd */
    private static function resizeGdImage($gd, int $targetSize)
    {
        $width = imagesx($gd);
        $height = imagesy($gd);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($gd);

            return false;
        }

        $maxDim = max($width, $height);
        if ($maxDim <= $targetSize) {
            return $gd;
        }

        $scale = $targetSize / $maxDim;
        $dstW = max(1, (int) round($width * $scale));
        $dstH = max(1, (int) round($height * $scale));
        $resized = imagecreatetruecolor($dstW, $dstH);
        if ($resized === false) {
            imagedestroy($gd);

            return false;
        }

        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $dstW, $dstH, $transparent);
        imagecopyresampled($resized, $gd, 0, 0, 0, 0, $dstW, $dstH, $width, $height);
        imagedestroy($gd);

        return $resized;
    }
}
