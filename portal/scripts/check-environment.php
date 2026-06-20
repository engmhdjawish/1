<?php

declare(strict_types=1);

/**
 * فحص بيئة PHP للبوابة — شغّل من سطر الأوامر:
 *   php scripts/check-environment.php
 */

$extensions = ['pdo_pgsql', 'curl', 'mbstring', 'openssl'];
$extensionStatus = [];
foreach ($extensions as $extension) {
    $extensionStatus[$extension] = extension_loaded($extension);
}

$algorithms = [
    'bcrypt' => defined('PASSWORD_BCRYPT'),
    'argon2id' => defined('PASSWORD_ARGON2ID'),
    'argon2i' => defined('PASSWORD_ARGON2I'),
];

$hashTest = [
    'ok' => false,
    'algorithm' => null,
    'error' => null,
];

try {
    require dirname(__DIR__) . '/bootstrap.php';
    $hash = \Portal\Auth\Password::hash('portal-env-check');
    $hashTest = [
        'ok' => \Portal\Auth\Password::verify('portal-env-check', $hash),
        'algorithm' => password_get_info($hash)['algoName'] ?? null,
        'error' => null,
    ];
} catch (\Throwable $exception) {
    $hashTest['error'] = $exception->getMessage();
}

$dbTest = ['ok' => false, 'error' => null];
try {
    if (!isset($hash) || $hashTest['ok']) {
        \Portal\Database::pdo()->query('SELECT 1');
        $dbTest['ok'] = true;
    }
} catch (\Throwable $exception) {
    $dbTest['error'] = $exception->getMessage();
}

$report = [
    'php_version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'extensions' => $extensionStatus,
    'password_algorithms' => [
        'bcrypt' => defined('PASSWORD_BCRYPT'),
        'argon2id' => defined('PASSWORD_ARGON2ID'),
        'argon2i' => defined('PASSWORD_ARGON2I'),
    ],
    'password_hash_test' => $hashTest,
    'database_test' => $dbTest,
];

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo $json !== false ? $json : '';
echo PHP_EOL;

if (!$extensionStatus['openssl'] || !$hashTest['ok']) {
    exit(1);
}

exit(0);
