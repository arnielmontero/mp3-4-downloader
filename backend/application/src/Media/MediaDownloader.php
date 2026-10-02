<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Media engine abstraction. The rest of the application only talks to this interface, so yt-dlp
 * could be replaced or wrapped without touching the job system or the API.
 *
 * Long operations are started asynchronously and polled (the worker multiplexes several of them
 * in one loop); getInfo() is a convenience that runs the metadata call to completion.
 */
interface MediaDownloader
{
    /**
     * Fetch metadata without downloading media.
     *
     * @return array<string,mixed> normalised info (see YtDlpDownloader::normalizeInfo)
     * @throws \App\Support\ApiException
     */
    public function getInfo(string $url): array;

    /**
     * Search YouTube (metadata only).
     *
     * @return list<array<string,mixed>> video_id, title, uploader, duration, duration_formatted, thumbnail, webpage_url
     * @throws \App\Support\ApiException
     */
    public function search(string $query, int $limit): array;

    /** Start a metadata task; $format/$quality are used to resolve which streams would be downloaded. */
    public function startInfo(string $url, string $format, string $quality): MediaTask;

    public function startMp4(string $url, string $quality, string $outDir): MediaTask;

    public function startMp3(string $url, int $bitrateKbps, string $outDir): MediaTask;

    /** Ask the task's process group to stop (graceful first). */
    public function cancel(MediaTask $task): void;

    /** Force-kill the task's process group and release resources. */
    public function kill(MediaTask $task): void;

    /** Read pending output and return the current progress snapshot. */
    public function getProgress(MediaTask $task): Progress;

    /** @return array{yt_dlp:?string,ffmpeg:?string} installed versions (null when unavailable) */
    public function versions(): array;
}
