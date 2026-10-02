<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MediaVerifier;
use PHPUnit\Framework\TestCase;

final class MediaVerifierTest extends TestCase
{
    private static function probe(string $formatName, ?float $duration, array $streams): string
    {
        $format = ['format_name' => $formatName];
        if ($duration !== null) {
            $format['duration'] = (string) $duration;
        }
        return json_encode(['format' => $format, 'streams' => $streams]);
    }

    public function testGoodFilesPass(): void
    {
        $mp4 = self::probe('mov,mp4,m4a,3gp,3g2,mj2', 213.1, [['codec_type' => 'video', 'codec_name' => 'h264'], ['codec_type' => 'audio', 'codec_name' => 'aac']]);
        $this->assertNull(MediaVerifier::checkProbe($mp4, 'mp4', 213));
        $mp3 = self::probe('mp3', 213.0, [['codec_type' => 'audio', 'codec_name' => 'mp3']]);
        $this->assertNull(MediaVerifier::checkProbe($mp3, 'mp3', 214));
        $this->assertNull(MediaVerifier::checkProbe($mp3, 'mp3', null), 'unknown expected length: only structure is checked');
    }

    public function testBrokenFilesAreRejected(): void
    {
        $video = [['codec_type' => 'video', 'codec_name' => 'h264']];
        $audio = [['codec_type' => 'audio', 'codec_name' => 'mp3']];
        $cases = [
            'not json' => ['garbage', 'mp4', 10],
            'empty' => ['{}', 'mp3', 10],
            'wrong container for mp4' => [self::probe('matroska,webm', 10.0, $video), 'mp4', 10],
            'mp4 without video' => [self::probe('mov,mp4,m4a', 10.0, [['codec_type' => 'audio', 'codec_name' => 'aac']]), 'mp4', 10],
            'mp3 without audio' => [self::probe('mp3', 10.0, []), 'mp3', 10],
            'wrong container for mp3' => [self::probe('wav', 10.0, $audio), 'mp3', 10],
            'zero duration' => [self::probe('mp3', 0.0, $audio), 'mp3', null],
            'missing duration' => [self::probe('mp3', null, $audio), 'mp3', null],
            'truncated (half the length)' => [self::probe('mp3', 100.0, $audio), 'mp3', 200],
            'much longer than announced' => [self::probe('mov,mp4', 400.0, $video), 'mp4', 200],
            'stream without codec' => [self::probe('mov,mp4', 10.0, [['codec_type' => 'video']]), 'mp4', 10],
        ];
        foreach ($cases as $name => [$json, $format, $expected]) {
            $this->assertNotNull(MediaVerifier::checkProbe($json, $format, $expected), $name);
        }
    }

    public function testDurationToleranceBoundary(): void
    {
        $mp3 = static fn (float $d): string => self::probe('mp3', $d, [['codec_type' => 'audio', 'codec_name' => 'mp3']]);
        $this->assertNull(MediaVerifier::checkProbe($mp3(205.0), 'mp3', 200), 'within 5 percent is tolerated');
        $this->assertNotNull(MediaVerifier::checkProbe($mp3(188.0), 'mp3', 200));
        $this->assertNull(MediaVerifier::checkProbe($mp3(41.0), 'mp3', 40), 'short clips: 3 s minimum tolerance');
        $this->assertNull(MediaVerifier::checkProbe($mp3(1900.0), 'mp3', 2000), '5% of a long video is tolerated');
        $this->assertNotNull(MediaVerifier::checkProbe($mp3(1850.0), 'mp3', 2000));
    }

    public function testDecodedLengthMustReachTheReference(): void
    {
        $this->assertNull(MediaVerifier::checkDecode("out_time_us=1000000\nout_time_us=213000000\nprogress=end\n", 213.0));
        $this->assertNull(MediaVerifier::checkDecode("out_time_ms=212000000\nprogress=end\n", 213.0), 'within tolerance');
        $this->assertNotNull(MediaVerifier::checkDecode("out_time_us=18000000\nprogress=end\n", 30.0), 'truncated: header promises 30 s, only 18 s decode');
        $this->assertNotNull(MediaVerifier::checkDecode("progress=end\n", 30.0), 'no progress output at all');
        $this->assertSame(213.0, MediaVerifier::probeDuration('{"format":{"duration":"213.000"}}'));
        $this->assertSame(0.0, MediaVerifier::probeDuration('nope'));
    }

    public function testDecodeTimeoutScalesWithLength(): void
    {
        $this->assertSame(300, MediaVerifier::decodeTimeout(null));
        $this->assertSame(300, MediaVerifier::decodeTimeout(60));
        $this->assertSame(14400 * 2 + 120, MediaVerifier::decodeTimeout(14400));
    }
}
