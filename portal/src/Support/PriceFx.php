<?php

declare(strict_types=1);

namespace Portal\Support;

/**
 * Jawish pricing: USD is the fixed sale price; SYP is derived from the current
 * exchange relationship and rounded UP to the nearest 5.
 */
final class PriceFx
{
    public static function roundUpToFive(float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        return ceil($amount / 5.0) * 5.0;
    }

    public static function inferRate(?float $syp, ?float $usd, ?float $currencyVal = null): float
    {
        if ($currencyVal !== null && $currencyVal > 1.0) {
            return $currencyVal;
        }
        if ($usd !== null && $usd > 0.0 && $syp !== null && $syp > 0.0) {
            return $syp / $usd;
        }

        return 0.0;
    }

    public static function sypFromUsd(float $usd, float $rate): float
    {
        if ($usd <= 0.0 || $rate <= 0.0) {
            return 0.0;
        }

        return self::roundUpToFive($usd * $rate);
    }

    public static function applyPercentToUsd(float $usd, float $percent): float
    {
        $pct = max(0.0, min(100.0, $percent));

        return max(0.0, $usd * (1.0 - ($pct / 100.0)));
    }

    public static function percentFromPrices(float $originalUsd, float $saleUsd): float
    {
        if ($originalUsd <= 0.0) {
            return 0.0;
        }
        $pct = (1.0 - ($saleUsd / $originalUsd)) * 100.0;

        return max(0.0, min(95.0, round($pct, 2)));
    }
}
