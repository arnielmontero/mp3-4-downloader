<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Media\MediaTask;
use App\Media\YtDlpDownloader;
use App\Services\ProcessService;
use Tests\Support\TestCase;

final class YtDlpDownloaderTest extends TestCase
{
    private const URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    private function downloader(array $env = []): YtDlpDownloader
    {
        $c = $this->container($env);
        return new YtDlpDownloader($c->config(), $c->processes(), $c->security());
    }

    /** A real (idle) process so MediaTask can be constructed; it is killed in tearDown paths. */
    private function task(string $kind = MediaTask::DOWNLOAD, string $format = 'mp4'): MediaTask
    {
        $p = (new ProcessService())->start([PHP_BINARY, '-r', 'usleep(200000);']);
        return new MediaTask($kind, $p, $format, $this->storage);
    }

    // ------------------------------------------------------------ command construction

    public function testDownloadCommandIsAnArgumentArrayWithUrlAfterDoubleDash(): void
    {
        $cmd = $this->downloader(['YTDLP_BIN' => '/usr/bin/yt-dlp'])->buildDownloadCommand(self::URL, 'mp4', 'best', 192, '/tmp/job');
        $this->assertIsArray($cmd);
        $this->assertSame('/usr/bin/yt-dlp', $cmd[0]);
        $this->assertSame(self::URL, end($cmd));
        $this->assertSame('--', $cmd[count($cmd) - 2], 'URL must follow "--" so it can never be parsed as an option');
        $this->assertContains('--ignore-config', $cmd, 'user/system yt-dlp config must not influence the app');
        $this->assertContains('--no-playlist', $cmd);
        foreach ($cmd as $part) {
            $this->assertIsString($part);
        }
    }

    public function testHostileUrlStaysASingleArgument(): void
    {
        $evil = 'https://x/; rm -rf / && `id` $(id) --exec id';
        $cmd = $this->downloader()->buildDownloadCommand($evil, 'mp3', 'best', 192, '/tmp/job');
        $this->assertSame($evil, end($cmd));
        $this->assertSame(1, count(array_keys($cmd, $evil, true)));
    }

    public function testMp4QualitySelection(): void
    {
        $d = $this->downloader();
        $best = $d->mp4FormatArgs('best');
        $this->assertContains('--merge-output-format', $best);
        $this->assertSame('mp4', $best[array_search('--merge-output-format', $best, true) + 1]);
        $this->assertSame('mp4', $best[array_search('--remux-video', $best, true) + 1]);
        $this->assertStringStartsWith('vcodec:avc1,res', $best[array_search('-S', $best, true) + 1]);
        $this->assertStringContainsString('bv+ba', $best[array_search('-f', $best, true) + 1]);

        $q720 = $d->mp4FormatArgs('720p');
        $this->assertStringStartsWith('res:720,', $q720[array_search('-S', $q720, true) + 1]);
        $q1080 = $d->mp4FormatArgs('1080p');
        $this->assertStringStartsWith('res:1080,', $q1080[array_search('-S', $q1080, true) + 1]);
    }

    public function testMp3Arguments(): void
    {
        $d = $this->downloader();
        foreach ([128, 192, 256, 320] as $kbps) {
            $args = $d->mp3FormatArgs($kbps);
            $this->assertContains('-x', $args);
            $this->assertSame('mp3', $args[array_search('--audio-format', $args, true) + 1]);
            $this->assertSame($kbps . 'K', $args[array_search('--audio-quality', $args, true) + 1]);
            $this->assertContains('--embed-metadata', $args);
        }
        $cmd = $d->buildDownloadCommand(self::URL, 'mp3', 'best', 256, '/tmp/j');
        $this->assertContains('256K', $cmd);
    }

    public function testLimitsAndOutputTemplateAreSetByTheApplication(): void
    {
        $cmd = $this->downloader(['MAX_DOWNLOAD_SIZE_GB' => '2'])->buildDownloadCommand(self::URL, 'mp4', 'best', 192, '/var/www/storage/temp/abc');
        $this->assertSame((string) (2 * 1024 ** 3), $cmd[array_search('--max-filesize', $cmd, true) + 1]);
        $template = $cmd[array_search('-o', $cmd, true) + 1];
        $this->assertStringStartsWith('/var/www/storage/temp/abc', $template);
        $this->assertStringEndsWith('%(id)s.%(ext)s', $template, 'server-chosen names; the title never reaches the filesystem here');
    }

    public function testInfoCommandNeverDownloads(): void
    {
        $cmd = $this->downloader()->buildInfoCommand(self::URL, 'mp4', '720p');
        $this->assertContains('--print', $cmd);
        $this->assertNotContains('-o', $cmd);
        $this->assertNotContains('-x', $cmd);
        $this->assertSame(self::URL, end($cmd));
    }

    public function testJsRuntimeOptionIsValidated(): void
    {
        $ok = $this->downloader(['YTDLP_JS_RUNTIME' => 'deno'])->buildInfoCommand(self::URL, '', 'best');
        $this->assertContains('--js-runtimes', $ok);
        $bad = $this->downloader(['YTDLP_JS_RUNTIME' => 'deno; rm -rf /'])->buildInfoCommand(self::URL, '', 'best');
        $this->assertNotContains('--js-runtimes', $bad);
    }

    // ------------------------------------------------------------ progress parsing

    public function testProgressWithKnownExpectedTotal(): void
    {
        $d = $this->downloader();
        $task = $this->task();
        $task->expectedTotalBytes = 1000;
        $d->handleLine($task, 'YTDP|downloading|250|400|NA|2097152|5|vid.f137.mp4');
        $this->assertEqualsWithDelta(25.0, $task->percent, 0.01);
        $this->assertSame(2097152.0, $task->speedBps);
        $this->assertSame(5, $task->etaSeconds);

        // first stream finishes, second (audio) starts: progress keeps climbing across both streams
        $d->handleLine($task, 'YTDP|finished|400|400|NA|NA|NA|vid.f137.mp4');
        $d->handleLine($task, 'YTDP|downloading|100|600|NA|1000|3|vid.f140.m4a');
        $this->assertEqualsWithDelta(50.0, $task->percent, 0.01);
        $this->assertSame(500, $task->downloadedBytes);
        $task->process->close();
    }

    public function testProgressNeverReportsCompleteBeforeProcessing(): void
    {
        $d = $this->downloader();
        $task = $this->task();
        $task->expectedTotalBytes = 100;
        $d->handleLine($task, 'YTDP|downloading|500|500|NA|1|0|a.mp4');
        $this->assertSame(99.9, $task->percent);
        $task->process->close();
    }

    public function testProgressFromSingleStreamWithoutExpectedTotal(): void
    {
        $d = $this->downloader();
        $task = $this->task();
        $d->handleLine($task, 'YTDP|downloading|500|2000|NA|100|15|a.m4a');
        $this->assertEqualsWithDelta(25.0, $task->percent, 0.01);
        $this->assertSame(2000, $task->totalBytes);
        $task->process->close();
    }

    public function testUnknownSizeIsIndeterminateNotFaked(): void
    {
        $d = $this->downloader();
        $task = $this->task();
        $d->handleLine($task, 'YTDP|downloading|500|NA|NA|NA|NA|a.mp4');
        $this->assertNull($task->percent);
        $progress = $d->getProgress($task);
        $this->assertTrue($progress->indeterminate);
        $this->assertNull($progress->percent);
        $task->process->close();
    }

    public function testEstimatedTotalIsUsedWhenExactTotalMissing(): void
    {
        $d = $this->downloader();
        $task = $this->task();
        $d->handleLine($task, 'YTDP|downloading|500|NA|1000|100|5|a.mp4');
        $this->assertEqualsWithDelta(50.0, $task->percent, 0.01);
        $task->process->close();
    }

    public function testPostProcessingLinesSwitchToProcessingStage(): void
    {
        $d = $this->downloader();
        $task = $this->task();
        $d->handleLine($task, 'YTDP|downloading|100|100|NA|1|0|a.mp4');
        $this->assertSame('downloading', $task->stage);
        foreach (['[Merger] Merging formats into "x.mp4"', '[ExtractAudio] Destination: x.mp3', '[VideoConvertor] Converting'] as $line) {
            $t = $this->task();
            $d->handleLine($t, $line);
            $p = $d->getProgress($t);
            $this->assertSame('processing', $p->stage);
            $this->assertTrue($p->indeterminate, 'FFmpeg gives no percentage, so the bar must be indeterminate');
            $t->process->close();
        }
        $d->handleLine($task, '[download] Destination: something');
        $this->assertSame('downloading', $task->stage, 'unrelated lines are ignored');
        $task->process->close();
    }

    public function testGarbageProgressLinesAreIgnored(): void
    {
        $d = $this->downloader();
        $task = $this->task();
        $d->handleLine($task, 'YTDP|incomplete');
        $d->handleLine($task, 'YTDP|downloading|abc|def|NA|NA|NA|x');
        $this->assertSame(0, $task->downloadedBytes);
        $task->process->close();
    }

    // ------------------------------------------------------------ metadata

    public function testNormalizeInfoBuildsQualitiesFromActualFormats(): void
    {
        $d = $this->downloader();
        $info = $d->normalizeInfo(
            ['id' => 'dQw4w9WgXcQ', 'title' => 'T', 'uploader' => 'U', 'duration' => 632, 'thumbnail' => 'https://i.ytimg.com/vi/x/hq.jpg', 'filesize' => 1000],
            [
                ['vcodec' => 'none', 'acodec' => 'mp4a', 'abr' => 129.7, 'height' => null],
                ['vcodec' => 'none', 'acodec' => 'opus', 'abr' => 50.0, 'height' => null],
                ['vcodec' => 'avc1', 'acodec' => 'none', 'height' => 1080],
                ['vcodec' => 'vp9', 'acodec' => 'none', 'height' => 720],
                ['vcodec' => 'avc1', 'acodec' => 'none', 'height' => 360],
                ['vcodec' => 'avc1', 'acodec' => 'none', 'height' => 360],
                ['vcodec' => 'avc1', 'acodec' => 'mp4a', 'height' => 144],
            ]
        );
        $this->assertSame(['best', '1080p', '720p', '360p', '144p'], $info['qualities']);
        $this->assertSame('10:32', $info['duration_formatted']);
        $this->assertSame(632, $info['duration']);
        $this->assertTrue($info['formats']['mp4']);
        $this->assertTrue($info['formats']['mp3']);
        $this->assertSame(130, $info['source_audio_bitrate']);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $info['webpage_url']);
        $this->assertSame([128, 192, 256, 320], $info['mp3_bitrates']);
        $this->assertSame('https://i.ytimg.com/vi/x/hq.jpg', $info['thumbnail']);
    }

    public function testNormalizeInfoHandlesAudioOnlyAndLiveAndHoursFormatting(): void
    {
        $d = $this->downloader();
        $audioOnly = $d->normalizeInfo(['id' => 'dQw4w9WgXcQ', 'duration' => 3725.4], [['vcodec' => 'none', 'acodec' => 'opus', 'abr' => 100]]);
        $this->assertFalse($audioOnly['formats']['mp4']);
        $this->assertTrue($audioOnly['formats']['mp3']);
        $this->assertSame(['best'], $audioOnly['qualities']);
        $this->assertSame('1:02:05', $audioOnly['duration_formatted']);
        $this->assertSame('dQw4w9WgXcQ', $audioOnly['title'], 'falls back to the id; never invents a title');

        $live = $d->normalizeInfo(['id' => 'dQw4w9WgXcQ', 'is_live' => true], []);
        $this->assertTrue($live['is_live']);
        $this->assertNull($live['duration']);

        $evilThumb = $d->normalizeInfo(['id' => 'dQw4w9WgXcQ', 'thumbnail' => 'https://evil.example/x.png'], []);
        $this->assertNull($evilThumb['thumbnail']);
    }

    // ------------------------------------------------------------ error mapping

    public function testErrorClassification(): void
    {
        $cases = [
            'ERROR: [youtube] abc: Video unavailable' => ['VIDEO_UNAVAILABLE', 'The requested video could not be downloaded.'],
            'ERROR: [youtube] abc: Private video. Sign in if you have been granted access' => ['VIDEO_UNAVAILABLE', 'The requested video could not be downloaded.'],
            'ERROR: Sign in to confirm your age' => ['VIDEO_UNAVAILABLE', 'The requested video could not be downloaded.'],
            'ERROR: Postprocessing: ffmpeg exited with code 1' => ['PROCESSING_FAILED', 'Media processing failed.'],
            'ERROR: ffprobe and ffmpeg not found' => ['PROCESSING_FAILED', 'Media processing failed.'],
            'ERROR: Unable to download webpage: <urlopen error [Errno -3] Temporary failure in name resolution>' => ['DOWNLOAD_FAILED', 'Unable to connect. Please check your internet connection.'],
            'ERROR: unable to download video data: HTTP Error 403: Forbidden' => ['DOWNLOAD_FAILED', 'The requested video could not be downloaded.'],
            'ERROR: HTTP Error 429: Too Many Requests' => ['DOWNLOAD_FAILED', 'YouTube is temporarily limiting requests. Please try again later.'],
            'File is larger than max-filesize (123 bytes > 100 bytes). Aborting.' => ['FILE_TOO_LARGE', 'The file is larger than the allowed maximum size.'],
            'something completely unexpected' => ['DOWNLOAD_FAILED', 'The requested video could not be downloaded.'],
            '' => ['DOWNLOAD_FAILED', 'The requested video could not be downloaded.'],
        ];
        foreach ($cases as $stderr => $expected) {
            $this->assertSame($expected, YtDlpDownloader::classifyError($stderr), $stderr);
        }
    }

    public function testClassifiedMessagesNeverLeakRawOutput(): void
    {
        [, $message] = YtDlpDownloader::classifyError("ERROR: failed at /var/www/storage/temp/abc cookie=SECRET123 token=xyz");
        $this->assertStringNotContainsString('/var/www', $message);
        $this->assertStringNotContainsString('SECRET', $message);
    }
}
