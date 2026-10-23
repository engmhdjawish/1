<?php

declare(strict_types=1);

$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
header('Location: /store.php' . ($query !== '' ? '?' . $query : ''), true, 301);
exit;
