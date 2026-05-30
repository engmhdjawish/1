<?php

declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_money(?float $amount, bool $show): string
{
    if (!$show || $amount === null) {
        return '—';
    }

    return number_format($amount, 0, '.', ',');
}
