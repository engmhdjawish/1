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

        $engine = new FarsiGd();

        return $engine->persianText($text, 'fa', 'tahoma', false);
    }

    public static function containsArabic(string $text): bool
    {
        return preg_match('/\p{Arabic}/u', $text) === 1;
    }
}
