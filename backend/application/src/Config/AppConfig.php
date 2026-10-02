<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Immutable, typed view of the server configuration.
 *
 * Everything is driven by environment variables (see .env.example). The constructor takes a plain
 * array so tests can build a config without touching the process environment.
 */
final class AppConfig
{
    public const VERSION = '1.1.0';

    public string $appEnv;
    public string $storagePath;
    public string $ytdlpBin;
    public string $ffmpegBin;
    public string $ffprobeBin;
    public string $ytdlpJsRuntime;
    public int $maxConcurrentDownloads;
    public int $maxDownloadSizeBytes;
    public int $maxVideoDurationSeconds;
    public int $downloadTimeoutSeconds;
    public int $retentionSeconds;
    public int $completedRetentionSeconds;
    public int $logRetentionDays;
    public int $rateLimitAnalyze;
    public int $rateLimitDownload;
    public int $rateLimitWindowSeconds;
    public int $maxRequestBodyBytes;
    public int $infoTimeoutSeconds;
    public int $infoCacheSeconds;
    public int $cleanupIntervalSeconds;
    public int $killGraceSeconds;
    public int $maxJobAttempts;
    public string $historyScope;
    public bool $trustProxyHeaders;
    public string $accelRedirectPrefix;
    public bool $cookieSecure;

    private const ENV_KEYS = [
        'APP_ENV', 'APP_URL', 'STORAGE_PATH', 'YTDLP_BIN', 'FFMPEG_BIN', 'FFPROBE_BIN', 'YTDLP_JS_RUNTIME',
        'MAX_CONCURRENT_DOWNLOADS', 'MAX_DOWNLOAD_SIZE_GB', 'MAX_VIDEO_DURATION_MINUTES',
        'DOWNLOAD_TIMEOUT_MINUTES', 'DOWNLOAD_RETENTION_HOURS', 'COMPLETED_RETENTION_HOURS',
        'LOG_RETENTION_DAYS', 'RATE_LIMIT_ANALYZE', 'RATE_LIMIT_DOWNLOAD', 'RATE_LIMIT_WINDOW_SECONDS',
        'MAX_REQUEST_BODY_BYTES', 'INFO_TIMEOUT_SECONDS', 'INFO_CACHE_SECONDS', 'CLEANUP_INTERVAL_SECONDS',
        'KILL_GRACE_SECONDS', 'MAX_JOB_ATTEMPTS', 'HISTORY_SCOPE', 'TRUST_PROXY_HEADERS',
        'ACCEL_REDIRECT_PREFIX', 'COOKIE_SECURE',
    ];

    /** @param array<string,string|int|bool|null> $env */
    public function __construct(array $env = [])
    {
        $str = static fn (string $k, string $d): string => isset($env[$k]) && $env[$k] !== '' ? (string) $env[$k] : $d;
        $int = static function (string $k, int $d, int $min = 0) use ($env): int {
            if (!isset($env[$k]) || $env[$k] === '' || !is_numeric($env[$k])) {
                return $d;
            }
            return max($min, (int) $env[$k]);
        };
        $bool = static function (string $k, bool $d) use ($env): bool {
            if (!isset($env[$k]) || $env[$k] === '') {
                return $d;
            }
            return in_array(strtolower((string) $env[$k]), ['1', 'true', 'yes', 'on'], true);
        };

        $this->appEnv = $str('APP_ENV', 'production');
        $this->storagePath = rtrim($str('STORAGE_PATH', dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'storage'), '/\\');
        $this->ytdlpBin = $str('YTDLP_BIN', 'yt-dlp');
        $this->ffmpegBin = $str('FFMPEG_BIN', 'ffmpeg');
        $this->ffprobeBin = $str('FFPROBE_BIN', 'ffprobe');
        // Optional yt-dlp JS runtime spec, e.g. "deno", "node" or "deno:/usr/local/bin/deno" (needed for YouTube).
        $this->ytdlpJsRuntime = $str('YTDLP_JS_RUNTIME', '');

        $this->maxConcurrentDownloads = $int('MAX_CONCURRENT_DOWNLOADS', 3, 1);
        $this->maxDownloadSizeBytes = (int) round(((float) $str('MAX_DOWNLOAD_SIZE_GB', '10')) * 1024 ** 3);
        $this->maxVideoDurationSeconds = (int) round(((float) $str('MAX_VIDEO_DURATION_MINUTES', '240')) * 60);
        $this->downloadTimeoutSeconds = $int('DOWNLOAD_TIMEOUT_MINUTES', 120, 1) * 60;
        $this->retentionSeconds = $int('DOWNLOAD_RETENTION_HOURS', 24, 1) * 3600;
        // 0 = keep completed files until the user deletes them.
        $this->completedRetentionSeconds = $int('COMPLETED_RETENTION_HOURS', 0, 0) * 3600;
        $this->logRetentionDays = $int('LOG_RETENTION_DAYS', 14, 1);

        $this->rateLimitAnalyze = $int('RATE_LIMIT_ANALYZE', 20, 1);
        $this->rateLimitDownload = $int('RATE_LIMIT_DOWNLOAD', 5, 1);
        $this->rateLimitWindowSeconds = $int('RATE_LIMIT_WINDOW_SECONDS', 60, 1);
        $this->maxRequestBodyBytes = $int('MAX_REQUEST_BODY_BYTES', 8192, 256);

        $this->infoTimeoutSeconds = $int('INFO_TIMEOUT_SECONDS', 60, 5);
        $this->infoCacheSeconds = $int('INFO_CACHE_SECONDS', 600, 0);
        $this->cleanupIntervalSeconds = $int('CLEANUP_INTERVAL_SECONDS', 600, 5);
        $this->killGraceSeconds = $int('KILL_GRACE_SECONDS', 5, 1);
        $this->maxJobAttempts = $int('MAX_JOB_ATTEMPTS', 2, 1);

        $scope = strtolower($str('HISTORY_SCOPE', 'client'));
        $this->historyScope = in_array($scope, ['client', 'global'], true) ? $scope : 'client';
        $this->trustProxyHeaders = $bool('TRUST_PROXY_HEADERS', false);
        $this->accelRedirectPrefix = $str('ACCEL_REDIRECT_PREFIX', '');
        $this->cookieSecure = $bool('COOKIE_SECURE', str_starts_with(strtolower($str('APP_URL', '')), 'https://'));
    }

    public static function fromEnvironment(): self
    {
        $env = [];
        foreach (self::ENV_KEYS as $key) {
            $value = getenv($key);
            if ($value === false && isset($_SERVER[$key])) {
                $value = $_SERVER[$key];
            }
            if ($value !== false && $value !== null) {
                $env[$key] = (string) $value;
            }
        }
        return new self($env);
    }

    public function path(string ...$parts): string
    {
        return $this->storagePath . ($parts ? DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts) : '');
    }
}
