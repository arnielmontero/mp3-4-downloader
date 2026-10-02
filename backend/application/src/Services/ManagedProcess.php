<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Handle for one running child process. Output is read non-blockingly and split into lines so
 * huge outputs are streamed, never held in memory (stderr keeps only a bounded tail).
 */
final class ManagedProcess
{
    private const MAX_PARTIAL_LINE = 1048576;
    private const STDERR_TAIL = 8192;

    private string $outBuf = '';
    private string $errBuf = '';
    private string $stderrTail = '';
    private ?int $exitCode = null;
    private bool $closed = false;
    public ?float $terminateRequestedAt = null;

    /** @param resource $proc @param resource $stdout @param resource $stderr */
    public function __construct(private $proc, private $stdout, private $stderr, private int $pid)
    {
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function isRunning(): bool
    {
        if ($this->closed) {
            return false;
        }
        $status = proc_get_status($this->proc);
        if (!$status['running']) {
            if ($this->exitCode === null && $status['exitcode'] >= 0) {
                $this->exitCode = $status['exitcode'];
            }
            return false;
        }
        return true;
    }

    public function exitCode(): int
    {
        if ($this->exitCode === null) {
            $this->isRunning();
        }
        return $this->exitCode ?? -1;
    }

    /** @return list<string> complete stdout lines that arrived since the last call */
    public function readStdout(bool $flush = false): array
    {
        return $this->drain($this->stdout, $this->outBuf, false, $flush);
    }

    /** @return list<string> */
    public function readStderr(bool $flush = false): array
    {
        return $this->drain($this->stderr, $this->errBuf, true, $flush);
    }

    public function stderrTail(): string
    {
        return $this->stderrTail;
    }

    /**
     * @param resource $stream
     * @return list<string>
     */
    private function drain($stream, string &$buffer, bool $isStderr, bool $flush): array
    {
        if ($this->closed || !is_resource($stream)) {
            return [];
        }
        for ($i = 0; $i < 64; $i++) {
            $chunk = fread($stream, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
        }
        $lines = [];
        // yt-dlp progress uses \r in some modes; treat \r like \n.
        while (($pos = strcspn($buffer, "\r\n")) < strlen($buffer)) {
            $line = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 1);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        if ($flush && $buffer !== '') {
            $lines[] = $buffer;
            $buffer = '';
        } elseif (strlen($buffer) > self::MAX_PARTIAL_LINE) {
            $buffer = '';
        }
        if ($isStderr && $lines) {
            $this->stderrTail = substr($this->stderrTail . implode("\n", $lines) . "\n", -self::STDERR_TAIL);
        }
        return $lines;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->exitCode === null) {
            $status = @proc_get_status($this->proc);
            if (is_array($status) && !$status['running'] && $status['exitcode'] >= 0) {
                $this->exitCode = $status['exitcode'];
            }
        }
        foreach ([$this->stdout, $this->stderr] as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (is_resource($this->proc)) {
            $code = @proc_close($this->proc);
            if ($this->exitCode === null && $code >= 0) {
                $this->exitCode = $code;
            }
        }
    }

    public function __destruct()
    {
        // Never leave pipes dangling; the process itself is stopped explicitly by ProcessService.
        if (!$this->closed) {
            foreach ([$this->stdout, $this->stderr] as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
        }
    }
}
