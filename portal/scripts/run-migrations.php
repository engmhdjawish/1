<?php

declare(strict_types=1);

/**
 * Applies incremental portal SQL migrations (docs/portal-migrations + standalone files).
 *
 * Usage:
 *   php scripts/run-migrations.php
 *   php scripts/run-migrations.php --list
 */

$base = dirname(__DIR__);
define('PORTAL_NO_SESSION', true);
require $base . '/bootstrap.php';

use Portal\Config;
use Portal\Database;

$docs = Config::get('PORTAL_REPO_DOCS_PATH', $base . '/../docs') ?? ($base . '/../docs');
if (!preg_match('/^[A-Za-z]:[\/\\\\]/', $docs) && !str_starts_with($docs, '/') && !str_starts_with($docs, '\\\\')) {
    $docs = $base . '/' . ltrim($docs, '/\\');
}
$docs = realpath($docs) ?: $docs;

$migrationDir = $docs . '/portal-migrations';
$standalone = [
    $docs . '/portal-migration-order-item-edits.sql',
    $docs . '/portal-migration-special-offers.sql',
];

$ordered = [
    '001-site-media-assets.sql',
    '002-company-about-settings.sql',
    '002-material-image-sync-queue.sql',
    '003-material-images-local.sql',
    '003-ensure-content-permissions.sql',
    '004-material-image-sync-fingerprint.sql',
    '004-ensure-accounting-permissions.sql',
    '005-visitor-logs.sql',
    '006-notifications.sql',
    '008-push-subscriptions.sql',
    '009-web-sessions-tracking.sql',
    '010-sessions-permission.sql',
    '007-staff-roles-reorganization.sql',
];

$files = [];
foreach ($ordered as $name) {
    $path = $migrationDir . '/' . $name;
    if (is_file($path)) {
        $files[] = $path;
    }
}
foreach ($standalone as $path) {
    if (is_file($path)) {
        $files[] = $path;
    }
}

if ($argc > 1 && in_array($argv[1], ['--list', '-l'], true)) {
    foreach ($files as $file) {
        echo basename($file), PHP_EOL;
    }
    exit(0);
}

$host = Config::get('PORTAL_DB_HOST', '127.0.0.1');
$port = Config::get('PORTAL_DB_PORT', '5432');
$name = Config::get('PORTAL_DB_NAME', 'portal_db');
$user = Config::get('PORTAL_DB_USER', 'portal');
$password = Config::get('PORTAL_DB_PASSWORD', 'portal');
$psqlBin = Config::get('PORTAL_PSQL_BIN', 'psql') ?? 'psql';

$pdo = Database::pdo();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS portal_schema_migrations (
        filename VARCHAR(255) PRIMARY KEY,
        applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )'
);

$appliedStmt = $pdo->prepare('SELECT 1 FROM portal_schema_migrations WHERE filename = :f LIMIT 1');
$insertStmt = $pdo->prepare('INSERT INTO portal_schema_migrations (filename) VALUES (:f) ON CONFLICT DO NOTHING');

putenv('PGPASSWORD=' . $password);
putenv('PGCLIENTENCODING=UTF8');
$psqlBase = sprintf(
    '%s -h %s -p %s -U %s -d %s',
    escapeshellarg($psqlBin),
    escapeshellarg($host),
    escapeshellarg($port),
    escapeshellarg($user),
    escapeshellarg($name)
);

$ran = 0;
$skipped = 0;

foreach ($files as $file) {
    $filename = basename($file);
    $appliedStmt->execute(['f' => $filename]);
    if ($appliedStmt->fetchColumn()) {
        echo "تخطي (مطبّق مسبقاً): $filename\n";
        $skipped++;
        continue;
    }

    echo "تطبيق: $filename ...\n";
    passthru("$psqlBase -f " . escapeshellarg($file), $code);
    if ($code !== 0) {
        fwrite(STDERR, "فشل تطبيق: $filename (رمز $code)\n");
        exit($code);
    }

    $insertStmt->execute(['f' => $filename]);
    $ran++;
}

echo "انتهى — طُبِّق $ran ملفاً، تُخطّى $skipped.\n";
exit(0);
