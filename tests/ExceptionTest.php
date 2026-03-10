<?php

declare(strict_types=1);

namespace RevAddress\USPSv3\Tests;

use PHPUnit\Framework\TestCase;
use RevAddress\USPSv3\Exception\AuthException;
use RevAddress\USPSv3\Exception\RateLimitException;
use RevAddress\USPSv3\Exception\USPSException;
use RevAddress\USPSv3\Exception\ValidationException;

/**
 * Tests for the exception hierarchy.
 *
 * Covers: retryability, status codes, response body access, field names,
 * retry-after parsing, and inheritance chain.
 */
class ExceptionTest extends TestCase
{
    // ── USPSException ────────────────────────────────────────────

    public function testUSPSExceptionMessage(): void
    {
        $e = new USPSException('Something broke', 500, ['error' => 'internal']);
        $this->assertSame('Something broke', $e->getMessage());
        $this->assertSame(500, $e->getCode());
        $this->assertSame(['error' => 'internal'], $e->getResponseBody());
    }

    public function testIsRetryable500(): void
    {
        $this->assertTrue((new USPSException('', 500))->isRetryable());
    }

    public function testIsRetryable502(): void
    {
        $this->assertTrue((new USPSException('', 502))->isRetryable());
    }

    public function testIsRetryable503(): void
    {
        $this->assertTrue((new USPSException('', 503))->isRetryable());
    }

    public function testIsRetryable504(): void
    {
        $this->assertTrue((new USPSException('', 504))->isRetryable());
    }

    public function testIsRetryable429(): void
    {
        $this->assertTrue((new USPSException('', 429))->isRetryable());
    }

    public function testNotRetryable400(): void
    {
        $this->assertFalse((new USPSException('', 400))->isRetryable());
    }

    public function testNotRetryable403(): void
    {
        $this->assertFalse((new USPSException('', 403))->isRetryable());
    }

    public function testNotRetryable404(): void
    {
        $this->assertFalse((new USPSException('', 404))->isRetryable());
    }

    public function testDefaultEmptyBody(): void
    {
        $e = new USPSException('msg');
        $this->assertSame([], $e->getResponseBody());
    }

    // ── AuthException ────────────────────────────────────────────

    public function testAuthExceptionInheritance(): void
    {
        $e = new AuthException('OAuth failed', 401, ['error' => 'invalid_client']);
        $this->assertInstanceOf(USPSException::class, $e);
        $this->assertSame(401, $e->getCode());
        $this->assertSame(['error' => 'invalid_client'], $e->getResponseBody());
    }

    public function testAuthExceptionNotRetryable(): void
    {
        $e = new AuthException('bad creds', 401);
        $this->assertFalse($e->isRetryable());
    }

    // ── RateLimitException ───────────────────────────────────────

    public function testRateLimitExceptionWithRetryAfter(): void
    {
        $e = new RateLimitException(3600);
        $this->assertSame(429, $e->getCode());
        $this->assertSame(3600, $e->getRetryAfter());
        $this->assertTrue($e->isRetryable());
    }

    public function testRateLimitExceptionNullRetryAfter(): void
    {
        $e = new RateLimitException();
        $this->assertNull($e->getRetryAfter());
        $this->assertTrue($e->isRetryable());
    }

    public function testRateLimitExceptionInheritance(): void
    {
        $e = new RateLimitException(60);
        $this->assertInstanceOf(USPSException::class, $e);
        $this->assertStringContainsString('60 req/hr', $e->getMessage());
    }

    // ── ValidationException ──────────────────────────────────────

    public function testValidationExceptionField(): void
    {
        $e = new ValidationException('ZIPCode is required', 'ZIPCode');
        $this->assertSame(422, $e->getCode());
        $this->assertSame('ZIPCode', $e->getField());
        $this->assertStringContainsString('ZIPCode is required', $e->getMessage());
    }

    public function testValidationExceptionNotRetryable(): void
    {
        $e = new ValidationException('bad input', 'streetAddress');
        $this->assertFalse($e->isRetryable());
    }

    public function testValidationExceptionEmptyField(): void
    {
        $e = new ValidationException('general validation error');
        $this->assertSame('', $e->getField());
    }

    public function testValidationExceptionInheritance(): void
    {
        $e = new ValidationException('bad', 'field');
        $this->assertInstanceOf(USPSException::class, $e);
    }
}
