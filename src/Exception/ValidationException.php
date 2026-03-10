<?php

declare(strict_types=1);

namespace RevAddress\USPSv3\Exception;

/**
 * Input validation failure (missing/invalid fields).
 */
class ValidationException extends USPSException
{
    private string $field;

    public function __construct(string $message, string $field = '', array $body = [])
    {
        $this->field = $field;
        parent::__construct($message, 422, $body);
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function isRetryable(): bool
    {
        return false;
    }
}
