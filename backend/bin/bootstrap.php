<?php

declare(strict_types=1);

// Shared bootstrap for CLI entry points (worker, cleanup, health probe).
require __DIR__ . '/../vendor/autoload.php';

return App\Container::fromEnvironment();
