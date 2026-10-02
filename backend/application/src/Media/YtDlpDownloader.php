<?php

declare(strict_types=1);

namespace App\Media;

use App\Config\AppConfig;
use App\Services\ProcessService;
use App\Services\SecurityService;
use App\Support\ApiException;

/**
 * MediaDownloader backed by the yt-dlp executable (and FFmpeg for merging / MP3 extraction).
 *
 * Every command is built as an argument array and the URL is always placed after "--", so user
 * input can never be interpreted as an option or shell syntax.
 */
final class YtDlpDownloader implements MediaDownloader
{
    private const STANDARD_HEIGHTS = [144, 240, 360, 480, 720, 1080, 1440, 2160, 4320];
    private const INFO_TAG = 'YTDINFO ';
    private const SEARCH_TAG = 'YTDS ';
    private const FORMATS_TAG = 'YTDFMT ';
    private const MAX_INFO_BYTES = 8388608;
    private const PROGRESS_TAG = 'YTDP|';
    private const PROCESSING_PATTERN = '/^\[(Merger|ExtractAudio|VideoConvertor|VideoRemuxer|Metadata|EmbedThumbnail|FixupM3u8|FixupM4a|FixupStretched|FixupTimestamp|ModifyChapters|MoveFiles)\]/';

    public function __construct(
        private AppConfig $config,
        private ProcessService $processes,
        private SecurityService $security,
    ) {
    }

    // ------------------------------------------------------------------ command construction

    /** @return list<string> */
    private function baseArgs(): array
    {
        $args = [
            $this->config->ytdlpBin,
            '--ignore-config',
            '--no-playlist',
            '--no-warnings',
            '--no-color',
            '--no-mtime',
            '--socket-timeout', '30',
            '--retries', '3',
            '--fragment-retries', '3',
            '--cache-dir', $this->config->path('temp', '_cache'),
        ];
        if ($this->config->ytdlpJsRuntime !== '' && preg_match('#^[A-Za-z0-9_.:/\\\\ -]+$#', $this->config->ytdlpJsRuntime) === 1) {
            $args[] = '--js-runtimes';
            $args[] = $this->config->ytdlpJsRuntime;
        }
        if (str_contains($this->config->ffmpegBin, '/') || str_contains($this->config->ffmpegBin, '\\')) {
            $args[] = '--ffmpeg-location';
            $args[] = $this->config->ffmpegBin;
        }
        return $args;
    }

    /**
     * yt-dlp format sort for MP4: prefer H.264/AAC (plays everywhere) and cap the resolution when a
     * quality is chosen. Streams are merged by FFmpeg into an .mp4 container.
     *
     * @return list<string>
     */
    public function mp4FormatArgs(string $quality): array
    {
        $res = $quality === 'best' ? 'res' : 'res:' . (int) $quality;
        $sort = ($quality === 'best' ? 'vcodec:avc1,res,acodec:m4a,channels:2' : $res . ',vcodec:avc1,acodec:m4a,channels:2');
        // "bv+ba" (separate DASH streams) is tried before "bv*" so the low-quality progressive
        // format 18 is only a last resort; FFmpeg merges the two streams into an .mp4.
        return ['-f', 'bv+ba/bv*+ba/b', '-S', $sort, '--merge-output-format', 'mp4', '--remux-video', 'mp4'];
    }

    /** @return list<string> */
    public function mp3FormatArgs(int $bitrateKbps): array
    {
        // Keep title/artist/album/date only: the (often huge) video description is not useful MP3 metadata.
        return ['-f', 'ba/b', '-x', '--audio-format', 'mp3', '--audio-quality', $bitrateKbps . 'K', '--embed-metadata', '--replace-in-metadata', 'description', '(?s).+', ''];
    }

    /** @return list<string> */
    public function buildInfoCommand(string $url, string $format, string $quality): array
    {
        $cmd = $this->baseArgs();
        if ($format === 'mp4') {
            $cmd = array_merge($cmd, array_slice($this->mp4FormatArgs($quality), 0, 4));
        } elseif ($format === 'mp3') {
            $cmd = array_merge($cmd, ['-f', 'ba/b']);
        }
        $cmd[] = '--print';
        $cmd[] = self::INFO_TAG . '%(.{id,title,uploader,channel,duration,thumbnail,is_live,live_status,webpage_url,filesize,filesize_approx})j';
        $cmd[] = '--print';
        $cmd[] = self::FORMATS_TAG . '%(formats.:.{format_id,ext,height,vcodec,acodec,abr,filesize,filesize_approx})j';
        $cmd[] = '--';
        $cmd[] = $url;
        return $cmd;
    }

    /** @return list<string> */
    public function buildDownloadCommand(string $url, string $format, string $quality, int $bitrate, string $outDir): array
    {
        $cmd = $this->baseArgs();
        $cmd = array_merge($cmd, [
            '--newline', '--progress',
            '--progress-template', 'download:' . self::PROGRESS_TAG
                . '%(progress.status)s|%(progress.downloaded_bytes)s|%(progress.total_bytes)s|%(progress.total_bytes_estimate)s|%(progress.speed)s|%(progress.eta)s|%(progress.filename)s',
            '--max-filesize', (string) $this->config->maxDownloadSizeBytes,
            '-o', $outDir . DIRECTORY_SEPARATOR . '%(id)s.%(ext)s',
        ]);
        $cmd = array_merge($cmd, $format === 'mp3' ? $this->mp3FormatArgs($bitrate) : $this->mp4FormatArgs($quality));
        $cmd[] = '--';
        $cmd[] = $url;
        return $cmd;
    }

    // ------------------------------------------------------------------ MediaDownloader

    public function startInfo(string $url, string $format, string $quality): MediaTask
    {
        $process = $this->processes->start($this->buildInfoCommand($url, $format, $quality));
        return new MediaTask(MediaTask::INFO, $process, $format);
    }

    public function startMp4(string $url, string $quality, string $outDir): MediaTask
    {
        $process = $this->processes->start($this->buildDownloadCommand($url, 'mp4', $quality, SecurityService::DEFAULT_BITRATE, $outDir));
        return new MediaTask(MediaTask::DOWNLOAD, $process, 'mp4', $outDir);
    }

    public function startMp3(string $url, int $bitrateKbps, string $outDir): MediaTask
    {
        $process = $this->processes->start($this->buildDownloadCommand($url, 'mp3', 'best', $bitrateKbps, $outDir));
        return new MediaTask(MediaTask::DOWNLOAD, $process, 'mp3', $outDir);
    }

    public function cancel(MediaTask $task): void
    {
        $this->processes->terminate($task->process);
    }

    public function kill(MediaTask $task): void
    {
        $this->processes->kill($task->process);
    }

    /** @return list<string> */
    public function buildSearchCommand(string $query, int $limit): array
    {
        $cmd = $this->baseArgs();
        // the "ytsearchN:" prefix plus "--" make it impossible for the query to be parsed as an option
        $cmd[] = '--flat-playlist';
        $cmd[] = '--print';
        $cmd[] = self::SEARCH_TAG . '%(.{id,title,uploader,channel,duration,live_status})j';
        $cmd[] = '--';
        $cmd[] = 'ytsearch' . $limit . ':' . $query;
        return $cmd;
    }

    public function search(string $query, int $limit): array
    {
        $limit = max(1, min(25, $limit));
        $r = $this->processes->run($this->buildSearchCommand($query, $limit), $this->config->infoTimeoutSeconds);
        if ($r['timeout']) {
            throw new ApiException('DOWNLOAD_FAILED', 'Unable to connect. Please check your internet connection.', 504);
        }
        if ($r['exit'] !== 0) {
            [$code, $message] = self::classifyError($r['stderr']);
            throw new ApiException($code, $message);
        }
        $results = [];
        foreach (explode("\n", $r['stdout']) as $line) {
            if (!str_starts_with($line, self::SEARCH_TAG)) {
                continue;
            }
            $item = json_decode(substr($line, strlen(self::SEARCH_TAG)), true);
            if (!is_array($item) || !isset($item['id']) || preg_match('/^[A-Za-z0-9_-]{11}$/', (string) $item['id']) !== 1) {
                continue;
            }
            if (in_array($item['live_status'] ?? '', ['is_live', 'is_upcoming'], true)) {
                continue; // live streams cannot be downloaded
            }
            $id = (string) $item['id'];
            $duration = isset($item['duration']) && is_numeric($item['duration']) ? (int) round((float) $item['duration']) : null;
            $uploader = $item['uploader'] ?? $item['channel'] ?? null;
            $results[] = [
                'video_id' => $id,
                'title' => isset($item['title']) ? (string) $item['title'] : $id,
                'uploader' => $uploader !== null ? (string) $uploader : null,
                'duration' => $duration,
                'duration_formatted' => $duration !== null ? self::formatDuration($duration) : null,
                // derived from the validated id: always an https YouTube image host
                'thumbnail' => 'https://i.ytimg.com/vi/' . $id . '/mqdefault.jpg',
                'webpage_url' => 'https://www.youtube.com/watch?v=' . $id,
            ];
        }
        return $results;
    }

    public function getInfo(string $url): array
    {
        $task = $this->startInfo($url, '', 'best');
        $deadline = microtime(true) + $this->config->infoTimeoutSeconds;
        while (true) {
            $this->getProgress($task);
            if (!$task->process->isRunning()) {
                $this->getProgress($task);
                break;
            }
            if (microtime(true) > $deadline) {
                $this->processes->kill($task->process);
                throw new ApiException('DOWNLOAD_FAILED', 'Unable to connect. Please check your internet connection.', 504);
            }
            usleep(50000);
        }
        $exit = $task->process->exitCode();
        $stderr = $task->process->stderrTail();
        $task->process->close();
        if ($exit !== 0) {
            [$code, $message] = self::classifyError($stderr);
            throw new ApiException($code, $message);
        }
        return $this->parseInfoTask($task);
    }

    /**
     * Turn a finished info task into normalised metadata.
     *
     * @return array<string,mixed>
     * @throws ApiException
     */
    public function parseInfoTask(MediaTask $task): array
    {
        $info = null;
        $formats = [];
        foreach (explode("\n", $task->infoJson) as $line) {
            if (str_starts_with($line, self::INFO_TAG)) {
                $decoded = json_decode(substr($line, strlen(self::INFO_TAG)), true);
                $info = is_array($decoded) ? $decoded : null;
            } elseif (str_starts_with($line, self::FORMATS_TAG)) {
                $decoded = json_decode(substr($line, strlen(self::FORMATS_TAG)), true);
                $formats = is_array($decoded) ? $decoded : [];
            }
        }
        if ($info === null || empty($info['id'])) {
            throw new ApiException('VIDEO_UNAVAILABLE', 'The requested video could not be downloaded.');
        }
        return $this->normalizeInfo($info, $formats);
    }

    /**
     * @param array<string,mixed> $info
     * @param list<array<string,mixed>> $formats
     * @return array<string,mixed>
     */
    public function normalizeInfo(array $info, array $formats): array
    {
        $isLive = !empty($info['is_live']) || in_array($info['live_status'] ?? '', ['is_live', 'is_upcoming'], true);
        $duration = isset($info['duration']) && is_numeric($info['duration']) ? (int) round((float) $info['duration']) : null;

        $buckets = [];
        $hasVideo = false;
        $hasAudio = false;
        $maxAbr = 0.0;
        foreach ($formats as $f) {
            if (!is_array($f)) {
                continue;
            }
            $vcodec = $f['vcodec'] ?? null;
            $acodec = $f['acodec'] ?? null;
            $height = isset($f['height']) && is_numeric($f['height']) ? (int) $f['height'] : 0;
            if ($vcodec !== null && $vcodec !== 'none' && $height > 0) {
                $hasVideo = true;
                $buckets[self::bucketFor($height)] = true;
            }
            if ($acodec !== null && $acodec !== 'none') {
                $hasAudio = true;
                if (($vcodec === null || $vcodec === 'none') && isset($f['abr']) && is_numeric($f['abr'])) {
                    $maxAbr = max($maxAbr, (float) $f['abr']);
                }
            }
        }
        $heights = array_keys($buckets);
        rsort($heights);
        $qualities = array_merge(['best'], array_map(static fn (int $h): string => $h . 'p', $heights));

        $size = $info['filesize'] ?? $info['filesize_approx'] ?? null;
        $size = is_numeric($size) ? (int) $size : null;

        $uploader = $info['uploader'] ?? $info['channel'] ?? null;
        $id = (string) $info['id'];

        return [
            'video_id' => $id,
            'title' => isset($info['title']) ? (string) $info['title'] : $id,
            'uploader' => $uploader !== null ? (string) $uploader : null,
            'duration' => $duration,
            'duration_formatted' => $duration !== null ? self::formatDuration($duration) : null,
            'thumbnail' => $this->security->safeThumbnail($info['thumbnail'] ?? null),
            'webpage_url' => 'https://www.youtube.com/watch?v=' . $id,
            'is_live' => $isLive,
            'formats' => [
                'mp4' => $formats === [] ? true : $hasVideo,
                'mp3' => $formats === [] ? true : $hasAudio,
            ],
            'qualities' => $qualities,
            'filesize' => $size,
            'source_audio_bitrate' => $maxAbr > 0 ? (int) round($maxAbr) : null,
            'mp3_bitrates' => SecurityService::BITRATES,
            'default_mp3_bitrate' => SecurityService::DEFAULT_BITRATE,
        ];
    }

    public function getProgress(MediaTask $task): Progress
    {
        $lines = $task->process->readStdout();
        if (!$task->process->isRunning()) {
            $lines = array_merge($lines, $task->process->readStdout(true));
        }
        foreach ($lines as $line) {
            $this->handleLine($task, $line);
        }
        $task->process->readStderr();
        if (!$task->process->isRunning()) {
            $task->process->readStderr(true);
        }
        return $this->snapshot($task);
    }

    public function handleLine(MediaTask $task, string $line): void
    {
        if ($task->kind === MediaTask::INFO) {
            if (str_starts_with($line, 'YTD') && strlen($task->infoJson) + strlen($line) < self::MAX_INFO_BYTES) {
                $task->infoJson .= $line . "\n";
            }
            return;
        }
        if (str_starts_with($line, self::PROGRESS_TAG)) {
            $this->applyProgressLine($task, substr($line, strlen(self::PROGRESS_TAG)));
            return;
        }
        if (preg_match(self::PROCESSING_PATTERN, $line) === 1) {
            $task->processing = true;
            $task->stage = 'processing';
        }
    }

    /** Parse "status|downloaded|total|estimate|speed|eta|filename". */
    private function applyProgressLine(MediaTask $task, string $payload): void
    {
        $f = explode('|', $payload, 7);
        if (count($f) < 7) {
            return;
        }
        [$status, $downloaded, $total, $estimate, $speed, $eta, $file] = $f;
        $num = static fn (string $v): ?float => is_numeric($v) ? (float) $v : null;
        $downloaded = (int) ($num($downloaded) ?? 0);
        $streamTotal = $num($total) ?? $num($estimate);

        if ($task->currentStreamFile !== null && $task->currentStreamFile !== $file) {
            // A new stream started without a "finished" event (e.g. fragment merge): bank the old one.
            $task->finishedBytes += $task->currentStreamTotal;
            $task->streamIndex++;
        }
        $task->currentStreamFile = $file;
        $task->currentStreamTotal = $downloaded;

        if ($status === 'finished') {
            $task->finishedBytes += $downloaded;
            $task->currentStreamFile = null;
            $task->currentStreamTotal = 0;
            $task->streamIndex++;
            $task->downloadedBytes = $task->finishedBytes;
            $task->speedBps = null;
            $task->etaSeconds = null;
            return;
        }

        $task->stage = 'downloading';
        $current = $downloaded;
        $task->downloadedBytes = $task->finishedBytes + $current;
        $task->speedBps = $num($speed);
        $task->etaSeconds = $num($eta) !== null ? (int) $num($eta) : null;

        if ($task->expectedTotalBytes > 0) {
            $task->totalBytes = max($task->expectedTotalBytes, $task->downloadedBytes);
            $task->percent = min(99.9, $task->downloadedBytes / $task->expectedTotalBytes * 100);
        } elseif ($streamTotal !== null && $streamTotal > 0 && $task->streamIndex === 0) {
            // Single known stream: exact progress straight from yt-dlp.
            $task->totalBytes = (int) $streamTotal;
            $task->percent = min(99.9, $current / $streamTotal * 100);
        } else {
            $task->totalBytes = null;
            $task->percent = null;
        }
    }

    private function snapshot(MediaTask $task): Progress
    {
        if ($task->processing) {
            $p = new Progress('processing', 100.0, $task->downloadedBytes, $task->totalBytes ?? ($task->downloadedBytes ?: null), null, null);
            $p->indeterminate = true;
            return $p;
        }
        $p = new Progress($task->stage, $task->percent, $task->downloadedBytes, $task->totalBytes, $task->speedBps, $task->etaSeconds);
        $p->indeterminate = $task->kind === MediaTask::DOWNLOAD && $task->percent === null;
        return $p;
    }

    public function versions(): array
    {
        $cacheFile = $this->config->path('temp', '_versions.json');
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - 300) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && array_key_exists('yt_dlp', $cached)) {
                return ['yt_dlp' => $cached['yt_dlp'], 'ffmpeg' => $cached['ffmpeg']];
            }
        }
        $yt = $this->probe([$this->config->ytdlpBin, '--version'], '/^\s*(\S+)/');
        $ff = $this->probe([$this->config->ffmpegBin, '-version'], '/ffmpeg version\s+(\S+)/i');
        $result = ['yt_dlp' => $yt, 'ffmpeg' => $ff];
        if ($yt !== null && $ff !== null) {
            @file_put_contents($cacheFile, json_encode($result), LOCK_EX);
        }
        return $result;
    }

    /** @param list<string> $command */
    private function probe(array $command, string $pattern): ?string
    {
        try {
            $r = $this->processes->run($command, 15);
        } catch (\Throwable) {
            return null;
        }
        if ($r['exit'] !== 0 || preg_match($pattern, $r['stdout'], $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Map yt-dlp stderr to a stable error code and a friendly message (no raw output is exposed).
     *
     * @return array{0:string,1:string}
     */
    public static function classifyError(string $stderr): array
    {
        $s = strtolower($stderr);
        $has = static function (array $needles) use ($s): bool {
            foreach ($needles as $n) {
                if (str_contains($s, $n)) {
                    return true;
                }
            }
            return false;
        };
        if ($has(['larger than max-filesize', 'max-filesize'])) {
            return ['FILE_TOO_LARGE', 'The file is larger than the allowed maximum size.'];
        }
        if ($has(['postprocessing', 'ffmpeg', 'ffprobe', 'conversion failed', 'error while decoding'])) {
            return ['PROCESSING_FAILED', 'Media processing failed.'];
        }
        if ($has(['http error 429', 'too many requests'])) {
            return ['DOWNLOAD_FAILED', 'YouTube is temporarily limiting requests. Please try again later.'];
        }
        if ($has(['unable to download webpage', 'getaddrinfo', 'name resolution', 'network is unreachable', 'timed out', 'connection reset', 'connection refused', 'urlopen error', 'no route to host', 'ssl:'])) {
            return ['DOWNLOAD_FAILED', 'Unable to connect. Please check your internet connection.'];
        }
        if ($has(['video unavailable', 'private video', 'this video is not available', 'is unavailable', 'has been removed', 'members-only', 'sign in', 'confirm your age', 'copyright', 'not available in your country', 'live event will begin', 'premieres in', 'requested format is not available', 'age-restricted', 'drm'])) {
            return ['VIDEO_UNAVAILABLE', 'The requested video could not be downloaded.'];
        }
        return ['DOWNLOAD_FAILED', 'The requested video could not be downloaded.'];
    }

    public static function formatDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    private static function bucketFor(int $height): int
    {
        foreach (self::STANDARD_HEIGHTS as $standard) {
            if ($height <= $standard) {
                return $standard;
            }
        }
        return 4320;
    }
}
