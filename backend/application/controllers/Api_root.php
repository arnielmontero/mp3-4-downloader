<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** GET / on the API host: a tiny pointer for humans hitting the backend directly. */
class Api_root extends MY_Controller
{
    public function index(): void
    {
        $this->ok(['name' => 'YouTube Downloader API', 'health' => '/api/health']);
    }
}
