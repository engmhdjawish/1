<?php

declare(strict_types=1);

namespace Portal\Support;

final class Text
{
    /** Lowercase without requiring ext-mbstring (common on Windows PHP builds). */
    public static function lower(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
