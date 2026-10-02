<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use App\Support\Logger;

/**
 * Housekeeping: abandoned temp directories, expired job metadata, orphaned download folders, old
 * logs, stale rate-limit/info-cache files, and recovery of jobs left "running" by a crashed worker.
 */
final class CleanupService
{
    public function __construct(
        private AppConfig $config,
        private JobService $jobs,
        private FileService $files,
        private RateLimitService $rateLimit,
        private VideoInfoService $videoInfo,
        private Logger $logger,
    ) {
    }

    /**
     * Run all cleanup tasks.
     *
     * @param list<string> $protectedJobIds jobs the current worker is actively processing
     * @return array<string,int> counters for logging/tests
     */
    public function run(array $protectedJobIds = [], ?int $now = null): array
    {
        $now ??= time();
        $stats = ['temp_dirs' => 0, 'jobs' => 0, 'download_dirs' => 0, 'logs' => 0, 'ratelimit' => 0, 'infocache' => 0];
        $retention = $this->config->retentionSeconds;

        // 1. temp/{job_id} directories of finished or abandoned jobs
        foreach ($this->jobIdDirs($this->config->path('temp')) as $id => $dir) {
            if (in_array($id, $protectedJobIds, true)) {
                continue;
            }
            $job = $this->jobs->find($id);
            $abandoned = filemtime($dir) < $now - $retention;
            if ($job === null || in_array($job['status'], JobService::TERMINAL, true) || $abandoned) {
                FileService::removeDir($dir);
                $stats['temp_dirs']++;
            }
        }

        // 2. expired job metadata (+ files for expired completed jobs)
        foreach ($this->jobs->all() as $job) {
            if (in_array($job['id'], $protectedJobIds, true) || !in_array($job['status'], JobService::TERMINAL, true)) {
                continue;
            }
            $finished = strtotime((string) ($job['finished_at'] ?? $job['updated_at'])) ?: $now;
            $age = $now - $finished;
            $expired = match ($job['status']) {
                JobService::COMPLETED => $this->config->completedRetentionSeconds > 0 && $age > $this->config->completedRetentionSeconds,
                default => $age > $retention,
            };
            if ($expired) {
                FileService::removeDir($this->files->jobDownloadDir($job['id']));
                $this->jobs->delete($job['id']);
                $stats['jobs']++;
            }
        }

        // 3. download folders without a job record (half-finished moves, manually deleted metadata)
        foreach ($this->jobIdDirs($this->config->path('downloads')) as $id => $dir) {
            if ($this->jobs->find($id) === null && filemtime($dir) < $now - $retention) {
                FileService::removeDir($dir);
                $stats['download_dirs']++;
            }
        }

        // 4. logs, rate-limit files, info cache
        $cutoff = $now - $this->config->logRetentionDays * 86400;
        foreach (glob($this->config->path('logs', 'app-*.log')) ?: [] as $log) {
            if (filemtime($log) < $cutoff && @unlink($log)) {
                $stats['logs']++;
            }
        }
        $stats['ratelimit'] = $this->rateLimit->purge();
        $stats['infocache'] = $this->videoInfo->purgeCache();

        $this->logger->info('cleanup ' . json_encode($stats), ['op' => 'cleanup', 'status' => 'ok']);
        return $stats;
    }

    /**
     * Called once when the worker starts, before it accepts work: nothing can legitimately be running,
     * so any job still marked active was interrupted. Re-queue it (bounded) or fail it, and wipe
     * every orphaned temp directory.
     *
     * @return array{requeued:int,failed:int,temp_dirs:int}
     */
    public function recoverStaleJobs(): array
    {
        $requeued = 0;
        $failed = 0;
        foreach ($this->jobs->all() as $job) {
            if (!in_array($job['status'], JobService::ACTIVE, true)) {
                continue;
            }
            if (!empty($job['cancel_requested'])) {
                $this->jobs->update($job['id'], static function (array $j): array {
                    $j['status'] = JobService::CANCELLED;
                    $j['finished_at'] = gmdate('c');
                    return $j;
                });
                continue;
            }
            if ((int) ($job['attempts'] ?? 0) < $this->config->maxJobAttempts) {
                $this->jobs->update($job['id'], static function (array $j): array {
                    return self::resetForRetry($j);
                });
                $requeued++;
            } else {
                $this->jobs->fail($job['id'], 'SERVER_ERROR', 'The server restarted while this download was running.');
                $failed++;
            }
        }
        $dirs = 0;
        foreach ($this->jobIdDirs($this->config->path('temp')) as $dir) {
            FileService::removeDir($dir);
            $dirs++;
        }
        $this->logger->info(sprintf('recovery: %d requeued, %d failed, %d temp dirs removed', $requeued, $failed, $dirs), ['op' => 'recover', 'status' => 'ok']);
        return ['requeued' => $requeued, 'failed' => $failed, 'temp_dirs' => $dirs];
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function resetForRetry(array $job): array
    {
        $job['status'] = JobService::QUEUED;
        $job['progress'] = 0.0;
        $job['indeterminate'] = false;
        $job['downloaded_bytes'] = 0;
        $job['total_bytes'] = null;
        $job['speed_bps'] = null;
        $job['eta_seconds'] = null;
        $job['started_at'] = null;
        return $job;
    }

    /** @return array<string,string> job id => absolute directory */
    private function jobIdDirs(string $base): array
    {
        $dirs = [];
        foreach (@scandir($base) ?: [] as $name) {
            $path = $base . DIRECTORY_SEPARATOR . $name;
            if (SecurityService::isValidJobId($name) && is_dir($path) && !is_link($path)) {
                $dirs[$name] = $path;
            }
        }
        return $dirs;
    }
}
