<?php

declare(strict_types=1);

/** Docker healthcheck for the worker container: exit 0 when the heartbeat is fresh. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$container = require __DIR__ . '/bootstrap.php';
exit($container->health()->workerStatus()['alive'] ? 0 : 1);
