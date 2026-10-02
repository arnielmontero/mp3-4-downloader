<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use App\Media\MediaDownloader;
use App\Support\ApiException;
use App\Support\Logger;

/**
 * Metadata analysis: validates the URL, asks the media engine for info (never downloads media),
 * enforces the duration limit and keeps a short-lived cache so a following download request can be
 * pre-validated without another round trip to YouTube.
 */
final class VideoInfoService
{
    public function __construct(
        private AppConfig $config,
        private SecurityService $security,
        private MediaDownloader $media,
        private Logger $logger,
    ) {
    }

    /** @return array<string,mixed> */
    public function analyze(mixed $url): array
    {
        $id = $this->security->extractVideoId($url);
        $normalized = 'https://www.youtube.com/watch?v=' . $id;
        $started = microtime(true);

        $info = $this->cached($id);
        if ($info === null) {
            try {
                $info = $this->media->getInfo($normalized);
            } catch (ApiException $e) {
                $this->logger->warning('analyze failed', ['op' => 'analyze', 'status' => 'failed', 'error_code' => $e->errorCode(), 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
                throw $e;
            }
            $this->store($id, $info);
        }
        self::assertWithinLimits($info, $this->config);
        $this->logger->info('analyzed ' . $id, ['op' => 'analyze', 'status' => 'ok', 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);

        $info['limits'] = [
            'max_duration_seconds' => $this->config->maxVideoDurationSeconds,
            'max_size_bytes' => $this->config->maxDownloadSizeBytes,
        ];
        return $info;
    }

    /** @param array<string,mixed> $info */
    public static function assertWithinLimits(array $info, AppConfig $config): void
    {
        if (!empty($info['is_live'])) {
            throw new ApiException('VIDEO_UNAVAILABLE', 'Live streams cannot be downloaded.');
        }
        if (isset($info['duration']) && $info['duration'] !== null && (int) $info['duration'] > $config->maxVideoDurationSeconds) {
            throw new ApiException(
                'VIDEO_TOO_LONG',
                sprintf('This video is longer than the allowed maximum of %d minutes.', (int) round($config->maxVideoDurationSeconds / 60)),
            );
        }
    }

    /** @return array<string,mixed>|null */
    public function cached(string $videoId): ?array
    {
        if ($this->config->infoCacheSeconds <= 0 || preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) {
            return null;
        }
        $file = $this->cacheFile($videoId);
        if (!is_file($file) || filemtime($file) < time() - $this->config->infoCacheSeconds) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) && ($data['video_id'] ?? null) === $videoId ? $data : null;
    }

    /** @param array<string,mixed> $info */
    private function store(string $videoId, array $info): void
    {
        if ($this->config->infoCacheSeconds <= 0) {
            return;
        }
        $dir = dirname($this->cacheFile($videoId));
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->cacheFile($videoId), json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function cacheFile(string $videoId): string
    {
        return $this->config->path('temp', '_infocache', $videoId . '.json');
    }

    public function purgeCache(): int
    {
        $removed = 0;
        foreach (glob($this->config->path('temp', '_infocache', '*.json')) ?: [] as $file) {
            if (filemtime($file) < time() - max(60, $this->config->infoCacheSeconds) && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }
}
