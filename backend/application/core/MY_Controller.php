<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use App\Container;
use App\Support\ApiException;

/**
 * Base class for all API controllers.
 *
 * Responsibilities: build the service container, enforce the request-size limit, parse JSON
 * bodies, add security headers, emit the standard {success,data|error} envelope and turn every
 * exception into a safe JSON error (internal details are logged, never returned).
 */
class MY_Controller extends CI_Controller
{
    protected Container $app;
    protected const CLIENT_COOKIE = 'ytd_client';

    public function __construct()
    {
        parent::__construct();
        $this->app = Container::fromEnvironment();
        $this->securityHeaders();
    }

    /** CI calls this instead of the routed method, so we can wrap every action in error handling. */
    public function _remap($method, $params = [])
    {
        try {
            if (!method_exists($this, $method)) {
                throw new ApiException('NOT_FOUND', 'Endpoint not found.');
            }
            call_user_func_array([$this, $method], $params);
        } catch (ApiException $e) {
            $this->fail($e);
        } catch (\Throwable $e) {
            $this->app->logger()->error(get_class($e) . ': ' . $e->getMessage(), ['op' => 'request', 'status' => 'error', 'error_code' => 'SERVER_ERROR']);
            $this->fail(new ApiException('SERVER_ERROR', 'An unexpected error occurred. Please try again later.'));
        }
    }

    protected function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        header('Cross-Origin-Resource-Policy: same-origin');
    }

    /** @param mixed $data */
    protected function ok($data, int $status = 200): void
    {
        $this->json(['success' => true, 'data' => $data], $status);
    }

    protected function fail(ApiException $e): void
    {
        if ($e->errorCode() === 'RATE_LIMITED' && isset($e->extra()['retry_after'])) {
            header('Retry-After: ' . (int) $e->extra()['retry_after']);
        }
        $this->json(['success' => false, 'error' => $e->toArray()], $e->httpStatus());
    }

    /** @param array<string,mixed> $payload */
    protected function json(array $payload, int $status): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Parse and size-check a JSON request body. @return array<string,mixed> */
    protected function jsonBody(): array
    {
        $limit = $this->app->config()->maxRequestBodyBytes;
        $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($declared > $limit) {
            throw new ApiException('REQUEST_TOO_LARGE', 'The request is too large.');
        }
        $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
        if ($raw === false || strlen($raw) > $limit) {
            throw new ApiException('REQUEST_TOO_LARGE', 'The request is too large.');
        }
        if (trim($raw) === '') {
            throw new ApiException('INVALID_REQUEST', 'The request body is empty.');
        }
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_starts_with($type, 'application/json')) {
            throw new ApiException('UNSUPPORTED_MEDIA_TYPE', 'Content-Type must be application/json.');
        }
        $data = json_decode($raw, true, 8);
        if (!is_array($data) || array_is_list($data)) {
            throw new ApiException('INVALID_REQUEST', 'The request body must be a JSON object.');
        }
        return $data;
    }

    /** Client IP for rate limiting. Proxy headers are only trusted when explicitly enabled. */
    protected function clientIp(): string
    {
        if ($this->app->config()->trustProxyHeaders && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** Anonymous per-browser id (cookie) used to scope the download history. */
    protected function clientId(bool $create): ?string
    {
        $id = $_COOKIE[self::CLIENT_COOKIE] ?? null;
        if (is_string($id) && preg_match('/^[a-f0-9]{32}$/', $id) === 1) {
            return $id;
        }
        if (!$create) {
            return null;
        }
        $id = bin2hex(random_bytes(16));
        setcookie(self::CLIENT_COOKIE, $id, [
            'expires' => time() + 86400 * 365,
            'path' => '/',
            'secure' => $this->app->config()->cookieSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::CLIENT_COOKIE] = $id;
        return $id;
    }
}
