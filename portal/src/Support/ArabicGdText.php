<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * Prepare Arabic (and mixed Arabic/Latin) strings for PHP GD imagettftext().
 */
final class ArabicGdText
{
    public static function shape(string $text): string
    {
        $text = trim($text);
        if ($text === '' || !self::containsArabic($text)) {
            return $text;
        }

        if (!function_exists('mb_strlen')) {
            return $text;
        }

        return ArabicGlyphsEngine::shape($text, true);
    }

    public static function containsArabic(string $text): bool
    {
        return preg_match('/\p{Arabic}/u', $text) === 1;
    }
}
