<?php

declare(strict_types=1);

define('PORTAL_NO_SESSION', true);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Support\VapidKeyGenerator;

try {
    $keys = VapidKeyGenerator::create();
} catch (\Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo "Add these lines to portal/.env (and D:\\JawishPortal\\.env on the server):\n\n";
echo 'VAPID_PUBLIC_KEY=' . $keys['publicKey'] . "\n";
echo 'VAPID_PRIVATE_KEY=' . $keys['privateKey'] . "\n";
echo "VAPID_SUBJECT=mailto:admin@example.com\n\n";
echo "Note: run deploy\\scripts\\portal-composer-install.ps1 once so Web Push can send from the server.\n";
