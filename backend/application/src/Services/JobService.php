<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use App\Support\ApiException;

/**
 * Job persistence on the filesystem: one JSON document per job in storage/jobs/{id}.json.
 *
 * All read-modify-write cycles go through update(), which holds an exclusive flock on the job
 * file, so the API process and the worker never overwrite each other's changes.
 */
final class JobService
{
    public const QUEUED = 'queued';
    public const ANALYZING = 'analyzing';
    public const DOWNLOADING = 'downloading';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public const ACTIVE = [self::ANALYZING, self::DOWNLOADING, self::PROCESSING];
    public const TERMINAL = [self::COMPLETED, self::FAILED, self::CANCELLED];

    private const STATUS_MESSAGES = [
        self::QUEUED => 'Waiting in queue...',
        self::ANALYZING => 'Checking the video...',
        self::DOWNLOADING => 'Downloading...',
        self::PROCESSING => 'Processing media...',
        self::COMPLETED => 'Download complete.',
        self::FAILED => 'The download failed.',
        self::CANCELLED => 'The download was cancelled.',
    ];

    public function __construct(private AppConfig $config, private SecurityService $security)
    {
    }

    private function dir(): string
    {
        $dir = $this->config->path('jobs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function path(string $id): string
    {
        return $this->dir() . DIRECTORY_SEPARATOR . $this->security->validateJobId($id) . '.json';
    }

    /**
     * @param array<string,mixed> $fields url, video_id, format, quality, bitrate, client_id
     * @return array<string,mixed>
     */
    public function create(array $fields): array
    {
        $now = gmdate('c');
        $job = $fields + [
            'format' => 'mp4',
            'quality' => 'best',
            'bitrate' => SecurityService::DEFAULT_BITRATE,
            'client_id' => null,
        ];
        $job = [
            'id' => $this->security->newJobId(),
            'status' => self::QUEUED,
            'progress' => 0.0,
            'indeterminate' => false,
            'downloaded_bytes' => 0,
            'total_bytes' => null,
            'speed_bps' => null,
            'eta_seconds' => null,
            'title' => null,
            'uploader' => null,
            'duration' => null,
            'filename' => null,
            'filesize' => null,
            'error' => null,
            'cancel_requested' => false,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'started_at' => null,
            'finished_at' => null,
        ] + $job;
        $json = json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // "x" mode: fail instead of overwriting in the (astronomically unlikely) event of an id clash.
        $fh = @fopen($this->path($job['id']), 'xb');
        if ($fh === false) {
            throw new \RuntimeException('Unable to create job file');
        }
        fwrite($fh, $json);
        fclose($fh);
        return $job;
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        if (!SecurityService::isValidJobId($id)) {
            return null;
        }
        $path = $this->path($id);
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : null;
    }

    /** @return array<string,mixed> */
    public function get(string $id): array
    {
        $this->security->validateJobId($id);
        $job = $this->find($id);
        if ($job === null) {
            throw new ApiException('JOB_NOT_FOUND', 'Download job not found.');
        }
        return $job;
    }

    /**
     * Atomically modify a job. The callback receives the current record and returns the modified
     * record, or null to leave it untouched. Returns the stored record (or null if missing).
     *
     * @param callable(array<string,mixed>):(array<string,mixed>|null) $mutator
     * @return array<string,mixed>|null
     */
    public function update(string $id, callable $mutator): ?array
    {
        if (!SecurityService::isValidJobId($id)) {
            return null;
        }
        $fh = @fopen($this->path($id), 'c+b');
        if ($fh === false) {
            return null;
        }
        flock($fh, LOCK_EX);
        try {
            $raw = stream_get_contents($fh);
            $job = json_decode((string) $raw, true);
            if (!is_array($job) || !isset($job['id'])) {
                return null;
            }
            $new = $mutator($job);
            if ($new === null) {
                return $job;
            }
            $new['updated_at'] = gmdate('c');
            $json = json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $json);
            fflush($fh);
            return $new;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>|null
     */
    public function patch(string $id, array $changes): ?array
    {
        return $this->update($id, static fn (array $job): array => $changes + $job);
    }

    /** @return list<array<string,mixed>> newest first */
    public function all(): array
    {
        $jobs = [];
        foreach (glob($this->dir() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            if (!SecurityService::isValidJobId($id)) {
                continue;
            }
            $job = $this->find($id);
            if ($job !== null) {
                $jobs[] = $job;
            }
        }
        usort($jobs, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
        return $jobs;
    }

    /** @return list<array<string,mixed>> oldest first */
    public function queued(): array
    {
        $queued = array_values(array_filter($this->all(), static fn (array $j): bool => $j['status'] === self::QUEUED && empty($j['cancel_requested'])));
        return array_reverse($queued);
    }

    public function delete(string $id): void
    {
        $path = $this->path($id);
        @unlink($path);
    }

    /**
     * Ask for cancellation. Queued jobs are cancelled immediately; running jobs are flagged and the
     * worker stops the right process. Terminal jobs are left alone.
     *
     * @return array<string,mixed>
     */
    public function requestCancel(string $id): array
    {
        $this->get($id);
        $result = $this->update($id, function (array $job): ?array {
            if (in_array($job['status'], self::TERMINAL, true)) {
                return null;
            }
            if ($job['status'] === self::QUEUED) {
                $job['status'] = self::CANCELLED;
                $job['finished_at'] = gmdate('c');
                $job['speed_bps'] = null;
                $job['eta_seconds'] = null;
            }
            $job['cancel_requested'] = true;
            return $job;
        });
        if ($result === null) {
            throw new ApiException('JOB_NOT_FOUND', 'Download job not found.');
        }
        return $result;
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    public function fail(string $id, string $code, string $message): ?array
    {
        return $this->update($id, function (array $job) use ($code, $message): ?array {
            if (in_array($job['status'], self::TERMINAL, true)) {
                return null;
            }
            $job['status'] = self::FAILED;
            $job['error'] = ['code' => $code, 'message' => $message];
            $job['finished_at'] = gmdate('c');
            $job['speed_bps'] = null;
            $job['eta_seconds'] = null;
            return $job;
        });
    }

    /**
     * Client-facing representation of a job. Never contains internal paths, process ids or client ids.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public function toPublic(array $job): array
    {
        $status = (string) $job['status'];
        $speed = isset($job['speed_bps']) && $job['speed_bps'] > 0 ? self::formatBytes((float) $job['speed_bps']) . '/s' : null;
        $eta = isset($job['eta_seconds']) && $job['eta_seconds'] !== null ? self::formatDuration((int) $job['eta_seconds']) : null;
        $message = self::STATUS_MESSAGES[$status] ?? $status;
        if (!empty($job['cancel_requested']) && !in_array($status, self::TERMINAL, true)) {
            $message = 'Cancelling...';
        }
        if ($status === self::FAILED && !empty($job['error']['message'])) {
            $message = (string) $job['error']['message'];
        }
        return [
            'job_id' => $job['id'],
            'status' => $status,
            'message' => $message,
            'progress' => round((float) $job['progress'], 1),
            'indeterminate' => (bool) ($job['indeterminate'] ?? false) && !in_array($status, self::TERMINAL, true),
            'downloaded_bytes' => (int) ($job['downloaded_bytes'] ?? 0),
            'total_bytes' => $job['total_bytes'] ?? null,
            'downloaded' => self::formatBytes((float) ($job['downloaded_bytes'] ?? 0)),
            'total' => isset($job['total_bytes']) ? self::formatBytes((float) $job['total_bytes']) : null,
            'speed' => $speed,
            'eta' => $eta,
            'format' => $job['format'],
            'quality' => $job['quality'],
            'bitrate' => $job['format'] === 'mp3' ? (int) $job['bitrate'] : null,
            'title' => $job['title'],
            'uploader' => $job['uploader'],
            'filename' => $status === self::COMPLETED ? $job['filename'] : null,
            'filesize' => $status === self::COMPLETED ? $job['filesize'] : null,
            'error' => $job['error'],
            'created_at' => $job['created_at'],
            'updated_at' => $job['updated_at'],
            'finished_at' => $job['finished_at'],
        ];
    }

    public static function formatBytes(float $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $bytes : number_format($bytes, 1, '.', '')) . $units[$i];
    }

    public static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
    }
}
