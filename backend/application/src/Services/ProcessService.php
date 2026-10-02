<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Starts and stops child processes. Commands are always argument arrays (never shell strings),
 * and each child is placed in its own process group (Linux: setsid, Windows: taskkill /T) so that
 * cancelling a job kills yt-dlp *and* the FFmpeg it spawned - and nothing else.
 */
final class ProcessService
{
    private const MAX_PARTIAL_LINE = 1048576;
    private const STDERR_TAIL = 8192;

    /** Environment variables handed down to children (everything else, e.g. app secrets, is dropped). */
    private const PASSTHROUGH_ENV = ['PATH', 'SystemRoot', 'SYSTEMROOT', 'TEMP', 'TMP', 'HOME', 'USERPROFILE', 'LANG', 'LC_ALL', 'APPDATA', 'LOCALAPPDATA', 'XDG_CACHE_HOME'];

    /**
     * @param list<string> $command
     * @param array<string,string> $extraEnv
     */
    public function start(array $command, ?string $cwd = null, array $extraEnv = []): ManagedProcess
    {
        if (PHP_OS_FAMILY !== 'Windows' && self::setsidPath() !== null) {
            array_unshift($command, self::setsidPath());
        }
        $env = [];
        foreach (self::PASSTHROUGH_ENV as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }
        $env = $extraEnv + $env + ['PYTHONUNBUFFERED' => '1', 'PYTHONIOENCODING' => 'utf-8'];

        $pipes = [];
        $proc = @proc_open(
            $command,
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $env,
            ['bypass_shell' => true]
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException('Unable to start process');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $status = proc_get_status($proc);
        return new ManagedProcess($proc, $pipes[1], $pipes[2], (int) $status['pid']);
    }

    /** Run a command to completion (bounded by $timeout seconds) and capture stdout. */
    public function run(array $command, int $timeout, ?string $cwd = null): array
    {
        $p = $this->start($command, $cwd);
        $deadline = microtime(true) + $timeout;
        $out = '';
        while (true) {
            $lines = $p->readStdout();
            foreach ($lines as $l) {
                $out .= $l . "\n";
            }
            if (!$p->isRunning()) {
                foreach ($p->readStdout(true) as $l) {
                    $out .= $l . "\n";
                }
                break;
            }
            if (microtime(true) > $deadline) {
                $this->kill($p);
                return ['exit' => -1, 'stdout' => $out, 'stderr' => $p->stderrTail(), 'timeout' => true];
            }
            usleep(50000);
        }
        $exit = $p->exitCode();
        $p->close();
        return ['exit' => $exit, 'stdout' => $out, 'stderr' => $p->stderrTail(), 'timeout' => false];
    }

    /** Send SIGTERM (or taskkill) to the process group; does not wait. */
    public function terminate(ManagedProcess $p): void
    {
        $this->signal($p, false);
    }

    /** Force-kill the whole process group and release the handle. */
    public function kill(ManagedProcess $p): void
    {
        $this->signal($p, true);
        $p->close();
    }

    private function signal(ManagedProcess $p, bool $force): void
    {
        $pid = $p->pid();
        if ($pid <= 0) {
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('taskkill /T ' . ($force ? '/F ' : '') . '/PID ' . $pid . ' 2>NUL');
            if (!$force) {
                // taskkill without /F cannot stop console programs; escalate straight away.
                @exec('taskkill /T /F /PID ' . $pid . ' 2>NUL');
            }
            return;
        }
        $sig = $force ? 9 : 15;
        if (function_exists('posix_kill')) {
            // Negative pid = the whole group created by setsid (the group id equals the child's pid).
            if (self::setsidPath() !== null) {
                @posix_kill(-$pid, $sig);
            }
            @posix_kill($pid, $sig);
        } else {
            $args = [$force ? '-KILL' : '-TERM', '--', self::setsidPath() !== null ? '-' . $pid : (string) $pid];
            $k = @proc_open(array_merge(['kill'], $args), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
            if (is_resource($k)) {
                proc_close($k);
            }
        }
    }

    private static ?string $setsid = null;
    private static bool $setsidChecked = false;

    private static function setsidPath(): ?string
    {
        if (!self::$setsidChecked) {
            self::$setsidChecked = true;
            foreach (['/usr/bin/setsid', '/bin/setsid', '/usr/local/bin/setsid'] as $candidate) {
                if (is_executable($candidate)) {
                    self::$setsid = $candidate;
                    break;
                }
            }
        }
        return self::$setsid;
    }
}
