<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\JobService;
use App\Support\ApiException;
use Tests\Support\TestCase;

final class JobServiceTest extends TestCase
{
    private JobService $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobs = $this->container()->jobs();
    }

    /** @return array<string,mixed> */
    private function newJob(string $format = 'mp4'): array
    {
        return $this->jobs->create(['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'video_id' => 'dQw4w9WgXcQ', 'format' => $format, 'quality' => '720p', 'client_id' => 'c1']);
    }

    public function testCreateWritesJsonFileWithExpectedShape(): void
    {
        $job = $this->newJob();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $job['id']);
        $this->assertSame('queued', $job['status']);
        $this->assertSame(0.0, $job['progress']);
        $file = $this->storage . '/jobs/' . $job['id'] . '.json';
        $this->assertFileExists($file);
        $stored = json_decode((string) file_get_contents($file), true);
        foreach (['id', 'url', 'format', 'quality', 'status', 'progress', 'created_at', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $stored);
        }
        $this->assertSame('720p', $stored['quality']);
    }

    public function testJobIdsAreUnique(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[$this->newJob()['id']] = true;
        }
        $this->assertCount(50, $ids);
    }

    public function testGetUnknownJobThrowsJobNotFound(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('not found');
        $this->jobs->get($this->validId());
    }

    public function testGetInvalidIdThrowsInvalidJobId(): void
    {
        try {
            $this->jobs->get('../../etc/passwd');
            $this->fail('expected exception');
        } catch (ApiException $e) {
            $this->assertSame('INVALID_JOB_ID', $e->errorCode());
        }
    }

    public function testUpdateIsAtomicReadModifyWrite(): void
    {
        $job = $this->newJob();
        for ($i = 0; $i < 20; $i++) {
            $this->jobs->update($job['id'], static function (array $j): array {
                $j['downloaded_bytes'] += 10;
                return $j;
            });
        }
        $this->assertSame(200, $this->jobs->get($job['id'])['downloaded_bytes']);
        $this->assertNull($this->jobs->update($this->validId(), static fn (array $j): array => $j));
    }

    public function testQueuedJobIsCancelledImmediately(): void
    {
        $job = $this->newJob();
        $after = $this->jobs->requestCancel($job['id']);
        $this->assertSame('cancelled', $after['status']);
        $this->assertTrue($after['cancel_requested']);
        $this->assertNotNull($after['finished_at']);
    }

    public function testActiveJobIsOnlyFlaggedForCancellation(): void
    {
        $job = $this->newJob();
        $this->jobs->patch($job['id'], ['status' => 'downloading']);
        $after = $this->jobs->requestCancel($job['id']);
        $this->assertSame('downloading', $after['status'], 'the worker performs the actual stop');
        $this->assertTrue($after['cancel_requested']);
        $this->assertSame('Cancelling...', $this->jobs->toPublic($after)['message']);
    }

    public function testTerminalJobIsNotChangedByCancel(): void
    {
        $job = $this->newJob();
        $this->jobs->patch($job['id'], ['status' => 'completed', 'filename' => 'x.mp4']);
        $after = $this->jobs->requestCancel($job['id']);
        $this->assertSame('completed', $after['status']);
        $this->assertFalse($after['cancel_requested']);
    }

    public function testQueuedListsOldestFirstAndSkipsCancelled(): void
    {
        $a = $this->newJob();
        usleep(1100000); // created_at has one-second resolution
        $b = $this->newJob();
        $c = $this->newJob();
        $this->jobs->requestCancel($c['id']);
        $queue = array_column($this->jobs->queued(), 'id');
        $this->assertSame([$a['id'], $b['id']], $queue);
    }

    public function testFailStoresErrorAndFinishTime(): void
    {
        $job = $this->newJob();
        $failed = $this->jobs->fail($job['id'], 'VIDEO_TOO_LONG', 'Too long');
        $this->assertSame('failed', $failed['status']);
        $this->assertSame(['code' => 'VIDEO_TOO_LONG', 'message' => 'Too long'], $failed['error']);
        // failing again must not overwrite a terminal state
        $this->jobs->patch($job['id'], ['status' => 'completed']);
        $this->jobs->fail($job['id'], 'X', 'y');
        $this->assertSame('completed', $this->jobs->get($job['id'])['status']);
    }

    public function testPublicViewHidesInternalFields(): void
    {
        $job = $this->newJob('mp3');
        $this->jobs->patch($job['id'], [
            'status' => 'downloading', 'progress' => 47.54, 'speed_bps' => 3355443.2, 'eta_seconds' => 42,
            'downloaded_bytes' => 5242880, 'total_bytes' => 10485760, 'filename' => 'secret.mp3', 'pid' => 1234, 'bitrate' => 192,
        ]);
        $public = $this->jobs->toPublic($this->jobs->get($job['id']));
        $this->assertSame($job['id'], $public['job_id']);
        $this->assertSame('downloading', $public['status']);
        $this->assertSame(47.5, $public['progress']);
        $this->assertSame('3.2MiB/s', $public['speed']);
        $this->assertSame('00:42', $public['eta']);
        $this->assertSame('5.0MiB', $public['downloaded']);
        $this->assertSame('10.0MiB', $public['total']);
        $this->assertSame(192, $public['bitrate']);
        $this->assertNull($public['filename'], 'filename only exposed once completed');
        $json = json_encode($public);
        foreach (['client_id', 'pid', 'cancel_requested', 'url', $this->storage] as $secret) {
            $this->assertStringNotContainsString((string) $secret, (string) $json);
        }
    }

    public function testFormatters(): void
    {
        $this->assertSame('0B', JobService::formatBytes(0));
        $this->assertSame('1.5KiB', JobService::formatBytes(1536));
        $this->assertSame('2.0GiB', JobService::formatBytes(2 * 1024 ** 3));
        $this->assertSame('00:05', JobService::formatDuration(5));
        $this->assertSame('01:05', JobService::formatDuration(65));
        $this->assertSame('1:01:05', JobService::formatDuration(3665));
        $this->assertSame('00:00', JobService::formatDuration(-4));
    }
}
