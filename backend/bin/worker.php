<?php

declare(strict_types=1);

/** Download worker daemon. Run: php bin/worker.php */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$container = require __DIR__ . '/bootstrap.php';
exit($container->worker()->run());
