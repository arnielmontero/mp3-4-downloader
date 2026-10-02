<?php

declare(strict_types=1);

namespace App\Worker;

use App\Config\AppConfig;
use App\Media\MediaDownloader;
use App\Media\MediaTask;
use App\Media\YtDlpDownloader;
use App\Services\CleanupService;
use App\Services\FileService;
use App\Services\JobService;
use App\Services\MediaVerifier;
use App\Services\VideoInfoService;
use App\Support\ApiException;
use App\Support\Logger;

/**
 * Download worker: a single event loop that
 *   1. claims queued jobs (up to MAX_CONCURRENT_DOWNLOADS),
 *   2. analyses each one (limits), downloads it with yt-dlp/FFmpeg and parses progress,
 *   3. honours cancel requests by killing exactly that job's process group,
 *   4. moves the finished file into storage/downloads/{job_id}/ and removes the temp directory.
 *
 * SIGTERM/SIGINT stop accepting work, terminate children (kill after a grace period), clean up and
 * put interrupted jobs back in the queue.
 */
final class Worker
{
    private const TICK_MICROSECONDS = 200000;
    private const WRITE_INTERVAL = 0.5;
    private const SIZE_CHECK_INTERVAL = 1.0;
    private const HEARTBEAT_INTERVAL = 2.0;
    private const QUEUE_POLL_INTERVAL = 0.5;

    /** @var array<string,ActiveJob> */
    private array $active = [];
    private bool $shutdown = false;
    private float $lastQueuePoll = 0.0;
    private float $lastHeartbeat = 0.0;
    private float $lastCleanup = 0.0;

    public function __construct(
        private AppConfig $config,
        private JobService $jobs,
        private FileService $files,
        private MediaDownloader $media,
        private CleanupService $cleanup,
        private Logger $logger,
        private MediaVerifier $verifier,
    ) {
    }

    public function requestShutdown(): void
    {
        $this->shutdown = true;
    }

    public function run(): int
    {
        $this->files->ensureStorage();
        $this->cleanup->recoverStaleJobs();
        $this->installSignalHandlers();
        $this->logger->info('worker started', ['op' => 'worker', 'status' => 'started']);

        while (!$this->shutdown) {
            $this->tick();
            usleep(self::TICK_MICROSECONDS);
        }
        $this->stopAll();
        $this->heartbeat(true, false);
        $this->logger->info('worker stopped', ['op' => 'worker', 'status' => 'stopped']);
        return 0;
    }

    /** One iteration of the event loop (public so tests can drive the worker deterministically). */
    public function tick(): void
    {
        $now = microtime(true);
        if (count($this->active) < $this->config->maxConcurrentDownloads && $now - $this->lastQueuePoll >= self::QUEUE_POLL_INTERVAL) {
            $this->lastQueuePoll = $now;
            $this->fillSlots();
        }
        foreach ($this->active as $aj) {
            try {
                $this->advance($aj);
            } catch (\Throwable $e) {
                $this->logger->error('worker error: ' . $e->getMessage(), ['job_id' => $aj->id, 'op' => 'advance', 'status' => 'error']);
                $this->failJob($aj, 'SERVER_ERROR', 'An unexpected error occurred.');
            }
        }
        if ($now - $this->lastHeartbeat >= self::HEARTBEAT_INTERVAL) {
            $this->heartbeat(false, true);
        }
        if ($now - $this->lastCleanup >= $this->config->cleanupIntervalSeconds) {
            $this->lastCleanup = $now;
            try {
                $this->cleanup->run(array_keys($this->active));
            } catch (\Throwable $e) {
                $this->logger->error('cleanup failed: ' . $e->getMessage(), ['op' => 'cleanup', 'status' => 'error']);
            }
        }
    }

    public function activeCount(): int
    {
        return count($this->active);
    }

    // ------------------------------------------------------------------ scheduling

    private function fillSlots(): void
    {
        foreach ($this->jobs->queued() as $job) {
            if (count($this->active) >= $this->config->maxConcurrentDownloads) {
                break;
            }
            $this->claim($job);
        }
    }

    /** @param array<string,mixed> $job */
    private function claim(array $job): void
    {
        $claimed = false;
        $stored = $this->jobs->update($job['id'], function (array $j) use (&$claimed): ?array {
            if ($j['status'] !== JobService::QUEUED || !empty($j['cancel_requested'])) {
                return null; // cancelled (or taken) between listing and claiming
            }
            $claimed = true;
            $j['status'] = JobService::ANALYZING;
            $j['started_at'] = gmdate('c');
            $j['attempts'] = (int) ($j['attempts'] ?? 0) + 1;
            $j['error'] = null;
            return $j;
        });
        if (!$claimed || $stored === null) {
            return;
        }
        $aj = new ActiveJob($stored['id'], $stored, $this->files->jobTempDir($stored['id']));
        $this->active[$aj->id] = $aj;
        try {
            $this->files->ensureDir($aj->tempDir);
            $aj->task = $this->media->startInfo($stored['url'], $stored['format'], $stored['quality']);
            $this->logger->info('job started', ['job_id' => $aj->id, 'op' => 'analyze', 'status' => 'started']);
        } catch (\Throwable $e) {
            $this->logger->error('cannot start analysis: ' . $e->getMessage(), ['job_id' => $aj->id, 'op' => 'analyze', 'status' => 'error']);
            $this->failJob($aj, 'SERVER_ERROR', 'The download engine could not be started.');
        }
    }

    // ------------------------------------------------------------------ per-job state machine

    private function advance(ActiveJob $aj): void
    {
        $current = $this->jobs->find($aj->id);
        if ($current === null) {
            // Metadata was deleted from under us: stop and clean up silently.
            $this->abort($aj);
            return;
        }

        if ($aj->stage === ActiveJob::STOPPING) {
            $this->continueStopping($aj);
            return;
        }
        if (!empty($current['cancel_requested'])) {
            $this->beginStop($aj, 'user');
            return;
        }
        if ($aj->stage === ActiveJob::VERIFY) {
            $this->advanceVerify($aj);
            return;
        }
        $task = $aj->task;
        if ($task === null) {
            return;
        }

        $progress = $this->media->getProgress($task);

        if (microtime(true) - $aj->startedAt > $this->config->downloadTimeoutSeconds) {
            $this->media->kill($task);
            $this->failJob($aj, 'DOWNLOAD_FAILED', 'The download took too long and was stopped.');
            return;
        }

        if ($aj->stage === ActiveJob::ANALYZE) {
            if (!$task->process->isRunning()) {
                $this->finishAnalysis($aj, $task);
            }
            return;
        }

        // download stage ----------------------------------------------------
        $now = microtime(true);
        if ($task->process->isRunning() && $now - $aj->lastSizeCheck >= self::SIZE_CHECK_INTERVAL) {
            $aj->lastSizeCheck = $now;
            if (FileService::dirSize($aj->tempDir) > $this->config->maxDownloadSizeBytes) {
                $this->media->kill($task);
                $this->failJob($aj, 'FILE_TOO_LARGE', 'The file exceeded the allowed maximum size and the download was stopped.');
                return;
            }
        }

        $stageChanged = $progress->stage !== $aj->lastStage;
        if ($task->process->isRunning() && ($stageChanged || $now - $aj->lastWrite >= self::WRITE_INTERVAL)) {
            $aj->lastWrite = $now;
            $aj->lastStage = $progress->stage;
            $status = $progress->stage === 'processing' ? JobService::PROCESSING : JobService::DOWNLOADING;
            $this->jobs->update($aj->id, static function (array $j) use ($progress, $status): ?array {
                if (in_array($j['status'], JobService::TERMINAL, true) || !empty($j['cancel_requested'])) {
                    return null;
                }
                $j['status'] = $status;
                $j['progress'] = $progress->percent ?? ($j['progress'] ?? 0.0);
                $j['indeterminate'] = $progress->indeterminate;
                $j['downloaded_bytes'] = $progress->downloadedBytes;
                $j['total_bytes'] = $progress->totalBytes;
                $j['speed_bps'] = $progress->speedBps;
                $j['eta_seconds'] = $progress->etaSeconds;
                return $j;
            });
        }

        if (!$task->process->isRunning()) {
            $this->finishDownload($aj, $task);
        }
    }

    private function finishAnalysis(ActiveJob $aj, MediaTask $task): void
    {
        $this->media->getProgress($task); // the process has exited: drain whatever is left in the pipes
        $exit = $task->process->exitCode();
        $stderr = $task->process->stderrTail();
        $task->process->close();
        if ($exit !== 0) {
            [$code, $message] = YtDlpDownloader::classifyError($stderr);
            $this->logger->warning('analysis failed: ' . $stderr, ['job_id' => $aj->id, 'op' => 'analyze', 'status' => 'failed', 'error_code' => $code]);
            $this->failJob($aj, $code, $message);
            return;
        }
        try {
            $info = $this->media instanceof YtDlpDownloader ? $this->media->parseInfoTask($task) : [];
            VideoInfoService::assertWithinLimits($info, $this->config);
            $expected = $info['filesize'] ?? null;
            if ($expected !== null && (int) $expected > $this->config->maxDownloadSizeBytes) {
                throw new ApiException('FILE_TOO_LARGE', 'The file is larger than the allowed maximum size.');
            }
        } catch (ApiException $e) {
            $this->logger->warning('job rejected: ' . $e->getMessage(), ['job_id' => $aj->id, 'op' => 'analyze', 'status' => 'rejected', 'error_code' => $e->errorCode()]);
            $this->failJob($aj, $e->errorCode(), $e->getMessage());
            return;
        }
        $aj->info = $info;

        $this->jobs->update($aj->id, static function (array $j) use ($info): ?array {
            if (in_array($j['status'], JobService::TERMINAL, true) || !empty($j['cancel_requested'])) {
                return null;
            }
            $j['status'] = JobService::DOWNLOADING;
            $j['title'] = $info['title'];
            $j['uploader'] = $info['uploader'];
            $j['duration'] = $info['duration'];
            $j['indeterminate'] = true;
            return $j;
        });

        $job = $aj->job;
        $aj->stage = ActiveJob::DOWNLOAD;
        $aj->lastStage = 'downloading';
        $aj->startedAt = microtime(true);
        $newTask = $job['format'] === 'mp3'
            ? $this->media->startMp3($job['url'], (int) $job['bitrate'], $aj->tempDir)
            : $this->media->startMp4($job['url'], $job['quality'], $aj->tempDir);
        $newTask->expectedTotalBytes = isset($info['filesize']) ? (int) $info['filesize'] : 0;
        $aj->task = $newTask;
        $this->logger->info('download started', ['job_id' => $aj->id, 'op' => 'download', 'status' => 'started']);
    }

    private function finishDownload(ActiveJob $aj, MediaTask $task): void
    {
        $this->media->getProgress($task); // drain the pipes after exit
        $exit = $task->process->exitCode();
        $stderr = $task->process->stderrTail();
        $task->process->close();
        $started = $aj->startedAt;

        if ($exit !== 0) {
            [$code, $message] = YtDlpDownloader::classifyError($stderr);
            $this->logger->warning('download failed (exit ' . $exit . '): ' . $stderr, ['job_id' => $aj->id, 'op' => 'download', 'status' => 'failed', 'error_code' => $code]);
            $this->failJob($aj, $code, $message);
            return;
        }

        $format = (string) $aj->job['format'];
        $source = FileService::findOutputFile($aj->tempDir, $format);
        if ($source === null) {
            $this->logger->warning('no output file produced: ' . $stderr, ['job_id' => $aj->id, 'op' => 'download', 'status' => 'failed', 'error_code' => 'PROCESSING_FAILED']);
            $this->failJob($aj, 'PROCESSING_FAILED', 'Media processing failed.');
            return;
        }

        $this->beginVerify($aj, $source);
    }

    /** Step 1 of verification: ffprobe. The job shows "processing" while the file is being checked. */
    private function beginVerify(ActiveJob $aj, string $source): void
    {
        $aj->stage = ActiveJob::VERIFY;
        $aj->verifyPath = $source;
        $aj->verifyStep = 'probe';
        $expected = isset($aj->info['duration']) ? (int) $aj->info['duration'] : null;
        $aj->verifyDeadline = microtime(true) + MediaVerifier::decodeTimeout($expected);
        $this->jobs->update($aj->id, static function (array $j): ?array {
            if (in_array($j['status'], JobService::TERMINAL, true) || !empty($j['cancel_requested'])) {
                return null;
            }
            $j['status'] = JobService::PROCESSING;
            $j['progress'] = 100.0;
            $j['indeterminate'] = true;
            $j['speed_bps'] = null;
            $j['eta_seconds'] = null;
            return $j;
        });
        try {
            // tracked as the job's current "task" so cancel / kill / shutdown handle it like any other process
            $aj->task = new MediaTask(MediaTask::INFO, $this->verifier->startProbe($source), (string) $aj->job['format']);
        } catch (\Throwable $e) {
            $this->logger->error('cannot start verification: ' . $e->getMessage(), ['job_id' => $aj->id, 'op' => 'verify', 'status' => 'error']);
            $this->failJob($aj, 'PROCESSING_FAILED', MediaVerifier::MESSAGE);
        }
    }

    private function advanceVerify(ActiveJob $aj): void
    {
        $task = $aj->task;
        if ($task === null || $aj->verifyPath === null) {
            return;
        }
        foreach ($task->process->readStdout() as $line) {
            if (strlen($task->infoJson) < 1048576) {
                $task->infoJson .= $line . "\n";
            }
        }
        $task->process->readStderr();
        if ($task->process->isRunning()) {
            if (microtime(true) > $aj->verifyDeadline) {
                $this->media->kill($task);
                $this->failJob($aj, 'PROCESSING_FAILED', MediaVerifier::MESSAGE);
            }
            return;
        }
        foreach ($task->process->readStdout(true) as $line) {
            $task->infoJson .= $line . "\n";
        }
        $task->process->readStderr(true);
        $exit = $task->process->exitCode();
        $stderr = $task->process->stderrTail();
        $out = $task->infoJson;
        $task->process->close();
        $format = (string) $aj->job['format'];

        if ($aj->verifyStep === 'probe') {
            $expected = isset($aj->info['duration']) ? (int) $aj->info['duration'] : null;
            $problem = $exit !== 0 ? 'ffprobe failed: ' . $stderr : MediaVerifier::checkProbe($out, $format, $expected);
            if ($problem !== null) {
                $this->logger->warning('verification failed: ' . $problem, ['job_id' => $aj->id, 'op' => 'verify', 'status' => 'failed', 'error_code' => 'PROCESSING_FAILED']);
                $this->failJob($aj, 'PROCESSING_FAILED', MediaVerifier::MESSAGE);
                return;
            }
            $aj->verifyStep = 'decode';
            $aj->verifyReference = $expected !== null && $expected > 0 ? (float) $expected : MediaVerifier::probeDuration($out);
            try {
                $aj->task = new MediaTask(MediaTask::INFO, $this->verifier->startDecode($aj->verifyPath), $format);
            } catch (\Throwable $e) {
                $this->failJob($aj, 'PROCESSING_FAILED', MediaVerifier::MESSAGE);
            }
            return;
        }

        // decode step: a clean full decode means the file plays from start to end
        if ($exit !== 0 || trim($stderr) !== '') {
            $this->logger->warning('verification failed (decode): ' . $stderr, ['job_id' => $aj->id, 'op' => 'verify', 'status' => 'failed', 'error_code' => 'PROCESSING_FAILED']);
            $this->failJob($aj, 'PROCESSING_FAILED', MediaVerifier::MESSAGE);
            return;
        }
        $problem = MediaVerifier::checkDecode($out, $aj->verifyReference);
        if ($problem !== null) {
            $this->logger->warning('verification failed (decoded length): ' . $problem, ['job_id' => $aj->id, 'op' => 'verify', 'status' => 'failed', 'error_code' => 'PROCESSING_FAILED']);
            $this->failJob($aj, 'PROCESSING_FAILED', MediaVerifier::MESSAGE);
            return;
        }
        $this->completeJob($aj, $aj->verifyPath);
    }

    private function completeJob(ActiveJob $aj, string $source): void
    {
        $format = (string) $aj->job['format'];
        $started = $aj->startedAt;
        try {
            $destDir = $this->files->jobDownloadDir($aj->id);
            $this->files->ensureDir($destDir);
            $title = (string) ($aj->info['title'] ?? $aj->job['title'] ?? $aj->job['video_id']);
            $name = FileService::uniqueFilename($destDir, FileService::sanitizeFilename($title, (string) $aj->job['video_id']), $format);
            $dest = $destDir . DIRECTORY_SEPARATOR . $name;
            if (!@rename($source, $dest)) {
                // temp and downloads can live on different volumes
                if (!@copy($source, $dest)) {
                    throw new \RuntimeException('Cannot move the finished file');
                }
                @unlink($source);
            }
            $size = (int) filesize($dest);
        } catch (\Throwable $e) {
            $this->logger->error('move failed: ' . $e->getMessage(), ['job_id' => $aj->id, 'op' => 'finalize', 'status' => 'error']);
            $this->failJob($aj, 'SERVER_ERROR', 'The finished file could not be stored.');
            return;
        }

        $this->jobs->update($aj->id, static function (array $j) use ($name, $size): ?array {
            if (in_array($j['status'], JobService::TERMINAL, true)) {
                return null;
            }
            $j['status'] = JobService::COMPLETED;
            $j['progress'] = 100.0;
            $j['indeterminate'] = false;
            $j['filename'] = $name;
            $j['filesize'] = $size;
            $j['downloaded_bytes'] = $size;
            $j['total_bytes'] = $size;
            $j['speed_bps'] = null;
            $j['eta_seconds'] = null;
            $j['finished_at'] = gmdate('c');
            return $j;
        });
        FileService::removeDir($aj->tempDir);
        unset($this->active[$aj->id]);
        $this->logger->info('job completed', ['job_id' => $aj->id, 'op' => 'download', 'status' => 'completed', 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
    }

    // ------------------------------------------------------------------ cancel / shutdown / failure

    private function beginStop(ActiveJob $aj, string $reason): void
    {
        if ($aj->stage === ActiveJob::STOPPING) {
            return;
        }
        $aj->stage = ActiveJob::STOPPING;
        $aj->stopReason = $reason;
        $aj->killDeadline = microtime(true) + $this->config->killGraceSeconds;
        if ($aj->task !== null) {
            $this->media->cancel($aj->task);
        }
        $this->continueStopping($aj);
    }

    private function continueStopping(ActiveJob $aj): void
    {
        $task = $aj->task;
        if ($task !== null && $task->process->isRunning()) {
            if ($aj->killDeadline !== null && microtime(true) >= $aj->killDeadline) {
                $this->media->kill($task);
            } else {
                return; // still shutting down gracefully
            }
        }
        if ($task !== null) {
            $task->process->close();
        }
        FileService::removeDir($aj->tempDir);
        unset($this->active[$aj->id]);

        if ($aj->stopReason === 'shutdown') {
            $this->jobs->update($aj->id, static fn (array $j): ?array => in_array($j['status'], JobService::TERMINAL, true) ? null : CleanupService::resetForRetry($j));
            $this->logger->info('job re-queued on shutdown', ['job_id' => $aj->id, 'op' => 'shutdown', 'status' => 'queued']);
            return;
        }
        $this->jobs->update($aj->id, static function (array $j): ?array {
            if (in_array($j['status'], JobService::TERMINAL, true)) {
                return null;
            }
            $j['status'] = JobService::CANCELLED;
            $j['cancel_requested'] = true;
            $j['speed_bps'] = null;
            $j['eta_seconds'] = null;
            $j['indeterminate'] = false;
            $j['finished_at'] = gmdate('c');
            return $j;
        });
        $this->logger->info('job cancelled', ['job_id' => $aj->id, 'op' => 'cancel', 'status' => 'cancelled']);
    }

    /** Metadata disappeared: stop quietly. */
    private function abort(ActiveJob $aj): void
    {
        if ($aj->task !== null) {
            $this->media->kill($aj->task);
        }
        FileService::removeDir($aj->tempDir);
        unset($this->active[$aj->id]);
    }

    private function failJob(ActiveJob $aj, string $code, string $message): void
    {
        if ($aj->task !== null) {
            $this->media->kill($aj->task);
        }
        FileService::removeDir($aj->tempDir);
        unset($this->active[$aj->id]);
        $this->jobs->fail($aj->id, $code, $message);
        $this->logger->warning('job failed: ' . $message, ['job_id' => $aj->id, 'op' => 'job', 'status' => 'failed', 'error_code' => $code]);
    }

    /** Graceful stop of everything (SIGTERM): TERM, wait for the grace period, KILL, requeue. */
    private function stopAll(): void
    {
        foreach ($this->active as $aj) {
            $this->beginStop($aj, 'shutdown');
        }
        $deadline = microtime(true) + $this->config->killGraceSeconds + 2;
        while ($this->active && microtime(true) < $deadline) {
            foreach ($this->active as $aj) {
                $this->continueStopping($aj);
            }
            usleep(100000);
        }
        foreach ($this->active as $aj) {
            if ($aj->task !== null) {
                $this->media->kill($aj->task);
            }
            $aj->killDeadline = 0.0;
            $this->continueStopping($aj);
        }
    }

    // ------------------------------------------------------------------ plumbing

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        $handler = function (): void {
            $this->shutdown = true;
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    private function heartbeat(bool $force, bool $alive): void
    {
        $this->lastHeartbeat = microtime(true);
        $payload = json_encode([
            'pid' => getmypid(),
            'ts' => time(),
            'alive' => $alive,
            'active' => count($this->active),
            'max' => $this->config->maxConcurrentDownloads,
        ]);
        $file = $this->config->path('temp', '_worker.json');
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, $payload, LOCK_EX);
    }
}
