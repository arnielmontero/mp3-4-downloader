<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Services\FileService;

/**
 * POST /api/download                    create a job
 * GET  /api/download/{id}               job status / progress
 * POST /api/download/{id}/cancel        cancel
 * GET  /api/download/{id}/file          controlled file download
 */
class Download extends MY_Controller
{
    public function create(): void
    {
        $body = $this->jobBodyChecked();
        $this->app->rateLimit()->hit('download', $this->clientIp(), $this->app->config()->rateLimitDownload);
        $job = $this->app->downloads()->create($body, $this->clientId(true));
        $this->json(['success' => true, 'job_id' => $job['job_id'], 'data' => $job], 202);
    }

    public function status(string $id = ''): void
    {
        $this->ok($this->app->downloads()->status($id));
    }

    public function cancel(string $id = ''): void
    {
        $this->ok($this->app->downloads()->cancel($id));
    }

    public function file(string $id = ''): void
    {
        $file = $this->app->downloads()->fileFor($id);
        $config = $this->app->config();

        header('Content-Type: ' . $file['type']);
        header('Content-Disposition: ' . FileService::contentDisposition($file['filename']));
        header('Cache-Control: private, no-store');
        header('Accept-Ranges: bytes');

        if ($config->accelRedirectPrefix !== '') {
            // nginx serves the bytes from an internal-only location (supports Range, no PHP memory use).
            header('X-Accel-Redirect: ' . rtrim($config->accelRedirectPrefix, '/') . '/' . $id . '/' . rawurlencode($file['filename']));
            return;
        }
        header('Content-Length: ' . $file['size']);
        $fh = fopen($file['path'], 'rb');
        if ($fh === false) {
            throw new \App\Support\ApiException('FILE_NOT_FOUND', 'The file is no longer available.');
        }
        while (!feof($fh)) {
            echo fread($fh, 1048576);
            flush();
        }
        fclose($fh);
    }

    /** @return array<string,mixed> */
    private function jobBodyChecked(): array
    {
        $body = $this->jsonBody();
        // Fail fast on bad input (before rate limiting counts it).
        $sec = $this->app->security();
        $sec->extractVideoId($body['url'] ?? null);
        $format = $sec->validateFormat($body['format'] ?? null);
        if ($format === 'mp4') {
            $sec->validateQuality($body['quality'] ?? null);
        } else {
            $sec->validateBitrate($body['bitrate'] ?? null);
        }
        return $body;
    }
}
