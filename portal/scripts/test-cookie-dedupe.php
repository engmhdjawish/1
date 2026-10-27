<?php

declare(strict_types=1);

/**
 * Duplicate-cookie healing (legacy host-only portal_session next to the Domain cookie).
 * Needs no database. Run: php portal/scripts/test-cookie-dedupe.php
 *
 * The HTTP part drives the real Bootstrap (session start, cookie options) through php -S and replays what a
 * browser does: it keeps a cookie jar that separates host-only from Domain cookies and sends them oldest first.
 */

define('PORTAL_NO_SESSION', true);
require dirname(__DIR__) . '/bootstrap.php';

use Portal\Support\CookieDeduper;

$failed = 0;
$assert = static function (string $label, bool $condition, string $detail = '') use (&$failed): void {
    if ($condition) {
        echo "ok   {$label}\n";

        return;
    }
    echo "FAIL {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    $failed++;
};

echo "== parsing and duplicate detection ==\n";
$pairs = CookieDeduper::parseHeader('a=1; portal_session=OLD; b=two%20words; portal_session=NEW;  ; novalue; =x');
$assert('parseHeader keeps order, decodes values, skips junk', $pairs === [['a', '1'], ['portal_session', 'OLD'], ['b', 'two words'], ['portal_session', 'NEW']], json_encode($pairs));
$assert('duplicates() reports only repeated wanted names, in header order', CookieDeduper::duplicates('portal_session=OLD; x=1; portal_session=NEW; y=1; y=2', ['portal_session']) === ['portal_session' => ['OLD', 'NEW']]);
$assert('a single cookie is not a duplicate', CookieDeduper::duplicates('portal_session=ONLY; x=1', ['portal_session']) === []);
$assert('same value twice still counts (the legacy copy must go before the next login)', CookieDeduper::duplicates('portal_session=SAME; portal_session=SAME', ['portal_session']) === ['portal_session' => ['SAME', 'SAME']]);
$assert('unrelated duplicated cookies are ignored', CookieDeduper::duplicates('other=1; other=2', ['portal_session']) === []);

$_COOKIE = ['portal_session' => 'OLD', 'jawish_staff' => 's1'];
$result = CookieDeduper::normalizeRequest(['portal_session', 'jawish_staff'], 'portal_session=OLD; jawish_staff=s1; portal_session=NEW');
$assert('normalizeRequest points $_COOKIE at the newest copy and reports the name', ($_COOKIE['portal_session'] ?? '') === 'NEW' && $_COOKIE['jawish_staff'] === 's1' && $result === ['portal_session'], json_encode([$_COOKIE, $result]));
$_COOKIE = ['portal_session' => 'ONLY'];
$assert('normalizeRequest leaves single cookies untouched', CookieDeduper::normalizeRequest(['portal_session'], 'portal_session=ONLY') === [] && $_COOKIE['portal_session'] === 'ONLY');
$assert('normalizeRequest tolerates an empty header', CookieDeduper::normalizeRequest(['portal_session'], '') === []);
$assert('app cookie list covers the session, staff-device and price cookies', CookieDeduper::appCookieNames('portal_session') === ['portal_session', 'jawish_staff', 'store_price_currency']);

echo "== browser replay over HTTP ==\n";
$portalRoot = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/portal-cookie-dedupe-' . bin2hex(random_bytes(4));
$docroot = $tmp . '/docroot';
$sessions = $tmp . '/sessions';
mkdir($docroot, 0775, true);
mkdir($sessions, 0775, true);
file_put_contents($docroot . '/probe.php', '<?php
require ' . var_export($portalRoot . '/bootstrap.php', true) . ';
if (($_GET["a"] ?? "") === "login") {
    session_regenerate_id(true);
    $_SESSION["probe_user"] = "customer-1";
}
header("Content-Type: text/plain");
echo "user=" . ($_SESSION["probe_user"] ?? "guest") . "\nsid=" . session_id() . "\n";
');

$spawn = static function (array $env) use ($docroot): ?array {
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        return null;
    }
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    $process = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $env['SESSION_PATH'], '-S', '127.0.0.1:' . $port, '-t', $docroot],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $docroot,
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

/** Minimal browser cookie jar: host-only and Domain cookies are different cookies; oldest is sent first. */
final class Jar
{
    /** @var array<string, array{name: string, value: string, hostOnly: bool, seq: int}> */
    public array $cookies = [];
    private int $seq = 0;

    public function seed(string $name, string $value, bool $hostOnly): void
    {
        $key = ($hostOnly ? 'h:' : 'd:') . $name;
        $this->cookies[$key] = ['name' => $name, 'value' => $value, 'hostOnly' => $hostOnly, 'seq' => ++$this->seq];
    }

    public function apply(array $headers): void
    {
        foreach ($headers as $line) {
            if (stripos((string) $line, 'Set-Cookie:') !== 0) {
                continue;
            }
            $parts = array_map('trim', explode(';', trim(substr((string) $line, 11))));
            [$name, $value] = array_pad(explode('=', (string) array_shift($parts), 2), 2, '');
            $hostOnly = true;
            $expired = false;
            foreach ($parts as $attribute) {
                $lower = strtolower($attribute);
                if (str_starts_with($lower, 'domain=')) {
                    $hostOnly = false;
                }
                if ($lower === 'max-age=0' || (str_starts_with($lower, 'expires=') && strtotime(substr($attribute, 8)) < time())) {
                    $expired = true;
                }
            }
            $key = ($hostOnly ? 'h:' : 'd:') . $name;
            if ($expired) {
                unset($this->cookies[$key]);
                continue;
            }
            $seq = $this->cookies[$key]['seq'] ?? ++$this->seq;
            $this->cookies[$key] = ['name' => $name, 'value' => $value, 'hostOnly' => $hostOnly, 'seq' => $seq];
        }
    }

    public function header(): string
    {
        $list = $this->cookies;
        uasort($list, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);

        return implode('; ', array_map(static fn (array $c): string => $c['name'] . '=' . $c['value'], $list));
    }

    public function value(string $name, bool $hostOnly): ?string
    {
        return $this->cookies[($hostOnly ? 'h:' : 'd:') . $name]['value'] ?? null;
    }
}

$visit = static function (int $port, Jar $jar, string $query) use ($assert): array {
    $headerLines = "Host: jawishco.sy\r\nX-Forwarded-Proto: https\r\n";
    if ($jar->header() !== '') {
        $headerLines .= 'Cookie: ' . $jar->header() . "\r\n";
    }
    $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 10, 'ignore_errors' => true, 'header' => $headerLines]]);
    $body = (string) @file_get_contents('http://127.0.0.1:' . $port . '/probe.php?a=' . $query, false, $context);
    $headers = $http_response_header ?? [];
    $jar->apply($headers);
    preg_match('/^user=(.*)$/m', $body, $user);
    preg_match('/^sid=(.*)$/m', $body, $sid);

    return ['user' => $user[1] ?? '', 'sid' => $sid[1] ?? '', 'headers' => $headers, 'body' => $body];
};
$setCookieLines = static fn (array $headers): array => array_values(array_filter($headers, static fn ($h): bool => stripos((string) $h, 'Set-Cookie:') === 0));

$server = $spawn([
    'PORTAL_APP_URL' => 'https://www.jawishco.sy',
    'PORTAL_STORAGE_PATH' => $tmp . '/storage',
    'PORTAL_FORCE_HTTPS' => '0',
    'PORTAL_SESSION_NAME' => 'portal_session',
    'SESSION_PATH' => $sessions,
]);
if ($server === null) {
    echo "SKIP HTTP checks (could not start php -S)\n";
} else {
    $port = $server['port'];
    $legacy = 'LEGACYHOSTONLYSESSION0001';

    // 0. A brand-new visitor: exactly one session Set-Cookie, a Domain cookie, no cleanup noise.
    $fresh = new Jar();
    $first = $visit($port, $fresh, 'whoami');
    $cookies = $setCookieLines($first['headers']);
    $assert('new visitor gets a single Domain session cookie (no identical duplicate header)', count($cookies) === 1 && stripos($cookies[0], 'domain=.jawishco.sy') !== false && stripos($cookies[0], 'portal_session=' . $first['sid']) !== false, json_encode($cookies));

    // 1. Legacy host-only cookie older than the Domain cookie, as every pre-Oct-25 visitor has.
    $jar = new Jar();
    $jar->seed('portal_session', $legacy, true);
    $jar->seed('portal_session', $first['sid'], false);
    $assert('fixture: the browser sends the legacy host-only cookie first', str_starts_with($jar->header(), 'portal_session=' . $legacy), $jar->header());

    $view = $visit($port, $jar, 'whoami');
    $assert('server reads the Domain (newest) cookie, not the stale host-only one', $view['sid'] === $first['sid'], $view['sid']);
    $expiry = array_values(array_filter($setCookieLines($view['headers']), static fn (string $h): bool => stripos($h, 'portal_session=;') !== false || stripos($h, 'portal_session=deleted') !== false));
    $assert('server expires the legacy host-only cookie (Set-Cookie without a Domain attribute)', count($expiry) === 1 && stripos($expiry[0], 'domain=') === false && stripos($expiry[0], 'path=/') !== false, json_encode($expiry));
    $assert('after the response the browser no longer holds the host-only cookie', $jar->value('portal_session', true) === null);
    $assert('the Domain cookie survives the cleanup', $jar->value('portal_session', false) === $first['sid']);

    // 2. Login then comes back as a signed-in customer.
    $login = $visit($port, $jar, 'login');
    $assert('login regenerates the session id', $login['user'] === 'customer-1' && $login['sid'] !== $first['sid'] && $login['sid'] !== '');
    $home = $visit($port, $jar, 'whoami');
    $assert('the very next request (the redirect to home) is still signed in', $home['user'] === 'customer-1' && $home['sid'] === $login['sid'], json_encode([$home['user'], $home['sid'], $login['sid']]));
    $again = $visit($port, $jar, 'whoami');
    $assert('and it keeps being signed in on later requests', $again['user'] === 'customer-1');

    // 3. Worst case: the stale host-only cookie is still around when the login happens.
    $jar2 = new Jar();
    $jar2->seed('portal_session', $legacy, true);
    $jar2->seed('portal_session', $first['sid'], false);
    $login2 = $visit($port, $jar2, 'login');
    $assert('login still works while both cookies are present', $login2['user'] === 'customer-1');
    $stale = new Jar();
    $stale->seed('portal_session', $legacy, true);
    $stale->seed('portal_session', $login2['sid'], false);
    $afterLogin = $visit($port, $stale, 'whoami');
    $assert('even if the browser ignored the cleanup, the post-login request is signed in (newest copy wins)', $afterLogin['user'] === 'customer-1' && $afterLogin['sid'] === $login2['sid'], json_encode([$afterLogin['user'], $afterLogin['sid']]));

    // 4. A plain single cookie is untouched: no cleanup headers.
    $single = new Jar();
    $single->seed('portal_session', $login['sid'], false);
    $plain = $visit($port, $single, 'whoami');
    $assert('a clean browser gets no expiry headers and keeps its session', $plain['user'] === 'customer-1' && count(array_filter($setCookieLines($plain['headers']), static fn (string $h): bool => stripos($h, 'domain=') === false)) === 0, json_encode($setCookieLines($plain['headers'])));

    proc_terminate($server['process']);
    proc_close($server['process']);
}

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
$rrmdir($tmp);

echo $failed === 0 ? "\nAll cookie dedupe checks passed.\n" : "\n{$failed} cookie dedupe check(s) FAILED.\n";
exit($failed === 0 ? 0 : 1);
