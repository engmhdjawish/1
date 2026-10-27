<?php

declare(strict_types=1);

/**
 * PWA build id + /api/app-version.php checks (no database required).
 * Run: php portal/scripts/test-pwa-build-version.php
 */

define('PORTAL_NO_SESSION', true);
require dirname(__DIR__) . '/bootstrap.php';

use Portal\Support\PwaBuild;

$failed = 0;
$assert = static function (string $label, bool $condition, string $detail = '') use (&$failed): void {
    if ($condition) {
        echo "ok   {$label}\n";

        return;
    }
    echo "FAIL {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    $failed++;
};

$rrmdir = static function (string $dir) use (&$rrmdir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) && !is_link($path) ? $rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
};

$tmp = sys_get_temp_dir() . '/portal-pwa-build-' . bin2hex(random_bytes(4));
$public = $tmp . '/public';
$cache = $tmp . '/cache';
mkdir($public . '/assets/dashboard', 0775, true);
mkdir($public . '/css', 0775, true);
mkdir($public . '/icons', 0775, true);
file_put_contents($public . '/sw.js', "// sw v1\n");
file_put_contents($public . '/offline.html', "<html>offline</html>\n");
file_put_contents($public . '/assets/app.js', "console.log('app');\n");
file_put_contents($public . '/assets/dashboard/dash.js', "console.log('dash');\n");
file_put_contents($public . '/css/site.css', "body{color:#000}\n");
file_put_contents($public . '/icons/icon-192.png', 'png-bytes');
file_put_contents($public . '/robots.txt', "User-agent: *\n");

echo "== content hash ==\n";
$first = PwaBuild::compute($public);
$assert('build id is 12 hex chars', preg_match('/^[0-9a-f]{12}$/', $first) === 1, $first);
$assert('build id is stable across calls', PwaBuild::compute($public) === $first);

touch($public . '/assets/app.js', time() + 3600);
clearstatcache();
$assert('touching a file (mtime only) keeps the id', PwaBuild::compute($public) === $first);

file_put_contents($public . '/icons/icon-192.png', 'other-png-bytes');
file_put_contents($public . '/robots.txt', "User-agent: bot\n");
file_put_contents($public . '/notes.txt', "not shipped\n");
$assert('non-client files (icons, robots, notes) do not change the id', PwaBuild::compute($public) === $first);

file_put_contents($public . '/assets/app.js', "console.log('app v2');\n");
$afterJs = PwaBuild::compute($public);
$assert('editing a shipped JS file changes the id', $afterJs !== $first);

file_put_contents($public . '/css/site.css', "body{color:#111}\n");
$afterCss = PwaBuild::compute($public);
$assert('editing a shipped CSS file changes the id', $afterCss !== $afterJs);

file_put_contents($public . '/sw.js', "// sw v2\n");
$afterSw = PwaBuild::compute($public);
$assert('editing sw.js changes the id', $afterSw !== $afterCss);

file_put_contents($public . '/assets/dashboard/dash.js', "console.log('dash v2');\n");
$afterNested = PwaBuild::compute($public);
$assert('editing a nested assets file changes the id', $afterNested !== $afterSw);

file_put_contents($public . '/assets/extra.js', "console.log('extra');\n");
$afterAdd = PwaBuild::compute($public);
$assert('adding a JS file changes the id', $afterAdd !== $afterNested);

rename($public . '/assets/extra.js', $public . '/assets/renamed.js');
$assert('renaming a JS file changes the id', PwaBuild::compute($public) !== $afterAdd);

unlink($public . '/assets/renamed.js');
file_put_contents($public . '/offline.html', "<html>offline v2</html>\n");
$assert('editing offline.html changes the id', PwaBuild::compute($public) !== $afterNested);

$assetFiles = array_keys(PwaBuild::assetFiles($public));
$assert(
    'asset file list is sorted and limited to client code',
    $assetFiles === ['assets/app.js', 'assets/dashboard/dash.js', 'css/site.css', 'offline.html', 'sw.js'],
    implode(',', $assetFiles)
);
$assert('missing public dir still yields a well-formed id', preg_match('/^[0-9a-f]{12}$/', PwaBuild::compute($tmp . '/nope')) === 1);

echo "== cache (file store, TTL) ==\n";
$base = 1_800_000_000;
$a = PwaBuild::resolve($public, $cache, 45, $base, false);
$assert('resolve returns the computed id', $a === PwaBuild::compute($public));
$assert('resolve writes a small JSON cache file', is_file($cache . '/pwa-build.json') && filesize($cache . '/pwa-build.json') < 400);
$leftovers = glob($cache . '/*.tmp') ?: [];
$assert('no temp files are left behind', $leftovers === []);

file_put_contents($public . '/assets/app.js', "console.log('app v3');\n");
$assert('within the TTL the cached id is served', PwaBuild::resolve($public, $cache, 45, $base + 10, false) === $a);
$assert('after the TTL the id is recomputed', PwaBuild::resolve($public, $cache, 45, $base + 46, false) === PwaBuild::compute($public) && PwaBuild::compute($public) !== $a);

file_put_contents($cache . '/pwa-build.json', '{not json');
$assert('corrupt cache file is ignored', PwaBuild::resolve($public, $cache, 45, $base + 100, false) === PwaBuild::compute($public));

file_put_contents($cache . '/pwa-build.json', json_encode(['build' => 'evil<script>', 'public_dir' => $public, 'expires_at' => $base + 9999]));
$assert('cache file with a malformed id is ignored', PwaBuild::resolve($public, $cache, 45, $base + 101, false) === PwaBuild::compute($public));

file_put_contents($cache . '/pwa-build.json', json_encode(['build' => 'aaaaaaaaaaaa', 'public_dir' => '/somewhere/else', 'expires_at' => $base + 9999]));
$assert('cache entry for another public dir is ignored', PwaBuild::resolve($public, $cache, 45, $base + 102, false) === PwaBuild::compute($public));

$unwritable = PwaBuild::resolve($public, '/dev/null/cache', 45, $base, false);
$assert('unwritable cache dir falls back to computing', $unwritable === PwaBuild::compute($public));
$assert('null cache dir falls back to computing', PwaBuild::resolve($public, null, 45, $base, false) === PwaBuild::compute($public));

echo "== operator override (PORTAL_BUILD_ID) ==\n";
$assert('override accepts a short safe token', PwaBuild::sanitizeOverride('2026-10-27.rc1') === '2026-10-27.rc1');
$assert('override rejects spaces', PwaBuild::sanitizeOverride('two words') === null);
$assert('override rejects quotes and angle brackets', PwaBuild::sanitizeOverride('a"b') === null && PwaBuild::sanitizeOverride('<x>') === null);
$assert('override rejects CR/LF (header injection)', PwaBuild::sanitizeOverride("ab\r\nSet-Cookie: x=1") === null);
$assert('override rejects values over 32 chars', PwaBuild::sanitizeOverride(str_repeat('a', 33)) === null && PwaBuild::sanitizeOverride(str_repeat('a', 32)) !== null);
$assert('override ignores empty and null', PwaBuild::sanitizeOverride('') === null && PwaBuild::sanitizeOverride(null) === null);

putenv('PORTAL_BUILD_ID=manual-7');
$_ENV['PORTAL_BUILD_ID'] = 'manual-7';
PwaBuild::resetMemo();
$assert('PwaBuild::id() honours PORTAL_BUILD_ID', PwaBuild::id() === 'manual-7');
putenv('PORTAL_BUILD_ID=bad value');
$_ENV['PORTAL_BUILD_ID'] = 'bad value';
PwaBuild::resetMemo();
$assert('PwaBuild::id() ignores an invalid override', PwaBuild::id() === PwaBuild::resolve(dirname(__DIR__) . '/public', \Portal\Config::storagePath() . '/cache'));
putenv('PORTAL_BUILD_ID');
unset($_ENV['PORTAL_BUILD_ID']);
PwaBuild::resetMemo();

$assert('serviceWorkerUrl uses the ?b= query', PwaBuild::serviceWorkerUrl('abc123def456') === '/sw.js?b=abc123def456');
$assert('isValidId accepts hex and override tokens only', PwaBuild::isValidId('0123456789ab') && PwaBuild::isValidId('manual-7') && !PwaBuild::isValidId('a b') && !PwaBuild::isValidId(''));

echo "== real sources ==\n";
$realPublic = dirname(__DIR__) . '/public';
$realBuild = PwaBuild::compute($realPublic);
$assert('real tree hashes to a 12-hex id', preg_match('/^[0-9a-f]{12}$/', $realBuild) === 1);
$realFiles = array_keys(PwaBuild::assetFiles($realPublic));
$assert(
    'real tree includes sw.js, assets/pwa.js and assets/pwa-update.js (editing the controller changes the build id)',
    in_array('sw.js', $realFiles, true)
    && in_array('assets/pwa.js', $realFiles, true)
    && in_array('assets/pwa-update.js', $realFiles, true)
);
$assert('real tree excludes media and storage uploads', array_filter($realFiles, static fn (string $f): bool => str_starts_with($f, 'media/')) === []);

echo "== HTML heads carry the build meta ==\n";
$headFiles = [
    'views/partials/head-icons.php' => 'shared head partial (layout.php + dashboard/layout.php)',
    'public/pwa-check.php' => 'pwa-check.php',
    'public/pwa-id.php' => 'pwa-id.php',
];
foreach ($headFiles as $relative => $label) {
    $src = (string) file_get_contents(dirname(__DIR__) . '/' . $relative);
    $assert("{$label} renders <meta name=\"portal-build\">", str_contains($src, '<meta name="portal-build" content="<?= h('));
}
$layout = (string) file_get_contents(dirname(__DIR__) . '/views/layout.php');
$dashLayout = (string) file_get_contents(dirname(__DIR__) . '/views/dashboard/layout.php');
$assert('layout.php includes head-icons.php', str_contains($layout, 'partials/head-icons.php'));
$assert('dashboard/layout.php includes head-icons.php', str_contains($dashLayout, 'partials/head-icons.php'));

ob_start();
$siteName = 'جاويش للتجارة';
$companyLogoUrl = null;
include dirname(__DIR__) . '/views/partials/head-icons.php';
$headHtml = (string) ob_get_clean();
$assert(
    'head-icons.php output contains the current build id',
    preg_match('/<meta name="portal-build" content="([0-9a-f]{12})">/', $headHtml, $m) === 1 && $m[1] === PwaBuild::id()
);
$assert(
    'head-icons.php loads the versioned controller script and links the manifest',
    preg_match('#<script src="/assets/pwa-update\.js\?v=\d+" defer></script>#', $headHtml) === 1
    && str_contains($headHtml, '<link rel="manifest" href="/manifest.php">')
);
$assert(
    'head-icons.php fallback registers /sw.js?b=<same build> and no longer the legacy /sw.js?v=N',
    str_contains($headHtml, "'/sw.js?b=' + encodeURIComponent(build)")
    && str_contains($headHtml, 'var build = "' . PwaBuild::id() . '";')
    && !str_contains($headHtml, 'sw.js?v=')
);

echo "== /api/app-version.php over HTTP ==\n";
$spawn = static function (array $env) use ($realPublic): ?array {
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        return null;
    }
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $realPublic],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $realPublic,
        array_merge(getenv(), $env)
    );
    if (!is_resource($process)) {
        return null;
    }
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($socket !== false) {
            fclose($socket);

            return ['process' => $process, 'port' => $port];
        }
        usleep(100_000);
    }
    proc_terminate($process);

    return null;
};
$request = static function (int $port, string $method, string $path): array {
    $context = stream_context_create(['http' => ['method' => $method, 'timeout' => 10, 'ignore_errors' => true, 'header' => "Host: jawishco.sy\r\nX-Forwarded-Proto: https\r\n"]]);
    $body = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    $headers = $http_response_header ?? [];
    $status = 0;
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', (string) $headers[0], $mm) === 1) {
        $status = (int) $mm[1];
    }

    return ['status' => $status, 'headers' => $headers, 'body' => (string) $body];
};
$headerValue = static function (array $headers, string $name): ?string {
    foreach ($headers as $line) {
        if (stripos((string) $line, $name . ':') === 0) {
            return trim(substr((string) $line, strlen($name) + 1));
        }
    }

    return null;
};

$server = $spawn([
    'PORTAL_APP_URL' => 'https://www.jawishco.sy',
    'PORTAL_STORAGE_PATH' => $tmp . '/server-storage',
    'PORTAL_BUILD_ID' => '',
    'PORTAL_FORCE_HTTPS' => '0',
]);
if ($server === null) {
    echo "SKIP HTTP checks (could not start php -S)\n";
} else {
    $res = $request($server['port'], 'GET', '/api/app-version.php');
    $data = json_decode($res['body'], true);
    $assert('GET returns 200', $res['status'] === 200, (string) $res['status']);
    $assert('GET is JSON', str_starts_with(strtolower((string) $headerValue($res['headers'], 'Content-Type')), 'application/json'));
    $assert('GET sends Cache-Control: no-store, max-age=0', $headerValue($res['headers'], 'Cache-Control') === 'no-store, max-age=0', (string) $headerValue($res['headers'], 'Cache-Control'));
    $assert('GET sets no cookies', $headerValue($res['headers'], 'Set-Cookie') === null);
    $assert('body carries ok + 12-hex build', is_array($data) && ($data['ok'] ?? null) === true && preg_match('/^[0-9a-f]{12}$/', (string) ($data['build'] ?? '')) === 1, $res['body']);
    $assert('endpoint build equals the on-disk content hash', is_array($data) && ($data['build'] ?? '') === PwaBuild::compute($realPublic));
    $assert('body names the matching registration URL', is_array($data) && ($data['sw'] ?? '') === '/sw.js?b=' . ($data['build'] ?? ''));
    $assert('server wrote its cache under PORTAL_STORAGE_PATH', is_file($tmp . '/server-storage/cache/pwa-build.json'));

    $head = $request($server['port'], 'HEAD', '/api/app-version.php');
    $assert('HEAD returns 200 with no-store and no body', $head['status'] === 200 && $head['body'] === '' && $headerValue($head['headers'], 'Cache-Control') === 'no-store, max-age=0');

    $post = $request($server['port'], 'POST', '/api/app-version.php');
    $assert('POST is rejected with 405', $post['status'] === 405 && $headerValue($post['headers'], 'Allow') !== null, (string) $post['status']);

    proc_terminate($server['process']);
    proc_close($server['process']);
}

$overrideServer = $spawn([
    'PORTAL_APP_URL' => 'https://www.jawishco.sy',
    'PORTAL_STORAGE_PATH' => $tmp . '/server-storage-2',
    'PORTAL_BUILD_ID' => 'release-42',
    'PORTAL_FORCE_HTTPS' => '0',
]);
if ($overrideServer !== null) {
    $res = $request($overrideServer['port'], 'GET', '/api/app-version.php');
    $data = json_decode($res['body'], true);
    $assert('endpoint reports PORTAL_BUILD_ID when set', is_array($data) && ($data['build'] ?? '') === 'release-42' && ($data['sw'] ?? '') === '/sw.js?b=release-42', $res['body']);
    proc_terminate($overrideServer['process']);
    proc_close($overrideServer['process']);
}

$rrmdir($tmp);

echo $failed === 0 ? "\nAll PWA build checks passed.\n" : "\n{$failed} PWA build check(s) FAILED.\n";
exit($failed === 0 ? 0 : 1);
