<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\JobService;
use App\Support\ApiException;
use Tests\Support\TestCase;

final class HistoryAndCleanupTest extends TestCase
{
    /** @return array<string,mixed> */
    private function job(\App\Container $c, string $status, ?string $client = 'c1', ?int $finishedAgo = null, string $format = 'mp4'): array
    {
        $job = $c->jobs()->create(['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'video_id' => 'dQw4w9WgXcQ', 'format' => $format, 'client_id' => $client]);
        $changes = ['status' => $status];
        if ($finishedAgo !== null) {
            $changes['finished_at'] = gmdate('c', time() - $finishedAgo);
        }
        return $c->jobs()->patch($job['id'], $changes);
    }

    private function storeFile(string $id, string $name, string $content = 'data'): void
    {
        mkdir($this->storage . '/downloads/' . $id, 0775, true);
        file_put_contents($this->storage . '/downloads/' . $id . '/' . $name, $content);
    }

    // ------------------------------------------------------------ history

    public function testHistoryIsScopedToTheClient(): void
    {
        $c = $this->container();
        $mine = $this->job($c, 'completed', 'me');
        $this->job($c, 'completed', 'someone-else');
        $items = $c->history()->list('me');
        $this->assertCount(1, $items);
        $this->assertSame($mine['id'], $items[0]['job_id']);
        $this->assertSame([], $c->history()->list(null));
        $this->assertSame([], $c->history()->list(''));
    }

    public function testGlobalHistoryScopeShowsEverything(): void
    {
        $c = $this->container(['HISTORY_SCOPE' => 'global']);
        $this->job($c, 'completed', 'a');
        $this->job($c, 'failed', 'b');
        $this->assertCount(2, $c->history()->list(null));
    }

    public function testHistoryEntriesReportFileAvailabilityWithoutPaths(): void
    {
        $c = $this->container();
        $job = $this->job($c, 'completed', 'me');
        $c->jobs()->patch($job['id'], ['filename' => 'Song.mp4', 'filesize' => 4, 'title' => 'Song']);
        $this->assertFalse($c->history()->list('me')[0]['file_available']);
        $this->storeFile($job['id'], 'Song.mp4');
        $item = $c->history()->list('me')[0];
        $this->assertTrue($item['file_available']);
        $this->assertSame('Song.mp4', $item['filename']);
        $this->assertStringNotContainsString($this->storage, (string) json_encode($item));
    }

    public function testRemoveDeletesRecordAndFilesButOnlyForTheOwner(): void
    {
        $c = $this->container();
        $job = $this->job($c, 'completed', 'me');
        $c->jobs()->patch($job['id'], ['filename' => 'a.mp4']);
        $this->storeFile($job['id'], 'a.mp4');

        try {
            $c->history()->remove($job['id'], 'intruder');
            $this->fail('foreign delete accepted');
        } catch (ApiException $e) {
            $this->assertSame('JOB_NOT_FOUND', $e->errorCode());
        }
        $this->assertDirectoryExists($this->storage . '/downloads/' . $job['id']);

        $c->history()->remove($job['id'], 'me');
        $this->assertNull($c->jobs()->find($job['id']));
        $this->assertDirectoryDoesNotExist($this->storage . '/downloads/' . $job['id']);
    }

    public function testActiveJobsCannotBeRemovedFromHistory(): void
    {
        $c = $this->container();
        foreach (['queued', 'analyzing', 'downloading', 'processing'] as $status) {
            $job = $this->job($c, $status, 'me');
            try {
                $c->history()->remove($job['id'], 'me');
                $this->fail("removed $status job");
            } catch (ApiException $e) {
                $this->assertSame('JOB_ACTIVE', $e->errorCode());
            }
        }
    }

    // ------------------------------------------------------------ cleanup

    public function testCleanupRemovesTempOfFinishedAndOrphanedJobsButNotActiveOnes(): void
    {
        $c = $this->container();
        $active = $this->job($c, 'downloading');
        $failed = $this->job($c, 'failed');
        $orphan = $this->validId();
        foreach ([$active['id'], $failed['id'], $orphan] as $id) {
            mkdir($this->storage . '/temp/' . $id, 0775, true);
            file_put_contents($this->storage . '/temp/' . $id . '/part.bin', 'x');
        }
        mkdir($this->storage . '/temp/_ratelimit', 0775, true); // internal dirs are never touched

        $stats = $c->cleanup()->run([$active['id']]);

        $this->assertDirectoryExists($this->storage . '/temp/' . $active['id'], 'protected: worker is using it');
        $this->assertDirectoryDoesNotExist($this->storage . '/temp/' . $failed['id']);
        $this->assertDirectoryDoesNotExist($this->storage . '/temp/' . $orphan);
        $this->assertDirectoryExists($this->storage . '/temp/_ratelimit');
        $this->assertSame(2, $stats['temp_dirs']);
    }

    public function testCleanupRemovesAbandonedActiveTempAfterRetention(): void
    {
        $c = $this->container(['DOWNLOAD_RETENTION_HOURS' => 1]);
        $job = $this->job($c, 'downloading');
        $dir = $this->storage . '/temp/' . $job['id'];
        mkdir($dir, 0775, true);
        touch($dir, time() - 7200);
        $c->cleanup()->run([]);
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testCleanupExpiresFailedAndCancelledMetadataButKeepsCompleted(): void
    {
        $c = $this->container(['DOWNLOAD_RETENTION_HOURS' => 24]);
        $oldFailed = $this->job($c, 'failed', 'c', 25 * 3600);
        $oldCancelled = $this->job($c, 'cancelled', 'c', 30 * 3600);
        $freshFailed = $this->job($c, 'failed', 'c', 3600);
        $oldCompleted = $this->job($c, 'completed', 'c', 90 * 24 * 3600);
        $c->jobs()->patch($oldCompleted['id'], ['filename' => 'keep.mp4']);
        $this->storeFile($oldCompleted['id'], 'keep.mp4');

        $stats = $c->cleanup()->run([]);

        $this->assertNull($c->jobs()->find($oldFailed['id']));
        $this->assertNull($c->jobs()->find($oldCancelled['id']));
        $this->assertNotNull($c->jobs()->find($freshFailed['id']));
        $this->assertNotNull($c->jobs()->find($oldCompleted['id']), 'completed files stay until the user deletes them');
        $this->assertFileExists($this->storage . '/downloads/' . $oldCompleted['id'] . '/keep.mp4');
        $this->assertSame(2, $stats['jobs']);
    }

    public function testOptionalCompletedRetentionPolicyDeletesFilesToo(): void
    {
        $c = $this->container(['COMPLETED_RETENTION_HOURS' => 48]);
        $old = $this->job($c, 'completed', 'c', 72 * 3600);
        $young = $this->job($c, 'completed', 'c', 3600);
        $c->jobs()->patch($old['id'], ['filename' => 'o.mp4']);
        $this->storeFile($old['id'], 'o.mp4');
        $c->cleanup()->run([]);
        $this->assertNull($c->jobs()->find($old['id']));
        $this->assertDirectoryDoesNotExist($this->storage . '/downloads/' . $old['id']);
        $this->assertNotNull($c->jobs()->find($young['id']));
    }

    public function testCleanupRemovesOrphanedDownloadFoldersAndOldLogs(): void
    {
        $c = $this->container(['DOWNLOAD_RETENTION_HOURS' => 1, 'LOG_RETENTION_DAYS' => 7]);
        $orphan = $this->validId();
        mkdir($this->storage . '/downloads/' . $orphan, 0775, true);
        touch($this->storage . '/downloads/' . $orphan, time() - 7200);
        $fresh = $this->validId();
        mkdir($this->storage . '/downloads/' . $fresh, 0775, true); // young: might be mid-move
        file_put_contents($this->storage . '/logs/app-2020-01-01.log', 'old');
        touch($this->storage . '/logs/app-2020-01-01.log', time() - 30 * 86400);
        file_put_contents($this->storage . '/logs/app-today.log', 'new');

        $c->cleanup()->run([]);

        $this->assertDirectoryDoesNotExist($this->storage . '/downloads/' . $orphan);
        $this->assertDirectoryExists($this->storage . '/downloads/' . $fresh);
        $this->assertFileDoesNotExist($this->storage . '/logs/app-2020-01-01.log');
        $this->assertFileExists($this->storage . '/logs/app-today.log');
    }

    // ------------------------------------------------------------ crash recovery

    public function testStaleJobsAreRequeuedOnceThenFailed(): void
    {
        $c = $this->container(['MAX_JOB_ATTEMPTS' => 2]);
        $first = $this->job($c, 'downloading');
        $c->jobs()->patch($first['id'], ['attempts' => 1, 'progress' => 55.5]);
        $exhausted = $this->job($c, 'processing');
        $c->jobs()->patch($exhausted['id'], ['attempts' => 2]);
        $queued = $this->job($c, 'queued');
        $cancelling = $this->job($c, 'downloading');
        $c->jobs()->patch($cancelling['id'], ['cancel_requested' => true]);
        $done = $this->job($c, 'completed');
        mkdir($this->storage . '/temp/' . $first['id'], 0775, true);

        $result = $c->cleanup()->recoverStaleJobs();

        $this->assertSame(1, $result['requeued']);
        $this->assertSame(1, $result['failed']);
        $again = $c->jobs()->get($first['id']);
        $this->assertSame('queued', $again['status']);
        $this->assertEquals(0, $again['progress']);
        $failed = $c->jobs()->get($exhausted['id']);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame('SERVER_ERROR', $failed['error']['code']);
        $this->assertSame('cancelled', $c->jobs()->get($cancelling['id'])['status']);
        $this->assertSame('queued', $c->jobs()->get($queued['id'])['status']);
        $this->assertSame('completed', $c->jobs()->get($done['id'])['status']);
        $this->assertDirectoryDoesNotExist($this->storage . '/temp/' . $first['id']);
    }
}
