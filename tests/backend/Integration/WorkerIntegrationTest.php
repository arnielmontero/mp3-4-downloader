<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Container;
use Tests\Support\TestCase;

/**
 * Drives the real Worker (real process spawning, real FFmpeg) against tests/fixtures/fake-yt-dlp.
 * Linux only (process groups, shebang script) - it runs inside the Docker test stage.
 */
final class WorkerIntegrationTest extends TestCase
{
    private Container $c;
    private \App\Worker\Worker $worker;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('worker integration tests need Linux (run: docker build --target test)');
        }
        $fake = dirname(__DIR__, 2) . '/fixtures/fake-yt-dlp';
        $this->assertFileExists($fake);
        @chmod($fake, 0755);
        $this->boot([]);
    }

    protected function tearDown(): void
    {
        // never leave fake downloads running between tests
        if (isset($this->worker) && $this->worker->activeCount() > 0) {
            foreach ($this->c->jobs()->all() as $job) {
                $this->c->jobs()->patch($job['id'], ['cancel_requested' => true]);
            }
            $this->pump(fn (): bool => $this->worker->activeCount() === 0, 15);
        }
        parent::tearDown();
    }

    /** @param array<string,string|int> $env */
    private function boot(array $env): void
    {
        $this->c = $this->container($env + [
            'YTDLP_BIN' => dirname(__DIR__, 2) . '/fixtures/fake-yt-dlp',
            'MAX_CONCURRENT_DOWNLOADS' => 2,
            'KILL_GRACE_SECONDS' => 2,
            'FFMPEG_BIN' => 'ffmpeg',
        ]);
        $this->worker = $this->c->worker();
    }

    /** Tick the worker until $done() or timeout. */
    private function pump(callable $done, int $timeoutSeconds = 40): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $this->worker->tick();
            if ($done()) {
                return true;
            }
            usleep(100000);
        }
        return false;
    }

    private function create(string $videoId, string $format = 'mp4', array $extra = []): string
    {
        return $this->c->downloads()->create(['url' => "https://www.youtube.com/watch?v=$videoId", 'format' => $format] + $extra, 'cl')['job_id'];
    }

    private function jobStatus(string $id): string
    {
        return $this->c->jobs()->get($id)['status'];
    }

    private function waitTerminal(string $id, int $timeout = 40): array
    {
        $this->pump(fn (): bool => in_array($this->jobStatus($id), ['completed', 'failed', 'cancelled'], true), $timeout);
        return $this->c->jobs()->get($id);
    }

    /** @return list<string> command lines of live processes that match $needle */
    private function processes(string $needle): array
    {
        $out = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $cmd = str_replace("\0", ' ', (string) @file_get_contents($file));
            $pid = (int) explode('/', $file)[2];
            if ($pid !== getmypid() && str_contains($cmd, $needle)) {
                $state = (string) @file_get_contents("/proc/$pid/stat");
                if (!preg_match('/^\d+ \([^)]*\) Z/', $state)) { // ignore zombies
                    $out[] = trim($cmd);
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------

    public function testMp4JobCompletesAndProducesPlayableFile(): void
    {
        $id = $this->create('weirdtitle1', 'mp4', ['quality' => '720p']);
        $job = $this->waitTerminal($id);
        $this->assertSame('completed', $job['status'], json_encode($job['error']));
        $this->assertSame('My Video - Episode 1 - Test.mp4', $job['filename'], 'title is sanitised into a safe name');
        $this->assertEquals(100, $job['progress']);

        $file = $this->c->downloads()->fileFor($id);
        $this->assertSame('video/mp4', $file['type']);
        $this->assertGreaterThan(1000, $file['size']);
        $probe = shell_exec('ffprobe -v error -show_entries stream=codec_name -of csv=p=0 ' . escapeshellarg($file['path']));
        $this->assertStringContainsString('h264', (string) $probe);
        $this->assertDirectoryDoesNotExist($this->storage . '/temp/' . $id, 'temp files are removed after success');

        $calls = (string) file_get_contents('/tmp/fake-ytdlp-calls.log');
        $this->assertMatchesRegularExpression('/res:720,vcodec:avc1/', $calls, 'quality 720p reaches yt-dlp as a resolution cap');
    }

    public function testMp3JobUsesRequestedBitrateAndKeepsMetadata(): void
    {
        $id = $this->create('okmp3video1', 'mp3', ['bitrate' => 320]);
        $job = $this->waitTerminal($id);
        $this->assertSame('completed', $job['status'], json_encode($job['error']));
        $this->assertStringEndsWith('.mp3', $job['filename']);
        $file = $this->c->downloads()->fileFor($id);
        $this->assertSame('audio/mpeg', $file['type']);
        $info = (string) shell_exec('ffprobe -v error -show_entries format_tags=title,artist:format=bit_rate -of default=nw=1 ' . escapeshellarg($file['path']));
        $this->assertStringContainsString('TAG:title=Fake video okmp3video1', $info);
        $this->assertStringContainsString('TAG:artist=Fake Channel', $info);
        $this->assertStringContainsString('bit_rate=3', $info, '320 kbps requested');
        $this->assertStringContainsString('320K', (string) file_get_contents('/tmp/fake-ytdlp-calls.log'));
    }

    public function testDuplicateTitlesDoNotOverwriteEachOther(): void
    {
        $a = $this->create('weirdtitle2');
        $b = $this->create('weirdtitle3');
        $ja = $this->waitTerminal($a);
        $jb = $this->waitTerminal($b);
        $this->assertSame('completed', $ja['status']);
        $this->assertSame('completed', $jb['status']);
        // same sanitised title but separate per-job directories
        $this->assertSame($ja['filename'], $jb['filename']);
        $this->assertNotSame($this->c->downloads()->fileFor($a)['path'], $this->c->downloads()->fileFor($b)['path']);
    }

    public function testProgressIsReportedFromEngineOutput(): void
    {
        $id = $this->create('conc0000001');
        $seen = [];
        $this->pump(function () use ($id, &$seen): bool {
            $job = $this->c->jobs()->get($id);
            if ($job['status'] === 'downloading' && $job['progress'] > 0) {
                $seen[] = (float) $job['progress'];
            }
            return in_array($job['status'], ['completed', 'failed'], true);
        });
        $this->assertGreaterThanOrEqual(2, count($seen), 'progress was observed while downloading');
        $sorted = $seen;
        sort($sorted);
        $this->assertSame($sorted, $seen, 'progress never goes backwards');
        $this->assertLessThanOrEqual(100.0, max($seen));
    }

    public function testQueueRespectsConcurrencyLimit(): void
    {
        $ids = [$this->create('conc0000002'), $this->create('conc0000003'), $this->create('conc0000004')];
        $maxActive = 0;
        $sawQueued = false;
        $this->pump(function () use ($ids, &$maxActive, &$sawQueued): bool {
            $statuses = array_map(fn (string $i): string => $this->jobStatus($i), $ids);
            $active = count(array_filter($statuses, fn (string $s): bool => in_array($s, ['analyzing', 'downloading', 'processing'], true)));
            $maxActive = max($maxActive, $active);
            $sawQueued = $sawQueued || in_array('queued', $statuses, true);
            return count(array_filter($statuses, fn (string $s): bool => $s === 'completed')) === 3;
        }, 90);
        $this->assertSame(2, $maxActive, 'never more than MAX_CONCURRENT_DOWNLOADS at once');
        $this->assertTrue($sawQueued, 'third job waited in the queue');
        foreach ($ids as $id) {
            $this->assertSame('completed', $this->jobStatus($id));
        }
    }

    public function testCancelKillsOnlyThatJobsProcessGroupAndCleansTemp(): void
    {
        $slow1 = $this->create('slowvideo01');
        $slow2 = $this->create('slowvideo02');
        $this->assertTrue($this->pump(fn (): bool => $this->jobStatus($slow1) === 'downloading' && $this->jobStatus($slow2) === 'downloading'));
        $this->pump(fn (): bool => file_exists($this->storage . '/temp/' . $slow1) && FileCount::in($this->storage . '/temp/' . $slow1) > 0, 10);
        $this->assertCount(2, $this->processes('slowvideo0'), 'two fake yt-dlp processes');
        $this->assertCount(2, $this->processes('sleep 120'), 'plus one child each (stands in for FFmpeg)');

        $this->c->jobs()->requestCancel($slow1);
        $this->assertTrue($this->pump(fn (): bool => $this->jobStatus($slow1) === 'cancelled', 15));

        $this->assertDirectoryDoesNotExist($this->storage . '/temp/' . $slow1, 'incomplete temp data removed');
        $this->assertCount(1, $this->processes('slowvideo0'), 'the other job keeps running');
        $this->assertCount(1, $this->processes('sleep 120'), 'cancelled job leaves no orphaned child');
        $this->assertSame('downloading', $this->jobStatus($slow2), 'second job is unaffected');
        $this->assertDirectoryExists($this->storage . '/temp/' . $slow2);

        $this->c->jobs()->requestCancel($slow2);
        $this->assertTrue($this->pump(fn (): bool => $this->jobStatus($slow2) === 'cancelled', 15));
        $this->assertSame([], $this->processes('slowvideo0'));
        $this->assertSame([], $this->processes('sleep 120'));
        $this->assertSame(0, $this->worker->activeCount());
    }

    public function testCancelledJobKeepsCompletedFilesOfOtherJobs(): void
    {
        $done = $this->create('okvideo0002');
        $this->waitTerminal($done);
        $path = $this->c->downloads()->fileFor($done)['path'];
        $slow = $this->create('slowvideo03');
        $this->assertTrue($this->pump(fn (): bool => $this->jobStatus($slow) === 'downloading'));
        $this->c->jobs()->requestCancel($slow);
        $this->assertTrue($this->pump(fn (): bool => $this->jobStatus($slow) === 'cancelled', 15));
        $this->assertFileExists($path, 'completed files are preserved');
    }

    /** @return array<string,array{string,string}> */
    public static function damagedOutputs(): array
    {
        return [
            'truncated mp4' => ['truncvideo1', 'mp4'],
            'truncated mp3' => ['truncvideo2', 'mp3'],
            'garbage instead of media' => ['junkvideo01', 'mp4'],
            'garbage mp3' => ['junkvideo02', 'mp3'],
            'corrupted payload' => ['bitrotvid01', 'mp4'],
            'too short for the announced length' => ['shortvideo1', 'mp3'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('damagedOutputs')]
    public function testDamagedOrIncompleteFilesNeverReachTheUser(string $videoId, string $format): void
    {
        $id = $this->create($videoId, $format);
        $job = $this->waitTerminal($id, 90);
        $this->assertSame('failed', $job['status'], 'a file that is not fully playable must not be offered');
        $this->assertSame('PROCESSING_FAILED', $job['error']['code']);
        $this->assertSame(\App\Services\MediaVerifier::MESSAGE, $job['error']['message']);
        $this->assertSame([], glob($this->storage . '/downloads/' . $id . '/*') ?: [], 'nothing stored for the user');
        $this->assertDirectoryDoesNotExist($this->storage . '/temp/' . $id);
        $this->expectException(\App\Support\ApiException::class);
        $this->c->downloads()->fileFor($id);
    }

    public function testEveryCompletedFileDecodesCleanly(): void
    {
        foreach (['mp4', 'mp3'] as $format) {
            $id = $this->create('okplayable' . ($format === 'mp4' ? '1' : '2'), $format);
            $job = $this->waitTerminal($id, 90);
            $this->assertSame('completed', $job['status'], json_encode($job['error']));
            $file = $this->c->downloads()->fileFor($id);
            $out = shell_exec('ffmpeg -nostdin -v error -xerror -i ' . escapeshellarg($file['path']) . ' -f null - 2>&1');
            $this->assertSame('', trim((string) $out), "a full decode of the delivered $format has no errors");
        }
    }

    public function testFailedDownloadIsMarkedFailedWithFriendlyError(): void
    {
        $id = $this->create('failvideo01');
        $job = $this->waitTerminal($id);
        $this->assertSame('failed', $job['status']);
        $this->assertSame('DOWNLOAD_FAILED', $job['error']['code']);
        $this->assertSame('The requested video could not be downloaded.', $job['error']['message']);
        $this->assertStringNotContainsString('403', json_encode($job['error']));
        $this->assertDirectoryDoesNotExist($this->storage . '/temp/' . $id);
        $this->assertSame([], glob($this->storage . '/downloads/' . $id . '/*') ?: []);
    }

    public function testUnavailableVideoFailsDuringAnalysis(): void
    {
        $id = $this->create('privvideo01');
        $job = $this->waitTerminal($id);
        $this->assertSame('failed', $job['status']);
        $this->assertSame('VIDEO_UNAVAILABLE', $job['error']['code']);
    }

    public function testOverlongVideoIsRejectedByTheWorker(): void
    {
        $id = $this->create('longvideo01'); // not analysed first, so the API cache cannot catch it
        $job = $this->waitTerminal($id);
        $this->assertSame('failed', $job['status']);
        $this->assertSame('VIDEO_TOO_LONG', $job['error']['code']);
    }

    public function testKnownOversizedFileIsRejectedUpFront(): void
    {
        $this->boot(['MAX_DOWNLOAD_SIZE_GB' => '1']);
        $id = $this->create('bigvideo001'); // fake reports 50 GB
        $job = $this->waitTerminal($id);
        $this->assertSame('failed', $job['status']);
        $this->assertSame('FILE_TOO_LARGE', $job['error']['code']);
        $this->assertDirectoryDoesNotExist($this->storage . '/temp/' . $id);
    }

    public function testUnknownSizeDownloadIsStoppedWhenLimitIsReached(): void
    {
        $this->boot(['MAX_DOWNLOAD_SIZE_GB' => '0.002']); // ~2 MB; the fake streams without end
        $id = $this->create('unszvideo01');
        $job = $this->waitTerminal($id, 60);
        $this->assertSame('failed', $job['status']);
        $this->assertSame('FILE_TOO_LARGE', $job['error']['code']);
        $this->assertDirectoryDoesNotExist($this->storage . '/temp/' . $id, 'partial data deleted');
        $this->assertSame([], $this->processes('unszvideo01'), 'engine process was killed');
    }
}

/** tiny helper to avoid a closure-heavy file counting expression */
final class FileCount
{
    public static function in(string $dir): int
    {
        return count(array_diff(scandir($dir) ?: [], ['.', '..']));
    }
}
