<?php

declare(strict_types=1);

/**
 * Web app manifest checks: identity, canonical-host rule, short_name, icons, headers (no database required).
 * Run: php portal/scripts/test-pwa-manifest.php
 */

define('PORTAL_NO_SESSION', true);
require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/views/helpers.php';

$failed = 0;
$assert = static function (string $label, bool $condition, string $detail = '') use (&$failed): void {
    if ($condition) {
        echo "ok   {$label}\n";

        return;
    }
    echo "FAIL {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    $failed++;
};

$setAppUrl = static function (string $appUrl): void {
    putenv('PORTAL_APP_URL=' . $appUrl);
    $_ENV['PORTAL_APP_URL'] = $appUrl;
};
$setRequest = static function (string $host): void {
    $_SERVER['HTTP_HOST'] = $host;
    $_SERVER['HTTPS'] = 'on';
};

echo "== short_name never cuts a word ==\n";
$assert('"جاويش للتجارة" (13 chars) becomes "جاويش", not "جاويش للتجار"', portal_pwa_short_name('جاويش للتجارة') === 'جاويش', portal_pwa_short_name('جاويش للتجارة'));
$assert('a name that fits is kept whole', portal_pwa_short_name('متجر جاويش') === 'متجر جاويش');
$assert('exactly 12 characters is kept whole', portal_pwa_short_name('abcdef ghijk') === 'abcdef ghijk');
$assert('13 characters drops the last whole word instead of cutting it', portal_pwa_short_name('abcdef ghijkl') === 'abcdef');
$assert('keeps as many whole leading words as fit', portal_pwa_short_name('شركة جاويش للتجارة والتوزيع') === 'شركة جاويش');
$assert('collapses runs of whitespace and trims', portal_pwa_short_name("  جاويش \n  للتجارة  ") === 'جاويش');
$assert('empty name stays empty', portal_pwa_short_name('   ') === '');
$assert('a single over-long word is cut to the limit as a last resort', portal_pwa_short_name('ABCDEFGHIJKLMNOP') === 'ABCDEFGHIJKL');
$assert('respects a custom limit', portal_pwa_short_name('Jawish Trading', 6) === 'Jawish');
foreach (['جاويش للتجارة', 'شركة جاويش للتجارة والتوزيع', 'Jawish Trading Company Limited', 'متجر الأمل الكبير للجملة'] as $sample) {
    $short = portal_pwa_short_name($sample);
    $words = explode(' ', $short);
    $original = explode(' ', $sample);
    $assert(
        "short_name of \"{$sample}\" is a whole-word prefix within 12 chars",
        \Portal\Support\Utf8Text::length($short) <= 12 && $words === array_slice($original, 0, count($words)),
        $short
    );
}

echo "== manifest origin: canonical www is pinned, apex stays same-origin ==\n";
$setAppUrl('https://www.jawishco.sy');
$setRequest('www.jawishco.sy');
$assert('www request -> canonical www origin', portal_manifest_origin() === 'https://www.jawishco.sy');
$setRequest('WWW.JAWISHCO.SY:443');
$assert('Host header casing/port cannot change the canonical origin', portal_manifest_origin() === 'https://www.jawishco.sy');
$setRequest('jawishco.sy');
$assert('apex request -> apex origin (a manifest cannot point cross-origin)', portal_manifest_origin() === 'https://jawishco.sy');
$setAppUrl('https://jawishco.sy');
$setRequest('www.jawishco.sy');
$assert('apex PORTAL_APP_URL still pins the canonical origin to www', portal_manifest_origin() === 'https://www.jawishco.sy');
$setRequest('jawishco.sy');
$assert('apex PORTAL_APP_URL: apex request stays on apex', portal_manifest_origin() === 'https://jawishco.sy');
$assert('icon revision is bumped past 6 and is a plain integer', portal_pwa_icon_revision() >= 7);

echo "== rendered manifest over HTTP ==\n";
$publicDir = dirname(__DIR__) . '/public';
$spawn = static function (array $env) use ($publicDir): ?array {
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        return null;
    }
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $publicDir],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $publicDir,
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
$fetch = static function (int $port, string $host, string $path): array {
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => 10,
        'ignore_errors' => true,
        'header' => "Host: {$host}\r\nX-Forwarded-Proto: https\r\n",
    ]]);
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

$tmpStorage = sys_get_temp_dir() . '/portal-manifest-test-' . bin2hex(random_bytes(4));
$checkManifest = static function (array $manifest, string $host, string $origin) use ($assert): void {
    $assert("{$host}: id is unchanged '/?source=pwa' (changing it would orphan every installed app)", ($manifest['id'] ?? null) === '/?source=pwa', (string) ($manifest['id'] ?? ''));
    $assert("{$host}: start_url is {$origin}/?source=pwa", ($manifest['start_url'] ?? '') === $origin . '/?source=pwa', (string) ($manifest['start_url'] ?? ''));
    $assert("{$host}: scope is {$origin}/ and contains start_url", ($manifest['scope'] ?? '') === $origin . '/' && str_starts_with((string) ($manifest['start_url'] ?? ''), (string) ($manifest['scope'] ?? '?')));
    $assert("{$host}: display is standalone with no display_override / prefer_related_applications", ($manifest['display'] ?? '') === 'standalone' && !isset($manifest['display_override']) && empty($manifest['prefer_related_applications']));
    $assert("{$host}: name present, short_name <= 12 chars and not cut mid-word", trim((string) ($manifest['name'] ?? '')) !== ''
        && \Portal\Support\Utf8Text::length((string) ($manifest['short_name'] ?? '')) > 0
        && \Portal\Support\Utf8Text::length((string) ($manifest['short_name'] ?? '')) <= 12
        && ($manifest['short_name'] ?? '') !== 'جاويش للتجار', (string) ($manifest['short_name'] ?? ''));
    $assert("{$host}: rtl Arabic with theme colors", ($manifest['dir'] ?? '') === 'rtl' && ($manifest['lang'] ?? '') === 'ar' && preg_match('/^#[0-9a-f]{6}$/i', (string) ($manifest['theme_color'] ?? '')) === 1 && preg_match('/^#[0-9a-f]{6}$/i', (string) ($manifest['background_color'] ?? '')) === 1);

    $icons = $manifest['icons'] ?? [];
    $sizes = [];
    $iconsOk = $icons !== [];
    foreach ($icons as $icon) {
        $src = (string) ($icon['src'] ?? '');
        $iconsOk = $iconsOk && str_starts_with($src, $origin . '/') && str_contains($src, 'webapk=' . portal_pwa_icon_revision()) && ($icon['type'] ?? '') === 'image/png';
        $sizes[] = ($icon['sizes'] ?? '') . ':' . ($icon['purpose'] ?? '');
    }
    $assert("{$host}: icons are same-origin PNGs carrying the bumped cache-buster", $iconsOk);
    $assert("{$host}: icons cover 192 any, 512 any and 512 maskable", in_array('192x192:any', $sizes, true) && in_array('512x512:any', $sizes, true) && in_array('512x512:maskable', $sizes, true), implode(',', $sizes));

    $urls = [(string) ($manifest['start_url'] ?? ''), (string) ($manifest['scope'] ?? '')];
    foreach ($manifest['shortcuts'] ?? [] as $shortcut) {
        $urls[] = (string) ($shortcut['url'] ?? '');
        foreach ($shortcut['icons'] ?? [] as $shortcutIcon) {
            $urls[] = (string) ($shortcutIcon['src'] ?? '');
        }
    }
    foreach ($manifest['related_applications'] ?? [] as $related) {
        $urls[] = (string) ($related['url'] ?? '');
    }
    foreach ($icons as $icon) {
        $urls[] = (string) ($icon['src'] ?? '');
    }
    $sameOrigin = true;
    foreach ($urls as $url) {
        $sameOrigin = $sameOrigin && str_starts_with($url, $origin . '/');
    }
    $assert("{$host}: every URL in the manifest (start, scope, icons, shortcuts, related app) is same-origin", $sameOrigin && count($urls) >= 8);
};

$server = $spawn([
    'PORTAL_APP_URL' => 'https://www.jawishco.sy',
    'PORTAL_STORAGE_PATH' => $tmpStorage,
    'PORTAL_FORCE_HTTPS' => '0',
]);
if ($server === null) {
    echo "SKIP HTTP checks (could not start php -S)\n";
} else {
    $www = $fetch($server['port'], 'www.jawishco.sy', '/manifest.php');
    $apex = $fetch($server['port'], 'jawishco.sy', '/manifest.php');
    $wwwManifest = json_decode($www['body'], true);
    $apexManifest = json_decode($apex['body'], true);

    $assert('www manifest returns 200 application/manifest+json', $www['status'] === 200 && str_starts_with(strtolower((string) $headerValue($www['headers'], 'Content-Type')), 'application/manifest+json'), (string) $www['status']);
    $assert('manifest is cached for at most 60s and must revalidate', $headerValue($www['headers'], 'Cache-Control') === 'public, max-age=60, must-revalidate', (string) $headerValue($www['headers'], 'Cache-Control'));
    $assert('apex manifest returns 200', $apex['status'] === 200, (string) $apex['status']);

    if (is_array($wwwManifest)) {
        $checkManifest($wwwManifest, 'www.jawishco.sy', 'https://www.jawishco.sy');
    } else {
        $assert('www manifest is valid JSON', false, substr($www['body'], 0, 120));
    }
    if (is_array($apexManifest)) {
        $checkManifest($apexManifest, 'jawishco.sy', 'https://jawishco.sy');
        $assert('apex manifest never points at www (cross-origin start_url would be ignored by Chrome)', !str_contains($apex['body'], 'www.jawishco.sy'));
    } else {
        $assert('apex manifest is valid JSON', false, substr($apex['body'], 0, 120));
    }
    if (is_array($wwwManifest) && is_array($apexManifest)) {
        $assert('www and apex expose the same identity (id), name and short_name', ($wwwManifest['id'] ?? 1) === ($apexManifest['id'] ?? 2) && ($wwwManifest['name'] ?? 1) === ($apexManifest['name'] ?? 2) && ($wwwManifest['short_name'] ?? 1) === ($apexManifest['short_name'] ?? 2));
    }

    proc_terminate($server['process']);
    proc_close($server['process']);
}

$staticPath = $publicDir . '/manifest.webmanifest';
$static = json_decode((string) file_get_contents($staticPath), true);
$assert(
    'static manifest.webmanifest mirrors the dynamic one (relative URLs, same id, bumped icons)',
    is_array($static)
    && ($static['id'] ?? null) === '/?source=pwa'
    && ($static['start_url'] ?? null) === '/?source=pwa'
    && ($static['display'] ?? null) === 'standalone'
    && !str_contains((string) file_get_contents($staticPath), 'https://')
    && str_contains((string) file_get_contents($staticPath), 'webapk=' . portal_pwa_icon_revision())
);

foreach (glob($tmpStorage . '/cache/*') ?: [] as $leftover) {
    @unlink($leftover);
}
@rmdir($tmpStorage . '/cache');
@rmdir($tmpStorage);

echo $failed === 0 ? "\nAll manifest checks passed.\n" : "\n{$failed} manifest check(s) FAILED.\n";
exit($failed === 0 ? 0 : 1);
