<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SecurityService;
use App\Support\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityServiceTest extends TestCase
{
    private SecurityService $sec;

    protected function setUp(): void
    {
        $this->sec = new SecurityService();
    }

    // ------------------------------------------------------------ URL validation + normalization

    /** @return array<string,array{string}> */
    public static function validUrls(): array
    {
        return [
            'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'short link' => ['https://youtu.be/dQw4w9WgXcQ'],
            'short link with tracking' => ['https://youtu.be/dQw4w9WgXcQ?si=abc123'],
            'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ'],
            'music' => ['https://music.youtube.com/watch?v=dQw4w9WgXcQ'],
            'no www' => ['https://youtube.com/watch?v=dQw4w9WgXcQ'],
            'http scheme' => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'no scheme' => ['www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ'],
            'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ'],
            'live path' => ['https://www.youtube.com/live/dQw4w9WgXcQ'],
            'nocookie embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
            'extra params' => ['https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=42s'],
            'with playlist param' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PL123456789'],
            'uppercase host' => ['HTTPS://WWW.YOUTUBE.COM/watch?v=dQw4w9WgXcQ'],
            'trailing dot host' => ['https://www.youtube.com./watch?v=dQw4w9WgXcQ'],
            'surrounding whitespace' => ["  https://youtu.be/dQw4w9WgXcQ \n"],
            'explicit https port' => ['https://www.youtube.com:443/watch?v=dQw4w9WgXcQ'],
        ];
    }

    #[DataProvider('validUrls')]
    public function testValidUrlsNormalizeToCanonicalForm(string $url): void
    {
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $this->sec->normalizeUrl($url));
        $this->assertSame('dQw4w9WgXcQ', $this->sec->extractVideoId($url));
    }

    /** @return array<string,array{mixed,string}> */
    public static function rejectedUrls(): array
    {
        return [
            'empty' => ['', 'INVALID_URL'],
            'whitespace only' => ["   \t", 'INVALID_URL'],
            'null' => [null, 'INVALID_URL'],
            'array' => [['https://youtu.be/dQw4w9WgXcQ'], 'INVALID_URL'],
            'not a url' => ['hello world', 'INVALID_URL'],
            'garbage' => ['%%%%', 'UNSUPPORTED_DOMAIN'],
            'too long' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&x=' . str_repeat('a', 2100), 'INVALID_URL'],
            'bad video id (short)' => ['https://www.youtube.com/watch?v=abc', 'INVALID_URL'],
            'bad video id (chars)' => ['https://www.youtube.com/watch?v=dQw4w9WgXc!', 'INVALID_URL'],
            'missing v' => ['https://www.youtube.com/watch', 'INVALID_URL'],
            'v as array' => ['https://www.youtube.com/watch?v[]=dQw4w9WgXcQ', 'INVALID_URL'],
            'playlist' => ['https://www.youtube.com/playlist?list=PL123456789', 'INVALID_URL'],
            'channel handle' => ['https://www.youtube.com/@somechannel', 'INVALID_URL'],
            'channel id' => ['https://www.youtube.com/channel/UC1234567890', 'INVALID_URL'],
            'root only' => ['https://www.youtube.com/', 'INVALID_URL'],
            // SSRF / domain restriction
            'localhost' => ['http://localhost/watch?v=dQw4w9WgXcQ', 'UNSUPPORTED_DOMAIN'],
            'loopback' => ['http://127.0.0.1/watch?v=dQw4w9WgXcQ', 'UNSUPPORTED_DOMAIN'],
            'loopback port' => ['http://127.0.0.1:8080/', 'UNSUPPORTED_DOMAIN'],
            'zero address' => ['http://0.0.0.0/', 'UNSUPPORTED_DOMAIN'],
            'private 10/8' => ['http://10.0.0.5/', 'UNSUPPORTED_DOMAIN'],
            'private 192.168' => ['http://192.168.1.1/admin', 'UNSUPPORTED_DOMAIN'],
            'private 172.16' => ['http://172.16.0.1/', 'UNSUPPORTED_DOMAIN'],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data/', 'UNSUPPORTED_DOMAIN'],
            'ipv6 loopback' => ['http://[::1]/', 'UNSUPPORTED_DOMAIN'],
            'decimal ip' => ['http://2130706433/', 'UNSUPPORTED_DOMAIN'],
            'hex ip' => ['http://0x7f000001/', 'UNSUPPORTED_DOMAIN'],
            'internal hostname' => ['http://intranet.local/video', 'UNSUPPORTED_DOMAIN'],
            'docker service name' => ['http://app:9000/', 'UNSUPPORTED_DOMAIN'],
            'other domain' => ['https://vimeo.com/12345', 'UNSUPPORTED_DOMAIN'],
            'lookalike suffix' => ['https://www.youtube.com.evil.com/watch?v=dQw4w9WgXcQ', 'UNSUPPORTED_DOMAIN'],
            'lookalike prefix' => ['https://evilyoutube.com/watch?v=dQw4w9WgXcQ', 'UNSUPPORTED_DOMAIN'],
            'unknown subdomain' => ['https://evil.youtube.com/watch?v=dQw4w9WgXcQ', 'UNSUPPORTED_DOMAIN'],
            'non-standard port' => ['https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ', 'UNSUPPORTED_DOMAIN'],
            'unicode homograph' => ["https://www.youtub\u{0435}.com/watch?v=dQw4w9WgXcQ", 'UNSUPPORTED_DOMAIN'],
            'redirect style' => ['https://evil.com/?u=https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'UNSUPPORTED_DOMAIN'],
            // schemes
            'file scheme' => ['file:///etc/passwd', 'INVALID_URL'],
            'ftp scheme' => ['ftp://www.youtube.com/watch?v=dQw4w9WgXcQ', 'INVALID_URL'],
            'data scheme' => ['data:text/html,<script>alert(1)</script>', 'INVALID_URL'],
            'javascript scheme' => ['javascript:alert(1)', 'INVALID_URL'],
            'gopher scheme' => ['gopher://www.youtube.com/', 'INVALID_URL'],
            // credentials / smuggling
            'userinfo trick' => ['https://www.youtube.com@evil.com/watch?v=dQw4w9WgXcQ', 'INVALID_URL'],
            'userinfo on youtube' => ['https://user:pass@www.youtube.com/watch?v=dQw4w9WgXcQ', 'INVALID_URL'],
            'backslash trick' => ['https://evil.com\\@www.youtube.com/watch?v=dQw4w9WgXcQ', 'INVALID_URL'],
            // shell metacharacters
            'semicolon' => ['https://youtu.be/dQw4w9WgXcQ;rm -rf /', 'INVALID_URL'],
            'pipe' => ['https://youtu.be/dQw4w9WgXcQ|cat /etc/passwd', 'INVALID_URL'],
            'backtick' => ['https://youtu.be/`id`', 'INVALID_URL'],
            'dollar subshell' => ['https://youtu.be/$(id)', 'INVALID_URL'],
            'ampersand command' => ['https://youtu.be/dQw4w9WgXcQ && whoami', 'INVALID_URL'],
            'newline injection' => ["https://youtu.be/dQw4w9WgXcQ\nhttps://evil.com", 'INVALID_URL'],
            'quote injection' => ['https://youtu.be/dQw4w9WgXcQ" --exec "id', 'INVALID_URL'],
            'option injection' => ['--exec=id', 'UNSUPPORTED_DOMAIN'],
            'null byte' => ["https://youtu.be/dQw4w9WgXcQ\0.evil", 'INVALID_URL'],
            'path traversal' => ['https://www.youtube.com/../../etc/passwd', 'INVALID_URL'],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function testRejectedUrls(mixed $url, string $code): void
    {
        try {
            $this->sec->normalizeUrl($url);
            $this->fail('URL should have been rejected: ' . json_encode($url));
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode(), $e->getMessage());
            $this->assertSame(400, $e->httpStatus());
        }
    }

    // ------------------------------------------------------------ format / quality / bitrate

    public function testFormatValidation(): void
    {
        $this->assertSame('mp4', $this->sec->validateFormat('mp4'));
        $this->assertSame('mp3', $this->sec->validateFormat(' MP3 '));
        foreach (['', 'avi', 'webm', 'mp4;ls', null, 5, ['mp4'], '../mp4'] as $bad) {
            try {
                $this->sec->validateFormat($bad);
                $this->fail('format accepted: ' . json_encode($bad));
            } catch (ApiException $e) {
                $this->assertSame('INVALID_FORMAT', $e->errorCode());
            }
        }
    }

    public function testQualityValidation(): void
    {
        $this->assertSame('best', $this->sec->validateQuality(null));
        $this->assertSame('best', $this->sec->validateQuality(''));
        $this->assertSame('720p', $this->sec->validateQuality('720p'));
        $this->assertSame('1080p', $this->sec->validateQuality('1080P'));
        foreach (['720', '999p', 'ultra', '720p; ls', '-S', '0p', 720, ['best']] as $bad) {
            try {
                $this->sec->validateQuality($bad);
                $this->fail('quality accepted: ' . json_encode($bad));
            } catch (ApiException $e) {
                $this->assertSame('INVALID_QUALITY', $e->errorCode());
            }
        }
    }

    public function testBitrateValidation(): void
    {
        $this->assertSame(192, $this->sec->validateBitrate(null));
        $this->assertSame(192, $this->sec->validateBitrate(''));
        foreach ([128, 192, 256, 320] as $ok) {
            $this->assertSame($ok, $this->sec->validateBitrate($ok));
            $this->assertSame($ok, $this->sec->validateBitrate((string) $ok));
        }
        foreach ([0, 64, 127, 321, 1000, -192, '192k', '192; ls', 192.5, [192], true] as $bad) {
            try {
                $this->sec->validateBitrate($bad);
                $this->fail('bitrate accepted: ' . json_encode($bad));
            } catch (ApiException $e) {
                $this->assertSame('INVALID_BITRATE', $e->errorCode());
            }
        }
    }

    // ------------------------------------------------------------ job ids / path traversal

    public function testJobIdValidation(): void
    {
        $id = $this->sec->newJobId();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
        $this->assertNotSame($id, $this->sec->newJobId(), 'ids are random');
        $this->assertSame($id, $this->sec->validateJobId($id));

        $bad = ['', 'abc123', '../etc/passwd', '..%2f..%2fetc', str_repeat('a', 31), str_repeat('A', 32), str_repeat('g', 32),
            $id . '/../x', $id . '.json', "$id\0", '..\\..\\windows', null, 123, ['x']];
        foreach ($bad as $value) {
            try {
                $this->sec->validateJobId($value);
                $this->fail('job id accepted: ' . json_encode($value));
            } catch (ApiException $e) {
                $this->assertSame('INVALID_JOB_ID', $e->errorCode());
            }
        }
    }

    public function testThumbnailsOnlyFromYoutubeImageHosts(): void
    {
        $this->assertSame('https://i.ytimg.com/vi/x/hq.jpg', $this->sec->safeThumbnail('https://i.ytimg.com/vi/x/hq.jpg'));
        $this->assertNull($this->sec->safeThumbnail('http://i.ytimg.com/vi/x/hq.jpg'));
        $this->assertNull($this->sec->safeThumbnail('https://evil.com/x.jpg'));
        $this->assertNull($this->sec->safeThumbnail('https://i.ytimg.com.evil.com/x.jpg'));
        $this->assertNull($this->sec->safeThumbnail('javascript:alert(1)'));
        $this->assertNull($this->sec->safeThumbnail(null));
    }
}
