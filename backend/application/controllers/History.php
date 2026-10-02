<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * GET    /api/download/history          list this browser's downloads
 * DELETE /api/download/history/{id}     delete an entry (and its stored file)
 */
class History extends MY_Controller
{
    public function index(): void
    {
        $this->ok(['items' => $this->app->history()->list($this->clientId(false))]);
    }

    public function remove(string $id = ''): void
    {
        $this->app->security()->validateJobId($id);
        $this->app->history()->remove($id, $this->clientId(false));
        $this->ok(['deleted' => true]);
    }
}
