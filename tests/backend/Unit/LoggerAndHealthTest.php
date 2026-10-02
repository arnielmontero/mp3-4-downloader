<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ApiException;
use App\Support\Logger;
use Tests\Support\TestCase;

final class LoggerAndHealthTest extends TestCase
{
    public function testLogLinesAreStructuredJson(): void
    {
        $logger = new Logger($this->storage . '/logs', $this->storage);
        $logger->info('job started', ['job_id' => 'abc', 'op' => 'download', 'status' => 'started', 'duration_ms' => 12, 'error_code' => null]);
        $file = $this->storage . '/logs/app-' . gmdate('Y-m-d') . '.log';
        $this->assertFileExists($file);
        $entry = json_decode(trim((string) file_get_contents($file)), true);
        foreach (['ts', 'level', 'job_id', 'op', 'status', 'duration_ms', 'error_code', 'message'] as $key) {
            $this->assertArrayHasKey($key, $entry);
        }
        $this->assertSame('abc', $entry['job_id']);
        $this->assertSame('info', $entry['level']);
    }

    public function testSecretsAndPathsAreRedacted(): void
    {
        $logger = new Logger($this->storage . '/logs', $this->storage);
        $dirty = "ERROR at {$this->storage}/temp/x Cookie: SID=abc123 token=SECRETTOKEN Authorization: Bearer abcdef "
            . 'https://host/v?key=APIKEY123&signature=SIG456 password=hunter2' . "\r\ninjected line\x00";
        $clean = $logger->sanitize($dirty);
        foreach (['abc123', 'SECRETTOKEN', 'abcdef', 'APIKEY123', 'SIG456', 'hunter2'] as $secret) {
            $this->assertStringNotContainsString($secret, $clean);
        }
        $this->assertStringNotContainsString($this->storage, $clean);
        $this->assertStringContainsString('[storage]', $clean);
        $this->assertStringNotContainsString("\n", $clean);
        $this->assertStringNotContainsString("\0", $clean);
    }

    public function testLongMessagesAreTruncated(): void
    {
        $logger = new Logger($this->storage . '/logs');
        $this->assertLessThanOrEqual(504, mb_strlen($logger->sanitize(str_repeat('a', 5000))));
    }

    public function testWorkerStatusFromHeartbeat(): void
    {
        $health = $this->container()->health();
        $this->assertFalse($health->workerStatus()['alive'], 'no heartbeat yet');

        $file = $this->storage . '/temp/_worker.json';
        file_put_contents($file, json_encode(['ts' => time(), 'alive' => true, 'active' => 1, 'max' => 3]));
        $status = $health->workerStatus();
        $this->assertTrue($status['alive']);
        $this->assertSame(1, $status['active']);

        file_put_contents($file, json_encode(['ts' => time() - 60, 'alive' => true, 'active' => 0, 'max' => 3]));
        $this->assertFalse($health->workerStatus()['alive'], 'stale heartbeat means the worker is down');

        file_put_contents($file, json_encode(['ts' => time(), 'alive' => false, 'active' => 0, 'max' => 3]));
        $this->assertFalse($health->workerStatus()['alive'], 'worker announced shutdown');
    }

    public function testApiExceptionEnvelope(): void
    {
        $e = new ApiException('RATE_LIMITED', 'Slow down', null, ['retry_after' => 9]);
        $this->assertSame(429, $e->httpStatus());
        $this->assertSame(['code' => 'RATE_LIMITED', 'message' => 'Slow down', 'retry_after' => 9], $e->toArray());
        $this->assertSame(404, (new ApiException('JOB_NOT_FOUND', 'x'))->httpStatus());
        $this->assertSame(500, (new ApiException('SOMETHING_NEW', 'x'))->httpStatus());
    }

    public function testConfigDefaultsMatchTheSpecification(): void
    {
        $c = $this->config();
        $this->assertSame(3, $c->maxConcurrentDownloads);
        $this->assertSame(10 * 1024 ** 3, $c->maxDownloadSizeBytes);
        $this->assertSame(14400, $c->maxVideoDurationSeconds);
        $this->assertSame(86400, $c->retentionSeconds);
        $this->assertSame(20, $c->rateLimitAnalyze);
        $this->assertSame(5, $c->rateLimitDownload);
        $this->assertSame(0, $c->completedRetentionSeconds);
    }

    public function testNonsenseConfigFallsBackToSafeValues(): void
    {
        $c = $this->config(['MAX_CONCURRENT_DOWNLOADS' => 'lots', 'RATE_LIMIT_ANALYZE' => '-5', 'MAX_DOWNLOAD_SIZE_GB' => '', 'HISTORY_SCOPE' => 'whatever']);
        $this->assertSame(3, $c->maxConcurrentDownloads);
        $this->assertSame(1, $c->rateLimitAnalyze);
        $this->assertSame(10 * 1024 ** 3, $c->maxDownloadSizeBytes);
        $this->assertSame('client', $c->historyScope);
    }
}
