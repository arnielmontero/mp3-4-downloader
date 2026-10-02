<?php

declare(strict_types=1);

namespace App\Worker;

use App\Media\MediaTask;

/** In-memory bookkeeping for a job the worker is currently running (one process group per job). */
final class ActiveJob
{
    public const ANALYZE = 'analyze';
    public const DOWNLOAD = 'download';
    public const VERIFY = 'verify';
    public const STOPPING = 'stopping';

    public string $stage = self::ANALYZE;
    public ?MediaTask $task = null;
    public float $startedAt;
    public float $lastWrite = 0.0;
    public float $lastSizeCheck = 0.0;
    public ?float $killDeadline = null;
    /** user | shutdown */
    public ?string $stopReason = null;
    public string $lastStage = '';
    /** @var array<string,mixed> */
    public array $info = [];
    /** verification of the finished file: path, step (probe|decode), deadline */
    public ?string $verifyPath = null;
    public string $verifyStep = 'probe';
    public float $verifyDeadline = 0.0;
    /** length (seconds) the decoded media must reach: announced duration, else what ffprobe reported */
    public float $verifyReference = 0.0;

    /** @param array<string,mixed> $job */
    public function __construct(public string $id, public array $job, public string $tempDir)
    {
        $this->startedAt = microtime(true);
    }
}
