<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * CodeIgniter renders HTML error pages by default. This is a JSON API, so every framework-level
 * error (404, show_error, uncaught exceptions) is converted to the standard error envelope and
 * never leaks file paths or stack traces.
 */
class MY_Exceptions extends CI_Exceptions
{
    private function respond(int $status, string $code, string $message): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        echo json_encode(['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    }

    public function show_404($page = '', $log_error = TRUE)
    {
        $this->respond(404, 'NOT_FOUND', 'Endpoint not found.');
        exit(4);
    }

    public function show_error($heading, $message, $template = 'error_general', $status_code = 500)
    {
        $status = is_numeric($status_code) ? (int) $status_code : 500;
        $this->respond($status, $status === 404 ? 'NOT_FOUND' : 'SERVER_ERROR', 'The request could not be processed.');
        exit(1);
    }

    public function show_exception($exception)
    {
        $this->respond(500, 'SERVER_ERROR', 'An unexpected error occurred.');
    }

    public function show_php_error($severity, $message, $filepath, $line)
    {
        // Details are written to the PHP error log by CI's handler; never echoed to the client.
        $this->respond(500, 'SERVER_ERROR', 'An unexpected error occurred.');
    }
}
