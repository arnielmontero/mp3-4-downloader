<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Config\AppConfig;
use App\Container;
use PHPUnit\Framework\TestCase as BaseTestCase;

/** Gives every test its own throw-away storage directory and a container wired to it. */
abstract class TestCase extends BaseTestCase
{
    protected string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ytd-test-' . bin2hex(random_bytes(6));
        foreach (['downloads', 'temp', 'jobs', 'logs'] as $dir) {
            mkdir($this->storage . DIRECTORY_SEPARATOR . $dir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->storage);
        parent::tearDown();
    }

    /** @param array<string,string|int|bool> $env */
    protected function config(array $env = []): AppConfig
    {
        return new AppConfig($env + ['STORAGE_PATH' => $this->storage]);
    }

    /** @param array<string,string|int|bool> $env */
    protected function container(array $env = []): Container
    {
        return new Container($this->config($env));
    }

    protected static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) && !is_link($path) ? self::rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    protected function validId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
