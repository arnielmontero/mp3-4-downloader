<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;

/**
 * Proves that a finished download is really playable before it is offered to the user:
 *   1. ffprobe: valid container, expected stream types (MP4 = video track, MP3 = audio track), a duration that
 *      matches what YouTube announced (catches truncated/partial files);
 *   2. ffmpeg: a full decode of the file with -xerror (any decoding error means a damaged file).
 * Both steps run as separate non-blocking processes so the worker keeps serving other jobs meanwhile.
 */
final class MediaVerifier
{
    public const MESSAGE = 'The downloaded file is damaged or incomplete and was discarded. Please try again.';

    public function __construct(private AppConfig $config, private ProcessService $processes)
    {
    }

    public function startProbe(string $path): ManagedProcess
    {
        return $this->processes->start([
            $this->config->ffprobeBin, '-v', 'error',
            '-show_entries', 'format=format_name,duration:stream=codec_type,codec_name',
            '-of', 'json', '--', $path,
        ]);
    }

    public function startDecode(string $path): ManagedProcess
    {
        return $this->processes->start([
            $this->config->ffmpegBin, '-nostdin', '-v', 'error', '-xerror', '-progress', 'pipe:1', '-nostats', '-i', $path, '-f', 'null', '-',
        ]);
    }

    /**
     * Evaluate ffprobe's JSON. Returns null when the file looks right, otherwise a technical reason (for the log).
     */
    public static function checkProbe(string $json, string $format, ?int $expectedSeconds): ?string
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['format']) || !is_array($data['format'])) {
            return 'ffprobe could not read the file';
        }
        $formatName = (string) ($data['format']['format_name'] ?? '');
        $duration = isset($data['format']['duration']) && is_numeric($data['format']['duration']) ? (float) $data['format']['duration'] : 0.0;
        $types = [];
        foreach (($data['streams'] ?? []) as $stream) {
            if (is_array($stream) && !empty($stream['codec_name'])) {
                $types[(string) ($stream['codec_type'] ?? '')] = true;
            }
        }

        if ($format === 'mp3') {
            if (!str_contains($formatName, 'mp3')) {
                return "container is '$formatName', not MP3";
            }
            if (!isset($types['audio'])) {
                return 'no audio stream';
            }
        } else {
            if (!str_contains($formatName, 'mp4')) {
                return "container is '$formatName', not MP4";
            }
            if (!isset($types['video'])) {
                return 'no video stream';
            }
        }
        if ($duration <= 0.0) {
            return 'zero duration';
        }
        if ($expectedSeconds !== null && $expectedSeconds > 0) {
            $tolerance = max(3.0, $expectedSeconds * 0.05);
            if (abs($duration - $expectedSeconds) > $tolerance) {
                return sprintf('duration %.1fs differs from the expected %ds', $duration, $expectedSeconds);
            }
        }
        return null;
    }

    public static function probeDuration(string $json): float
    {
        $data = json_decode($json, true);
        return is_array($data) && isset($data['format']['duration']) && is_numeric($data['format']['duration']) ? (float) $data['format']['duration'] : 0.0;
    }

    /**
     * Compare how much media the decoder really produced (ffmpeg -progress "out_time_us") with the length the file
     * claims/was announced. Container headers (e.g. an MP3 Xing header) can promise more than a truncated file holds,
     * so this is what finally proves the file plays to its end.
     */
    public static function checkDecode(string $progressOutput, float $referenceSeconds): ?string
    {
        if (preg_match_all('/^out_time_(?:us|ms)=(\d+)$/m', $progressOutput, $m) < 1) {
            return 'decoder reported no progress';
        }
        $decoded = ((float) end($m[1])) / 1_000_000;
        $tolerance = max(3.0, $referenceSeconds * 0.05);
        if ($referenceSeconds > 0 && $decoded < $referenceSeconds - $tolerance) {
            return sprintf('only %.1fs of %.1fs could be decoded', $decoded, $referenceSeconds);
        }
        return null;
    }

    /** Generous upper bound for the decode step, so a hung ffmpeg can never block a job slot forever. */
    public static function decodeTimeout(?int $expectedSeconds): int
    {
        return max(300, (int) (($expectedSeconds ?? 0) * 2) + 120);
    }
}
