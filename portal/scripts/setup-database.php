<?php

declare(strict_types=1);

/**
 * Creates portal_db schema + seed.
 * Usage: php scripts/setup-database.php
 */

$base = dirname(__DIR__);
require $base . '/bootstrap.php';

use Portal\Config;

$docs = Config::get('PORTAL_REPO_DOCS_PATH', $base . '/../docs') ?? $base . '/../docs';
$schema = realpath($docs . '/portal-db-schema.sql');
$seed = realpath($docs . '/portal-db-seed.sql');

if ($schema === false || $seed === false) {
    fwrite(STDERR, "لم يُعثر على ملفات SQL في: $docs\n");
    exit(1);
}

$host = Config::get('PORTAL_DB_HOST', '127.0.0.1');
$port = Config::get('PORTAL_DB_PORT', '5432');
$name = Config::get('PORTAL_DB_NAME', 'portal_db');
$user = Config::get('PORTAL_DB_USER', 'portal');
$password = Config::get('PORTAL_DB_PASSWORD', 'portal');

$psqlBase = sprintf(
    'PGPASSWORD=%s psql -h %s -p %s -U %s -d %s',
    escapeshellarg($password),
    escapeshellarg($host),
    escapeshellarg($port),
    escapeshellarg($user),
    escapeshellarg($name)
);

echo "تنفيذ المخطط...\n";
passthru("$psqlBase -f " . escapeshellarg($schema), $code1);
if ($code1 !== 0) {
    exit($code1);
}

echo "تنفيذ البذور...\n";
passthru("$psqlBase -f " . escapeshellarg($seed), $code2);
exit($code2);
