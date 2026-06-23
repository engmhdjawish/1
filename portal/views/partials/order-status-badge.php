<?php

declare(strict_types=1);

use Portal\Services\OrderService;

/** @var string $status */
$status = (string) ($status ?? 'pending');
$label = OrderService::statusLabel($status);
$class = match ($status) {
    'confirmed' => 'order-status-badge--confirmed',
    'completed' => 'order-status-badge--completed',
    'cancelled' => 'order-status-badge--cancelled',
    default => 'order-status-badge--pending',
};
$size = ($size ?? 'md') === 'sm' ? 'sm' : 'md';
?>
<span class="order-status-badge order-status-badge--<?= h($size) ?> <?= h($class) ?>"><?= h($label) ?></span>
