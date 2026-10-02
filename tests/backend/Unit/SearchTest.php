<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Media\YtDlpDownloader;
use App\Support\ApiException;
use Tests\Support\TestCase;

final class SearchTest extends TestCase
{
    public function testQueryIsNormalised(): void
    {
        $sec = $this->container()->security();
        $this->assertSame('rick astley never gonna', $sec->validateSearchQuery("  rick\t astley \n never   gonna  "));
        $this->assertSame('Música ñandú 日本語', $sec->validateSearchQuery('Música ñandú 日本語'));
        $this->assertSame('a b', $sec->validateSearchQuery("a\0b"), 'control characters become spaces');
        $this->assertSame(100, mb_strlen($sec->validateSearchQuery(str_repeat('é', 100))));
    }

    public function testBadQueriesAreRejected(): void
    {
        $sec = $this->container()->security();
        foreach (['', '   ', "\t\n", null, 5, ['x'], str_repeat('a', 101), "\xff\xfe"] as $bad) {
            try {
                $sec->validateSearchQuery($bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (ApiException $e) {
                $this->assertSame('INVALID_QUERY', $e->errorCode());
                $this->assertSame(400, $e->httpStatus());
            }
        }
    }

    public function testSearchCommandCannotBeHijackedByTheQuery(): void
    {
        $c = $this->container();
        $d = new YtDlpDownloader($c->config(), $c->processes(), $c->security());
        foreach (['--exec id', '; rm -rf /', '$(id) `id` | cat', "--output /etc/passwd"] as $evil) {
            $cmd = $d->buildSearchCommand($evil, 10);
            $this->assertSame('ytsearch10:' . $evil, end($cmd), 'whole query is one argument with a fixed prefix');
            $this->assertSame('--', $cmd[count($cmd) - 2]);
            $this->assertSame(1, count(array_keys($cmd, 'ytsearch10:' . $evil, true)));
            $this->assertContains('--flat-playlist', $cmd);
            $this->assertNotContains('-o', $cmd, 'search never downloads media');
        }
    }
}
