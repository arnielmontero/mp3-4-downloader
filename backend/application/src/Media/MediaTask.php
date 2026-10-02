<?php

declare(strict_types=1);

namespace App\Media;

use App\Services\ManagedProcess;

/** One running yt-dlp invocation plus the state needed to turn its output into progress. */
final class MediaTask
{
    public const INFO = 'info';
    public const DOWNLOAD = 'download';

    /** Raw JSON collected from stdout for info tasks (bounded). */
    public string $infoJson = '';
    public bool $infoOverflow = false;

    public string $stage;
    public int $downloadedBytes = 0;
    public ?int $totalBytes = null;
    public ?float $speedBps = null;
    public ?int $etaSeconds = null;
    public ?float $percent = null;

    /** Bytes of streams that already finished (video part of a video+audio download). */
    public int $finishedBytes = 0;
    public ?string $currentStreamFile = null;
    public int $streamIndex = 0;
    public int $currentStreamTotal = 0;
    /** Sum of expected sizes of all streams, from the analysis step (0 = unknown). */
    public int $expectedTotalBytes = 0;
    public bool $processing = false;

    public function __construct(
        public string $kind,
        public ManagedProcess $process,
        public string $format,
        public string $outDir = '',
    ) {
        $this->stage = $kind === self::INFO ? 'analyzing' : 'downloading';
    }
}
