<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use App\Support\ApiException;

/**
 * Everything that touches the filesystem on behalf of a job: safe paths, filename sanitising,
 * unique names, recursive deletion and controlled file lookup for the download endpoint.
 */
final class FileService
{
    private const RESERVED = ['CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'];
    private const MAX_BASENAME_BYTES = 150;
    private const CONTENT_TYPES = ['mp4' => 'video/mp4', 'mp3' => 'audio/mpeg'];

    public function __construct(private AppConfig $config)
    {
    }

    public function ensureStorage(): void
    {
        foreach (['downloads', 'temp', 'jobs', 'logs'] as $dir) {
            $path = $this->config->path($dir);
            if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
                throw new \RuntimeException('Cannot create storage directory: ' . $dir);
            }
        }
    }

    /** Make a title safe to use as a file name (without extension). */
    public static function sanitizeFilename(string $name, string $fallback = 'download'): string
    {
        // Control characters and invisible format characters (e.g. right-to-left override) are dropped.
        $name = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name) ?? '';
        // Path separators and drive colons become " - " so words stay separated. Segments made only of
        // dots ("..", ".") are traversal remnants and are dropped.
        $segments = preg_split('/[\/\\\\:]+/u', $name) ?: [$name];
        $segments = array_filter($segments, static fn (string $s): bool => trim($s, " .\t") !== '');
        $name = implode(' - ', array_map('trim', $segments));
        // Remaining characters that are illegal on Windows.
        $name = preg_replace('/[*?"<>|]/u', '', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = preg_replace('/^(\s*-\s+)+|(\s+-\s*)+$/u', '', $name) ?? '';
        $name = trim($name, " .\t");
        // ".." style names can never survive the trim above, but be explicit.
        if ($name === '' || preg_match('/^\.+$/', $name) === 1) {
            $name = $fallback;
        }
        // Windows reserved device names (with or without an extension).
        $stem = strtoupper(explode('.', $name)[0]);
        if (in_array(rtrim($stem), self::RESERVED, true)) {
            $name = '_' . $name;
        }
        // Limit length in bytes without breaking a multibyte character.
        while (strlen($name) > self::MAX_BASENAME_BYTES) {
            $name = mb_substr($name, 0, mb_strlen($name) - 1);
        }
        $name = rtrim($name, " .");
        return $name === '' ? $fallback : $name;
    }

    /** Return "base.ext", "base (1).ext", "base (2).ext", ... whichever does not exist yet. */
    public static function uniqueFilename(string $dir, string $base, string $ext): string
    {
        $candidate = $base . '.' . $ext;
        $i = 1;
        while (file_exists($dir . DIRECTORY_SEPARATOR . $candidate)) {
            $candidate = sprintf('%s (%d).%s', $base, $i++, $ext);
        }
        return $candidate;
    }

    public function jobTempDir(string $jobId): string
    {
        $this->assertJobId($jobId);
        return $this->config->path('temp', $jobId);
    }

    public function jobDownloadDir(string $jobId): string
    {
        $this->assertJobId($jobId);
        return $this->config->path('downloads', $jobId);
    }

    public function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create directory');
        }
    }

    /**
     * Resolve the finished file of a job, verifying that it is a plain file that really lives in
     * that job's own download directory. Returns null when anything does not check out.
     *
     * @param array<string,mixed> $job
     * @return array{path:string,filename:string,size:int,type:string,ext:string}|null
     */
    public function resolveJobFile(array $job): ?array
    {
        $id = (string) ($job['id'] ?? '');
        $filename = (string) ($job['filename'] ?? '');
        if (!SecurityService::isValidJobId($id) || $filename === '' || $filename !== basename($filename)
            || str_contains($filename, "\0") || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return null;
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!isset(self::CONTENT_TYPES[$ext]) || $ext !== ($job['format'] ?? null)) {
            return null;
        }
        $dir = realpath($this->jobDownloadDir($id));
        if ($dir === false) {
            return null;
        }
        $path = realpath($dir . DIRECTORY_SEPARATOR . $filename);
        if ($path === false || dirname($path) !== $dir || !is_file($path) || is_link($path)) {
            return null;
        }
        $size = filesize($path);
        return [
            'path' => $path,
            'filename' => $filename,
            'size' => $size === false ? 0 : $size,
            'type' => self::CONTENT_TYPES[$ext],
            'ext' => $ext,
        ];
    }

    public static function contentDisposition(string $filename): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'download';
        $ascii = str_replace(['"', '\\', '%'], '_', $ascii);
        return sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($filename));
    }

    /** Recursively delete a directory without following symlinks. Silent when it does not exist. */
    public static function removeDir(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /** Total size in bytes of all regular files below $dir. */
    public static function dirSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $total = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $total += $file->getSize();
            }
        }
        return $total;
    }

    /** Newest file with the given extension directly inside $dir, or null. */
    public static function findOutputFile(string $dir, string $ext): ?string
    {
        $best = null;
        $bestTime = -1;
        foreach (@scandir($dir) ?: [] as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_file($path) && strtolower(pathinfo($item, PATHINFO_EXTENSION)) === $ext && !str_ends_with($item, '.part')) {
                $mtime = (int) filemtime($path);
                if ($mtime >= $bestTime) {
                    $best = $path;
                    $bestTime = $mtime;
                }
            }
        }
        return $best;
    }

    private function assertJobId(string $jobId): void
    {
        if (!SecurityService::isValidJobId($jobId)) {
            throw new ApiException('INVALID_JOB_ID', 'The job id is not valid.');
        }
    }
}
