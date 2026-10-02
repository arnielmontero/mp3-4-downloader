<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** GET /api/health (used by the Docker healthcheck) and GET /api/about. */
class Health extends MY_Controller
{
    public function index(): void
    {
        $health = $this->app->health()->health();
        // Flat payload as specified: {"status":"ok","yt_dlp":true,"ffmpeg":true} (+ worker details).
        http_response_code($health['status'] === 'error' ? 503 : 200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($health);
    }

    public function about(): void
    {
        $this->ok($this->app->health()->about());
    }
}
