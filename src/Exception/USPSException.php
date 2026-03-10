<?php

declare(strict_types=1);

namespace RevAddress\USPSv3\Exception;

/**
 * Base exception for all USPS v3 API errors.
 */
class USPSException extends \RuntimeException
{
    protected array $responseBody;

    public function __construct(string $message, int $code = 0, array $body = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->responseBody = $body;
    }

    public function getResponseBody(): array
    {
        return $this->responseBody;
    }

    /**
     * Whether this error is transient and worth retrying.
     */
    public function isRetryable(): bool
    {
        $code = $this->getCode();
        return $code === 429 || $code === 500 || $code === 502 || $code === 503 || $code === 504;
    }
}
