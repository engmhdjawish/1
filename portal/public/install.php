<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/?' . ($query !== '' ? $query . '&' : '') . 'install=1';
header('Location: ' . $target, true, 302);
exit;
