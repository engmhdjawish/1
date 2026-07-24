<?php

declare(strict_types=1);

namespace Portal\Support;

final class DigitNormalizer
{
  /** Arabic-Indic (٠–٩) and Persian (۰–۹) → Western (0–9). */
  public static function toWesternDigits(string $value): string
  {
    if ($value === '') {
      return '';
    }

    return strtr($value, [
      '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
      '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
      '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
      '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ]);
  }

  public static function normalizePhone(string $phone): string
  {
    return trim(self::toWesternDigits($phone));
  }
}
