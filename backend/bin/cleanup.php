<?php

declare(strict_types=1);

/** One-off cleanup run (the worker also runs it on a schedule). Run: php bin/cleanup.php */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$container = require __DIR__ . '/bootstrap.php';
echo json_encode($container->cleanup()->run()), PHP_EOL;
