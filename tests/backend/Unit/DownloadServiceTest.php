<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\JobService;
use App\Services\VideoInfoService;
use App\Support\ApiException;
use Tests\Support\TestCase;

final class DownloadServiceTest extends TestCase
{
    private const URL = 'https://youtu.be/dQw4w9WgXcQ';

    /** Prime the info cache so create() can pre-validate without running yt-dlp. */
    private function cacheInfo(array $env, array $info): void
    {
        $dir = $this->storage . '/temp/_infocache';
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/dQw4w9WgXcQ.json', json_encode($info + ['video_id' => 'dQw4w9WgXcQ', 'title' => 'Cached', 'uploader' => 'U', 'duration' => 100, 'is_live' => false]));
    }

    public function testCreateReturnsQueuedJobWithNormalizedUrl(): void
    {
        $c = $this->container();
        $job = $c->downloads()->create(['url' => self::URL, 'format' => 'mp4', 'quality' => '720p'], 'client1');
        $this->assertSame('queued', $job['status']);
        $this->assertSame('mp4', $job['format']);
        $this->assertSame('720p', $job['quality']);
        $stored = $c->jobs()->get($job['job_id']);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $stored['url']);
        $this->assertSame('client1', $stored['client_id']);
    }

    public function testMp3JobDefaultsToBestQualityAnd192kbps(): void
    {
        $c = $this->container();
        $job = $c->downloads()->create(['url' => self::URL, 'format' => 'mp3', 'quality' => '720p'], null);
        $stored = $c->jobs()->get($job['job_id']);
        $this->assertSame(192, $stored['bitrate']);
        $this->assertSame('best', $stored['quality'], 'quality is meaningless for audio');
        $this->assertSame(256, $c->jobs()->get($c->downloads()->create(['url' => self::URL, 'format' => 'mp3', 'bitrate' => 256], null)['job_id'])['bitrate']);
    }

    public function testCreateValidatesEverything(): void
    {
        $downloads = $this->container()->downloads();
        $cases = [
            [['url' => '', 'format' => 'mp4'], 'INVALID_URL'],
            [['format' => 'mp4'], 'INVALID_URL'],
            [['url' => 'http://localhost/', 'format' => 'mp4'], 'UNSUPPORTED_DOMAIN'],
            [['url' => self::URL, 'format' => 'avi'], 'INVALID_FORMAT'],
            [['url' => self::URL], 'INVALID_FORMAT'],
            [['url' => self::URL, 'format' => 'mp4', 'quality' => '4k'], 'INVALID_QUALITY'],
            [['url' => self::URL, 'format' => 'mp3', 'bitrate' => 999], 'INVALID_BITRATE'],
        ];
        foreach ($cases as [$input, $code]) {
            try {
                $downloads->create($input, null);
                $this->fail('accepted ' . json_encode($input));
            } catch (ApiException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }
        $this->assertSame([], glob($this->storage . '/jobs/*.json'), 'rejected requests must not create jobs');
    }

    public function testCachedOverlongVideoIsRejectedBeforeQueueing(): void
    {
        $this->cacheInfo([], ['duration' => 3601]);
        $c = $this->container(['MAX_VIDEO_DURATION_MINUTES' => 60]);
        try {
            $c->downloads()->create(['url' => self::URL, 'format' => 'mp4'], null);
            $this->fail('over-long video accepted');
        } catch (ApiException $e) {
            $this->assertSame('VIDEO_TOO_LONG', $e->errorCode());
            $this->assertSame(422, $e->httpStatus());
        }
        $this->assertSame([], glob($this->storage . '/jobs/*.json'));
    }

    public function testDurationLimitBoundary(): void
    {
        $config = $this->config(['MAX_VIDEO_DURATION_MINUTES' => 10]);
        VideoInfoService::assertWithinLimits(['duration' => 600], $config); // exactly at the limit is fine
        $this->expectException(ApiException::class);
        VideoInfoService::assertWithinLimits(['duration' => 601], $config);
    }

    public function testLiveStreamsAreRejected(): void
    {
        $this->expectException(ApiException::class);
        VideoInfoService::assertWithinLimits(['is_live' => true, 'duration' => null], $this->config());
    }

    public function testSizeAndDurationLimitsAreConfigurable(): void
    {
        $c = $this->config(['MAX_DOWNLOAD_SIZE_GB' => '0.5', 'MAX_VIDEO_DURATION_MINUTES' => '90', 'MAX_CONCURRENT_DOWNLOADS' => '4']);
        $this->assertSame(536870912, $c->maxDownloadSizeBytes);
        $this->assertSame(5400, $c->maxVideoDurationSeconds);
        $this->assertSame(4, $c->maxConcurrentDownloads);
        $d = $this->config();
        $this->assertSame(10 * 1024 ** 3, $d->maxDownloadSizeBytes);
        $this->assertSame(240 * 60, $d->maxVideoDurationSeconds);
        $this->assertSame(3, $d->maxConcurrentDownloads);
        $this->assertSame(24 * 3600, $d->retentionSeconds);
    }

    public function testStatusOfUnknownAndInvalidJobs(): void
    {
        $downloads = $this->container()->downloads();
        foreach ([[$this->validId(), 'JOB_NOT_FOUND'], ['../../etc/passwd', 'INVALID_JOB_ID'], ['abc123', 'INVALID_JOB_ID']] as [$id, $code]) {
            try {
                $downloads->status($id);
                $this->fail('expected exception');
            } catch (ApiException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        }
    }

    public function testCancelQueuedJobEndsInCancelled(): void
    {
        $downloads = $this->container()->downloads();
        $job = $downloads->create(['url' => self::URL, 'format' => 'mp4'], null);
        $result = $downloads->cancel($job['job_id']);
        $this->assertSame('cancelled', $result['status']);
        // idempotent
        $this->assertSame('cancelled', $downloads->cancel($job['job_id'])['status']);
    }

    public function testCompletedJobCannotBeCancelledAndKeepsItsFile(): void
    {
        $c = $this->container();
        $job = $c->downloads()->create(['url' => self::URL, 'format' => 'mp3'], null);
        $c->jobs()->patch($job['job_id'], ['status' => 'completed', 'filename' => 'x.mp3']);
        try {
            $c->downloads()->cancel($job['job_id']);
            $this->fail('expected exception');
        } catch (ApiException $e) {
            $this->assertSame('JOB_NOT_CANCELLABLE', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }
        $this->assertSame('completed', $c->jobs()->get($job['job_id'])['status']);
    }

    public function testCancelActiveJobWaitsForWorker(): void
    {
        $c = $this->container();
        $job = $c->downloads()->create(['url' => self::URL, 'format' => 'mp4'], null);
        $c->jobs()->patch($job['job_id'], ['status' => 'downloading']);

        // simulate the worker acknowledging the cancel flag shortly after
        $id = $job['job_id'];
        $jobs = $c->jobs();
        $pid = null;
        if (function_exists('pcntl_fork')) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                usleep(300000);
                $jobs->patch($id, ['status' => 'cancelled', 'finished_at' => gmdate('c')]);
                exit(0);
            }
            $result = $c->downloads()->cancel($id);
            pcntl_waitpid($pid, $status);
            $this->assertSame('cancelled', $result['status']);
        } else {
            $this->markTestSkipped('pcntl_fork unavailable on this platform');
        }
    }

    // ------------------------------------------------------------ file endpoint authorisation

    public function testFileForRequiresCompletedJobWithExistingFile(): void
    {
        $c = $this->container();
        $job = $c->downloads()->create(['url' => self::URL, 'format' => 'mp3'], null);
        $id = $job['job_id'];

        $expect = function (string $code) use ($c, $id): void {
            try {
                $c->downloads()->fileFor($id);
                $this->fail("expected $code");
            } catch (ApiException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        };

        $expect('JOB_NOT_READY'); // queued
        $c->jobs()->patch($id, ['status' => 'downloading']);
        $expect('JOB_NOT_READY');
        $c->jobs()->patch($id, ['status' => 'completed', 'filename' => 'Song.mp3']);
        $expect('FILE_NOT_FOUND'); // completed but file missing

        mkdir($this->storage . '/downloads/' . $id, 0775, true);
        file_put_contents($this->storage . '/downloads/' . $id . '/Song.mp3', 'ID3');
        $file = $c->downloads()->fileFor($id);
        $this->assertSame('audio/mpeg', $file['type']);

        $c->jobs()->patch($id, ['status' => 'cancelled']);
        $expect('JOB_CANCELLED');
    }

    public function testFileForNeverFollowsTamperedMetadataOutOfTheJobDirectory(): void
    {
        $c = $this->container();
        $job = $c->downloads()->create(['url' => self::URL, 'format' => 'mp4'], null);
        file_put_contents($this->storage . '/secret.mp4', 'top secret');
        $c->jobs()->patch($job['job_id'], ['status' => 'completed', 'filename' => '../../secret.mp4']);
        $this->expectException(ApiException::class);
        $c->downloads()->fileFor($job['job_id']);
    }
}
