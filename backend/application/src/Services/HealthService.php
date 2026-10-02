<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use App\Media\MediaDownloader;

/** Health and version reporting for /api/health, /api/about and the worker's own Docker healthcheck. */
final class HealthService
{
    private const WORKER_STALE_AFTER = 15;

    public function __construct(private AppConfig $config, private MediaDownloader $media)
    {
    }

    /** @return array{status:string,yt_dlp:bool,ffmpeg:bool,worker:array<string,mixed>} */
    public function health(): array
    {
        $versions = $this->media->versions();
        $ytdlp = $versions['yt_dlp'] !== null;
        $ffmpeg = $versions['ffmpeg'] !== null;
        $worker = $this->workerStatus();
        $status = !$ytdlp || !$ffmpeg ? 'error' : ($worker['alive'] ? 'ok' : 'degraded');
        return ['status' => $status, 'yt_dlp' => $ytdlp, 'ffmpeg' => $ffmpeg, 'worker' => $worker];
    }

    /** @return array<string,mixed> */
    public function about(): array
    {
        $versions = $this->media->versions();
        return [
            'name' => 'YouTube Downloader',
            'version' => AppConfig::VERSION,
            'yt_dlp_version' => $versions['yt_dlp'],
            'ffmpeg_version' => $versions['ffmpeg'],
            'limits' => [
                'max_concurrent_downloads' => $this->config->maxConcurrentDownloads,
                'max_download_size_bytes' => $this->config->maxDownloadSizeBytes,
                'max_video_duration_seconds' => $this->config->maxVideoDurationSeconds,
            ],
        ];
    }

    /** @return array{alive:bool,active:int|null,max:int|null,last_seen_seconds:int|null} */
    public function workerStatus(): array
    {
        $file = $this->config->path('temp', '_worker.json');
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($data) || !isset($data['ts'])) {
            return ['alive' => false, 'active' => null, 'max' => null, 'last_seen_seconds' => null];
        }
        $age = max(0, time() - (int) $data['ts']);
        return [
            'alive' => !empty($data['alive']) && $age <= self::WORKER_STALE_AFTER,
            'active' => isset($data['active']) ? (int) $data['active'] : null,
            'max' => isset($data['max']) ? (int) $data['max'] : null,
            'last_seen_seconds' => $age,
        ];
    }
}
