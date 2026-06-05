<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Portal\Auth\CustomerSession;
use Portal\Auth\WebSession;

WebSession::logout();
CustomerSession::logout();
header('Location: /index.php');
