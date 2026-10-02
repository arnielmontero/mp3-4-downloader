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
}
