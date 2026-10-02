<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ApiException;
use Tests\Support\TestCase;

final class RateLimitServiceTest extends TestCase
{
    public function testAllowsUpToLimitThenRejectsWithRetryAfter(): void
    {
        $rl = $this->container(['RATE_LIMIT_WINDOW_SECONDS' => 60])->rateLimit();
        for ($i = 0; $i < 5; $i++) {
            $rl->hit('download', '203.0.113.7', 5, 1000 + $i);
        }
        try {
            $rl->hit('download', '203.0.113.7', 5, 1010);
            $this->fail('6th request should be limited');
        } catch (ApiException $e) {
            $this->assertSame('RATE_LIMITED', $e->errorCode());
            $this->assertSame(429, $e->httpStatus());
            $this->assertSame(50, $e->extra()['retry_after']); // oldest hit at t=1000, window 60 s
        }
    }

    public function testWindowSlides(): void
    {
        $rl = $this->container(['RATE_LIMIT_WINDOW_SECONDS' => 60])->rateLimit();
        for ($i = 0; $i < 3; $i++) {
            $rl->hit('analyze', '203.0.113.7', 3, 1000);
        }
        $rl->hit('analyze', '203.0.113.7', 3, 1061); // all earlier hits expired
        $this->addToAssertionCount(1);
    }

    public function testBucketsAndClientsAreIndependent(): void
    {
        $rl = $this->container()->rateLimit();
        $rl->hit('analyze', '203.0.113.7', 1, 1000);
        $rl->hit('download', '203.0.113.7', 1, 1000); // other bucket
        $rl->hit('analyze', '203.0.113.8', 1, 1000); // other client
        $this->expectException(ApiException::class);
        $rl->hit('analyze', '203.0.113.7', 1, 1001);
    }

    public function testLimitsAreConfigurableViaEnvironment(): void
    {
        $config = $this->config(['RATE_LIMIT_ANALYZE' => '7', 'RATE_LIMIT_DOWNLOAD' => '2', 'RATE_LIMIT_WINDOW_SECONDS' => '30']);
        $this->assertSame(7, $config->rateLimitAnalyze);
        $this->assertSame(2, $config->rateLimitDownload);
        $this->assertSame(30, $config->rateLimitWindowSeconds);
        $defaults = $this->config();
        $this->assertSame(20, $defaults->rateLimitAnalyze);
        $this->assertSame(5, $defaults->rateLimitDownload);
    }

    public function testPurgeRemovesStaleFiles(): void
    {
        $rl = $this->container(['RATE_LIMIT_WINDOW_SECONDS' => 1])->rateLimit();
        $rl->hit('analyze', '203.0.113.7', 5);
        $files = glob($this->storage . '/temp/_ratelimit/*.json');
        $this->assertCount(1, $files);
        touch($files[0], time() - 100);
        $this->assertSame(1, $rl->purge());
    }
}
