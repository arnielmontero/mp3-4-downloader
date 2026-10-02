<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An error that is safe to show to API clients: stable code, friendly message, HTTP status.
 */
final class ApiException extends \RuntimeException
{
    /** Stable error code => default HTTP status. */
    private const STATUS = [
        'INVALID_REQUEST' => 400,
        'INVALID_URL' => 400,
        'INVALID_FORMAT' => 400,
        'INVALID_QUALITY' => 400,
        'INVALID_BITRATE' => 400,
        'INVALID_JOB_ID' => 400,
        'INVALID_QUERY' => 400,
        'UNSUPPORTED_DOMAIN' => 400,
        'JOB_NOT_FOUND' => 404,
        'FILE_NOT_FOUND' => 404,
        'NOT_FOUND' => 404,
        'METHOD_NOT_ALLOWED' => 405,
        'JOB_NOT_READY' => 409,
        'JOB_NOT_CANCELLABLE' => 409,
        'JOB_ACTIVE' => 409,
        'JOB_CANCELLED' => 409,
        'REQUEST_TOO_LARGE' => 413,
        'UNSUPPORTED_MEDIA_TYPE' => 415,
        'VIDEO_UNAVAILABLE' => 422,
        'VIDEO_TOO_LONG' => 422,
        'FILE_TOO_LARGE' => 422,
        'DOWNLOAD_FAILED' => 502,
        'PROCESSING_FAILED' => 500,
        'RATE_LIMITED' => 429,
        'SERVER_ERROR' => 500,
    ];

    private string $errorCode;
    private int $httpStatus;
    /** @var array<string,mixed> */
    private array $extra;

    /** @param array<string,mixed> $extra */
    public function __construct(string $errorCode, string $message, ?int $httpStatus = null, array $extra = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus ?? (self::STATUS[$errorCode] ?? 500);
        $this->extra = $extra;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string,mixed> */
    public function extra(): array
    {
        return $this->extra;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['code' => $this->errorCode, 'message' => $this->getMessage()] + $this->extra;
    }
}
