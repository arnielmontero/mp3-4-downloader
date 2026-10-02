<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use App\Support\ApiException;
use App\Support\Logger;

/**
 * API-facing download operations: create a job, read its status, cancel it, authorise file access.
 * The actual work happens in the worker; nothing here blocks on a download.
 */
final class DownloadService
{
    private const CANCEL_WAIT_SECONDS = 4;

    public function __construct(
        private AppConfig $config,
        private SecurityService $security,
        private JobService $jobs,
        private FileService $files,
        private VideoInfoService $videoInfo,
        private Logger $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $input url, format, quality?, bitrate?
     * @return array<string,mixed> public job representation
     */
    public function create(array $input, ?string $clientId): array
    {
        $videoId = $this->security->extractVideoId($input['url'] ?? null);
        $format = $this->security->validateFormat($input['format'] ?? null);
        $quality = $format === 'mp4' ? $this->security->validateQuality($input['quality'] ?? null) : 'best';
        $bitrate = $format === 'mp3' ? $this->security->validateBitrate($input['bitrate'] ?? null) : SecurityService::DEFAULT_BITRATE;

        // If the video was analysed moments ago we can reject over-long videos before queueing.
        $cached = $this->videoInfo->cached($videoId);
        if ($cached !== null) {
            VideoInfoService::assertWithinLimits($cached, $this->config);
        }

        $job = $this->jobs->create([
            'url' => 'https://www.youtube.com/watch?v=' . $videoId,
            'video_id' => $videoId,
            'format' => $format,
            'quality' => $quality,
            'bitrate' => $bitrate,
            'client_id' => $clientId,
            'title' => $cached['title'] ?? null,
            'uploader' => $cached['uploader'] ?? null,
            'duration' => $cached['duration'] ?? null,
        ]);
        $this->logger->info('job created', ['job_id' => $job['id'], 'op' => 'create', 'status' => $job['status']]);
        return $this->jobs->toPublic($job);
    }

    /** @return array<string,mixed> */
    public function status(string $id): array
    {
        return $this->jobs->toPublic($this->jobs->get($id));
    }

    /** @return array<string,mixed> */
    public function cancel(string $id): array
    {
        $job = $this->jobs->get($id);
        if ($job['status'] === JobService::CANCELLED) {
            return $this->jobs->toPublic($job);
        }
        if (in_array($job['status'], [JobService::COMPLETED, JobService::FAILED], true)) {
            throw new ApiException('JOB_NOT_CANCELLABLE', 'This download has already finished and cannot be cancelled.');
        }
        $job = $this->jobs->requestCancel($id);
        $this->logger->info('cancel requested', ['job_id' => $id, 'op' => 'cancel', 'status' => $job['status']]);

        // Give the worker a moment so the response normally already shows the final "cancelled" state.
        $deadline = microtime(true) + self::CANCEL_WAIT_SECONDS;
        while ($job['status'] !== JobService::CANCELLED && microtime(true) < $deadline) {
            usleep(100000);
            $job = $this->jobs->get($id);
        }
        return $this->jobs->toPublic($job);
    }

    /**
     * Authorise and locate the finished file. Throws unless the job is completed and its file exists
     * in the job's own download directory.
     *
     * @return array{path:string,filename:string,size:int,type:string,ext:string}
     */
    public function fileFor(string $id): array
    {
        $job = $this->jobs->get($id);
        if ($job['status'] === JobService::CANCELLED) {
            throw new ApiException('JOB_CANCELLED', 'This download was cancelled.');
        }
        if ($job['status'] !== JobService::COMPLETED) {
            throw new ApiException('JOB_NOT_READY', 'The file is not ready yet.');
        }
        $file = $this->files->resolveJobFile($job);
        if ($file === null) {
            throw new ApiException('FILE_NOT_FOUND', 'The file is no longer available.');
        }
        return $file;
    }
}
