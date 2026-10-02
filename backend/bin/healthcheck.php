<?php

declare(strict_types=1);

/** Docker healthcheck for the php-fpm container: yt-dlp and FFmpeg must run. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$container = require __DIR__ . '/bootstrap.php';
$h = $container->health()->health();
exit($h['yt_dlp'] && $h['ffmpeg'] ? 0 : 1);
