<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ApiException;

/**
 * Input validation: URLs (with SSRF protection), formats, qualities, bitrates and job ids.
 *
 * Only an explicit allow-list of YouTube hosts is accepted, so localhost, private addresses,
 * internal hostnames and non-http(s) schemes can never reach yt-dlp.
 */
final class SecurityService
{
    public const FORMATS = ['mp4', 'mp3'];
    public const QUALITIES = ['best', '144p', '240p', '360p', '480p', '720p', '1080p', '1440p', '2160p', '4320p'];
    public const BITRATES = [128, 192, 256, 320];
    public const DEFAULT_BITRATE = 192;

    private const ALLOWED_HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'music.youtube.com',
        'youtu.be',
        'www.youtu.be',
        'youtube-nocookie.com',
        'www.youtube-nocookie.com',
    ];

    private const MAX_URL_LENGTH = 2048;
    private const VIDEO_ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    /**
     * Validate a user supplied URL and return the canonical https://www.youtube.com/watch?v=ID form.
     */
    public function normalizeUrl(mixed $input): string
    {
        return 'https://www.youtube.com/watch?v=' . $this->extractVideoId($input);
    }

    public function extractVideoId(mixed $input): string
    {
        if (!is_string($input) || trim($input) === '') {
            throw new ApiException('INVALID_URL', 'Please enter a valid YouTube URL.');
        }
        $url = trim($input);
        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw new ApiException('INVALID_URL', 'The supplied URL is too long.');
        }
        // No whitespace / control characters / shell metacharacters anywhere in the raw string.
        if (preg_match('/[\x00-\x20\x7F"\'`<>\\\\^{}|;$]/', $url) === 1) {
            throw new ApiException('INVALID_URL', 'The supplied URL contains invalid characters.');
        }

        if (!str_contains($url, '://')) {
            if (str_starts_with($url, '//')) {
                $url = 'https:' . $url;
            } elseif (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1 && !preg_match('/^[^\/?#]+:\d+(\/|$)/', $url)) {
                // file:, data:, javascript:, ftp: ... (scheme without "//")
                throw new ApiException('INVALID_URL', 'Only http(s) YouTube URLs are supported.');
            } else {
                $url = 'https://' . $url;
            }
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ApiException('INVALID_URL', 'Please enter a valid YouTube URL.');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new ApiException('INVALID_URL', 'Only http(s) YouTube URLs are supported.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ApiException('INVALID_URL', 'URLs with credentials are not supported.');
        }
        if (isset($parts['port']) && !in_array($parts['port'], [80, 443], true)) {
            throw new ApiException('UNSUPPORTED_DOMAIN', 'Only YouTube URLs are supported.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            // localhost, 127.0.0.1, 0.0.0.0, private ranges, internal names, lookalike domains...
            throw new ApiException('UNSUPPORTED_DOMAIN', 'Only YouTube URLs are supported.');
        }

        $path = $parts['path'] ?? '';
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $id = null;
        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            $id = ltrim($path, '/');
            $id = explode('/', $id)[0];
        } elseif ($path === '/watch' || $path === '/watch/') {
            $id = isset($query['v']) && is_string($query['v']) ? $query['v'] : null;
        } elseif (preg_match('~^/(?:shorts|live|embed|v)/([^/?#]+)/?$~', $path, $m) === 1) {
            $id = $m[1];
        } elseif (str_starts_with($path, '/playlist') || str_starts_with($path, '/@') || str_starts_with($path, '/channel/') || str_starts_with($path, '/c/') || str_starts_with($path, '/user/')) {
            throw new ApiException('INVALID_URL', 'Only single video URLs are supported (no playlists or channels).');
        }

        if ($id === null || preg_match(self::VIDEO_ID_PATTERN, $id) !== 1) {
            throw new ApiException('INVALID_URL', 'The supplied URL is not a supported YouTube video URL.');
        }
        return $id;
    }

    public function validateFormat(mixed $format): string
    {
        $format = is_string($format) ? strtolower(trim($format)) : '';
        if (!in_array($format, self::FORMATS, true)) {
            throw new ApiException('INVALID_FORMAT', 'Format must be "mp4" or "mp3".');
        }
        return $format;
    }

    public function validateQuality(mixed $quality): string
    {
        if ($quality === null || $quality === '') {
            return 'best';
        }
        $quality = is_string($quality) ? strtolower(trim($quality)) : '';
        if (!in_array($quality, self::QUALITIES, true)) {
            throw new ApiException('INVALID_QUALITY', 'The selected quality is not valid.');
        }
        return $quality;
    }

    public function validateBitrate(mixed $bitrate): int
    {
        if ($bitrate === null || $bitrate === '') {
            return self::DEFAULT_BITRATE;
        }
        if (is_string($bitrate) && preg_match('/^\d{2,3}$/', $bitrate) === 1) {
            $bitrate = (int) $bitrate;
        }
        if (!is_int($bitrate) || !in_array($bitrate, self::BITRATES, true)) {
            throw new ApiException('INVALID_BITRATE', 'Audio bitrate must be one of 128, 192, 256 or 320 kbps.');
        }
        return $bitrate;
    }

    public function validateJobId(mixed $id): string
    {
        if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new ApiException('INVALID_JOB_ID', 'The job id is not valid.');
        }
        return $id;
    }

    public static function isValidJobId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $id) === 1;
    }

    public function newJobId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Only accept thumbnail URLs served by YouTube's image CDNs; anything else is dropped.
     */
    public function safeThumbnail(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if ($host === 'i.ytimg.com' || str_ends_with($host, '.ytimg.com') || str_ends_with($host, '.ggpht.com')) {
            return $url;
        }
        return null;
    }
}
