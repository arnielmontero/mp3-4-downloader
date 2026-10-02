<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Structured JSON-lines logger (one file per day under storage/logs).
 *
 * Messages are sanitised before writing: control characters are stripped, secrets/cookies/tokens
 * are redacted, the storage path is masked and long values are truncated.
 */
final class Logger
{
    private const MAX_MESSAGE = 500;

    public function __construct(private string $logDir, private string $storagePath = '')
    {
    }

    /** @param array<string,mixed> $context job_id, op, status, duration_ms, error_code */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function sanitize(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';
        $text = preg_replace('/\r?\n/', ' | ', $text) ?? '';
        if ($this->storagePath !== '') {
            $text = str_replace($this->storagePath, '[storage]', $text);
        }
        // Redact anything that looks like a credential.
        $text = preg_replace(
            '/(cookie|set-cookie|authorization|bearer|token|passwd|password|secret|api[_-]?key|po_token)(\W*[:=]\W*|\s+)(?:(?:bearer|basic)\s+)?[^\s,;|]+/i',
            '$1=[redacted]',
            $text
        ) ?? '';
        $text = preg_replace('/([?&](?:key|token|signature|sig|sparams|cookie|auth)[^=&\s]*=)[^&\s]+/i', '$1[redacted]', $text) ?? '';
        if (mb_strlen($text) > self::MAX_MESSAGE) {
            $text = mb_substr($text, 0, self::MAX_MESSAGE) . '...';
        }
        return $text;
    }

    /** @param array<string,mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $entry = [
            'ts' => gmdate('c'),
            'level' => $level,
            'job_id' => $context['job_id'] ?? null,
            'op' => $context['op'] ?? null,
            'status' => $context['status'] ?? null,
            'duration_ms' => $context['duration_ms'] ?? null,
            'error_code' => $context['error_code'] ?? null,
            'message' => $this->sanitize($message),
        ];
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            return;
        }
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0775, true);
        }
        @file_put_contents($this->logDir . DIRECTORY_SEPARATOR . 'app-' . gmdate('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
