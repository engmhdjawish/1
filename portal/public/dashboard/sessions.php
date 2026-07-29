<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

header('Location: /dashboard/visitor-analytics.php?tab=now', true, 302);
exit;
