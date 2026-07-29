<?php

declare(strict_types=1);

/**
 * Background worker for material image ZIP jobs (CLI — does not block PHP-FPM).
 *
 * Usage: php scripts/build-material-zip-job.php
 */

$base = dirname(__DIR__);
define('PORTAL_NO_SESSION', true);
require $base . '/bootstrap.php';

use Portal\Services\MaterialImageZipJobService;

MaterialImageZipJobService::runWorker();
