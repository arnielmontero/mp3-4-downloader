<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use App\Support\ApiException;

/**
 * Sliding-window rate limiter keyed by (bucket, client IP), stored as small files under
 * storage/temp/_ratelimit. Limits are configurable through environment variables.
 */
final class RateLimitService
{
    public function __construct(private AppConfig $config)
    {
    }

    /** Count a request against the bucket and throw RATE_LIMITED when the limit is exceeded. */
    public function hit(string $bucket, string $clientIp, int $limit, ?int $now = null): void
    {
        $now ??= time();
        $window = $this->config->rateLimitWindowSeconds;
        $dir = $this->config->path('temp', '_ratelimit');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', $bucket . '|' . $clientIp) . '.json';
        $fh = @fopen($file, 'c+b');
        if ($fh === false) {
            return; // fail open: a broken limiter must not take the API down
        }
        flock($fh, LOCK_EX);
        try {
            $stamps = json_decode((string) stream_get_contents($fh), true);
            $stamps = is_array($stamps) ? array_values(array_filter($stamps, static fn ($t): bool => is_int($t) && $t > $now - $window)) : [];
            if (count($stamps) >= $limit) {
                $retry = max(1, $stamps[0] + $window - $now);
                throw new ApiException('RATE_LIMITED', 'Too many requests. Please wait a moment and try again.', 429, ['retry_after' => $retry]);
            }
            $stamps[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($stamps));
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** Remove limiter files that have not been touched for longer than the window. */
    public function purge(): int
    {
        $dir = $this->config->path('temp', '_ratelimit');
        $removed = 0;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (filemtime($file) < time() - $this->config->rateLimitWindowSeconds * 2 && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }
}
