<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Auth\WebSession;

// Browser push cleanup runs in notifications.js before navigating here
// (unsubscribe endpoint while the session is still valid).
WebSession::logout();
CustomerSession::logout();
header('Location: /index.php');
