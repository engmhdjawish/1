<?php

declare(strict_types=1);

define('PORTAL_NO_SESSION', true);

require dirname(__DIR__) . '/bootstrap.php';

if (!class_exists(\Minishlink\WebPush\VAPID::class)) {
    fwrite(STDERR, "Run composer install in the portal folder first.\n");
    exit(1);
}

$keys = \Minishlink\WebPush\VAPID::createVapidKeys();

echo "Add these lines to portal/.env:\n\n";
echo 'VAPID_PUBLIC_KEY=' . $keys['publicKey'] . "\n";
echo 'VAPID_PRIVATE_KEY=' . $keys['privateKey'] . "\n";
echo "VAPID_SUBJECT=mailto:admin@example.com\n";
