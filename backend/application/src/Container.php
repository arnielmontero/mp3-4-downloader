<?php

declare(strict_types=1);

namespace App;

use App\Config\AppConfig;
use App\Media\MediaDownloader;
use App\Media\YtDlpDownloader;
use App\Services\CleanupService;
use App\Services\DownloadService;
use App\Services\FileService;
use App\Services\HealthService;
use App\Services\HistoryService;
use App\Services\JobService;
use App\Services\ProcessService;
use App\Services\RateLimitService;
use App\Services\SecurityService;
use App\Services\VideoInfoService;
use App\Support\Logger;
use App\Worker\Worker;

/**
 * Tiny hand-rolled service container: wires the constructor dependencies in one place so
 * controllers, the worker CLI and the tests all build the same object graph.
 */
final class Container
{
    /** @var array<string,object> */
    private array $instances = [];

    public function __construct(private AppConfig $config, private ?MediaDownloader $media = null)
    {
    }

    public static function fromEnvironment(): self
    {
        return new self(AppConfig::fromEnvironment());
    }

    public function config(): AppConfig
    {
        return $this->config;
    }

    public function logger(): Logger
    {
        return $this->instances[Logger::class] ??= new Logger($this->config->path('logs'), $this->config->storagePath);
    }

    public function security(): SecurityService
    {
        return $this->instances[SecurityService::class] ??= new SecurityService();
    }

    public function files(): FileService
    {
        return $this->instances[FileService::class] ??= new FileService($this->config);
    }

    public function jobs(): JobService
    {
        return $this->instances[JobService::class] ??= new JobService($this->config, $this->security());
    }

    public function processes(): ProcessService
    {
        return $this->instances[ProcessService::class] ??= new ProcessService();
    }

    public function rateLimit(): RateLimitService
    {
        return $this->instances[RateLimitService::class] ??= new RateLimitService($this->config);
    }

    public function media(): MediaDownloader
    {
        return $this->media ??= new YtDlpDownloader($this->config, $this->processes(), $this->security());
    }

    public function videoInfo(): VideoInfoService
    {
        return $this->instances[VideoInfoService::class] ??= new VideoInfoService($this->config, $this->security(), $this->media(), $this->logger());
    }

    public function downloads(): DownloadService
    {
        return $this->instances[DownloadService::class] ??= new DownloadService(
            $this->config,
            $this->security(),
            $this->jobs(),
            $this->files(),
            $this->videoInfo(),
            $this->logger(),
        );
    }

    public function history(): HistoryService
    {
        return $this->instances[HistoryService::class] ??= new HistoryService($this->config, $this->jobs(), $this->files(), $this->logger());
    }

    public function cleanup(): CleanupService
    {
        return $this->instances[CleanupService::class] ??= new CleanupService(
            $this->config,
            $this->jobs(),
            $this->files(),
            $this->rateLimit(),
            $this->videoInfo(),
            $this->logger(),
        );
    }

    public function health(): HealthService
    {
        return $this->instances[HealthService::class] ??= new HealthService($this->config, $this->media());
    }

    public function worker(): Worker
    {
        return new Worker($this->config, $this->jobs(), $this->files(), $this->media(), $this->cleanup(), $this->logger());
    }
}
