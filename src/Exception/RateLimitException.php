<?php

declare(strict_types=1);

namespace RevAddress\USPSv3\Exception;

/**
 * USPS rate limit exceeded (60 req/hr default).
 */
class RateLimitException extends USPSException
{
    private ?int $retryAfter;

    public function __construct(?int $retryAfter = null, array $body = [])
    {
        $this->retryAfter = $retryAfter;
        parent::__construct(
            'USPS rate limit exceeded (60 req/hr default). Request increase at emailus.usps.com.',
            429,
            $body
        );
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
