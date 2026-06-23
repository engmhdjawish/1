<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$tab = ($_GET['tab'] ?? '') === 'orders' ? 'orders' : 'profile';
$target = $tab === 'orders' ? '/my-orders.php' : '/my-profile.php';
$query = [];

if ($tab === 'orders') {
    $orderId = trim((string) ($_GET['order'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    if ($orderId !== '') {
        $query['order'] = $orderId;
    }
    if ($status !== '') {
        $query['status'] = $status;
    }
}

$location = $target;
if ($query !== []) {
    $location .= '?' . http_build_query($query);
}

header('Location: ' . $location, true, 302);
exit;
