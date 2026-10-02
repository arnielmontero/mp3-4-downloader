<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** POST /api/video/info - analyse a URL without downloading anything. */
class Video extends MY_Controller
{
    public function info(): void
    {
        $body = $this->jsonBody();
        // Validate before spending a rate-limit token or starting a process.
        $this->app->security()->extractVideoId($body['url'] ?? null);
        $this->app->rateLimit()->hit('analyze', $this->clientIp(), $this->app->config()->rateLimitAnalyze);
        $this->ok($this->app->videoInfo()->analyze($body['url']));
    }

    /** POST /api/search {"query": "..."} - find videos by text. */
    public function search(): void
    {
        $body = $this->jsonBody();
        $query = $this->app->security()->validateSearchQuery($body['query'] ?? null);
        $this->app->rateLimit()->hit('search', $this->clientIp(), $this->app->config()->rateLimitAnalyze);
        $this->ok($this->app->search()->search($query));
    }
}
