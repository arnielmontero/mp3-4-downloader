<?php

declare(strict_types=1);

namespace App\Media;

/** Snapshot of a running media task, derived only from what yt-dlp actually reports. */
final class Progress
{
    public function __construct(
        /** analyzing | downloading | processing */
        public string $stage,
        /** 0-100, or null when the engine cannot tell (indeterminate) */
        public ?float $percent,
        public int $downloadedBytes,
        public ?int $totalBytes,
        public ?float $speedBps,
        public ?int $etaSeconds,
    ) {
    }

    public bool $indeterminate = false;
}
