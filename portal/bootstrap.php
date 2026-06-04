<?php

declare(strict_types=1);

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

if (!class_exists('Portal\\Bootstrap')) {
    require __DIR__ . '/src/Bootstrap.php';
}

\Portal\Bootstrap::init(__DIR__);
