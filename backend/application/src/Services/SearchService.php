<?php

declare(strict_types=1);

namespace App\Services;

use App\Media\MediaDownloader;
use App\Support\Logger;

/** YouTube search for the main screen: validates the text, asks the media engine, returns downloadable videos. */
final class SearchService
{
    public const LIMIT = 10;

    public function __construct(private SecurityService $security, private MediaDownloader $media, private Logger $logger)
    {
    }

    /** @return array{query:string,results:list<array<string,mixed>>} */
    public function search(mixed $query): array
    {
        $query = $this->security->validateSearchQuery($query);
        $started = microtime(true);
        $results = $this->media->search($query, self::LIMIT);
        $this->logger->info('search returned ' . count($results) . ' results', ['op' => 'search', 'status' => 'ok', 'duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
        return ['query' => $query, 'results' => $results];
    }
}
