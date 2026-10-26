<?php

declare(strict_types=1);

/**
 * Short share-link entry: /s/{code} (via nginx rewrite) or /s.php?c={code}.
 * Delegates to share.php after normalizing the access key into $_GET['c'].
 */

$code = trim((string) ($_GET['c'] ?? $_GET['t'] ?? $_GET['code'] ?? ''));

if ($code === '' && !empty($_SERVER['PATH_INFO'])) {
    $code = trim((string) preg_replace('#^/+#', '', (string) $_SERVER['PATH_INFO']));
}

if ($code === '' && !empty($_SERVER['REQUEST_URI'])) {
    $path = (string) (parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '');
    if (preg_match('#^/s/([A-Za-z0-9_-]+)/?$#', $path, $m) === 1) {
        $code = $m[1];
    }
}

if ($code !== '') {
    $_GET['c'] = $code;
    $_REQUEST['c'] = $code;
    if (!isset($_GET['token']) && !isset($_GET['t'])) {
        $_GET['t'] = $code;
        $_REQUEST['t'] = $code;
    }
}

require __DIR__ . '/share.php';
