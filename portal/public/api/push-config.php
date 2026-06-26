<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Portal\Services\WebPushService;

header('Content-Type: application/json; charset=utf-8');

$publicKey = WebPushService::publicKey();
$supported = $publicKey !== ''
    && class_exists(\Minishlink\WebPush\WebPush::class);

echo json_encode([
    'ok' => true,
    'supported' => $supported,
    'publicKey' => $supported ? $publicKey : null,
], JSON_UNESCAPED_UNICODE);
