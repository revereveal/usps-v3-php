<?php

declare(strict_types=1);

namespace RevAddress\USPSv3\Tests;

use PHPUnit\Framework\TestCase;
use RevAddress\USPSv3\Client;
use RevAddress\USPSv3\Exception\AuthException;
use RevAddress\USPSv3\Exception\ValidationException;

/**
 * Tests for Client — public API surface, input validation, credential gating.
 *
 * These tests verify behavior that doesn't require network access:
 * - Validation rules (bad mail class, missing addresses, missing credentials)
 * - Token manager delegation
 * - Mail class constants
 */
class ClientTest extends TestCase
{
    // ── Mail class validation ────────────────────────────────────

    public function testMailClassConstants(): void
    {
        $expected = [
            'PRIORITY_MAIL_EXPRESS',
            'PRIORITY_MAIL',
            'FIRST-CLASS_PACKAGE_SERVICE',
            'PARCEL_SELECT',
            'LIBRARY_MAIL',
            'MEDIA_MAIL',
            'BOUND_PRINTED_MATTER',
            'USPS_GROUND_ADVANTAGE',
        ];
        $this->assertSame($expected, Client::MAIL_CLASSES);
    }

    // ── createLabel validation ───────────────────────────────────

    public function testCreateLabelInvalidMailClass(): void
    {
        $client = new Client('test-id', 'test-secret');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid mailClass');

        $client->createLabel(
            fromAddress: ['streetAddress' => '228 Park Ave S', 'city' => 'New York', 'state' => 'NY', 'ZIPCode' => '10003'],
            toAddress: ['streetAddress' => '1600 Pennsylvania Ave NW', 'city' => 'Washington', 'state' => 'DC', 'ZIPCode' => '20500'],
            mailClass: 'INVALID_CLASS',
            weight: 2.5,
        );
    }

    public function testCreateLabelEmptyFromAddress(): void
    {
        $client = new Client('test-id', 'test-secret');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('fromAddress is required');

        $client->createLabel(
            fromAddress: [],
            toAddress: ['streetAddress' => '1600 Pennsylvania Ave NW'],
            mailClass: 'PRIORITY_MAIL',
            weight: 1.0,
        );
    }

    public function testCreateLabelEmptyToAddress(): void
    {
        $client = new Client('test-id', 'test-secret');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('toAddress is required');

        $client->createLabel(
            fromAddress: ['streetAddress' => '228 Park Ave S'],
            toAddress: [],
            mailClass: 'PRIORITY_MAIL',
            weight: 1.0,
        );
    }

    public function testCreateLabelRequiresPaymentCredentials(): void
    {
        // Client without BYOK credentials
        $client = new Client('test-id', 'test-secret');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Label creation requires BYOK credentials');

        $client->createLabel(
            fromAddress: ['streetAddress' => '228 Park Ave S', 'city' => 'New York', 'state' => 'NY', 'ZIPCode' => '10003'],
            toAddress: ['streetAddress' => '1600 Pennsylvania Ave NW', 'city' => 'Washington', 'state' => 'DC', 'ZIPCode' => '20500'],
            mailClass: 'PRIORITY_MAIL',
            weight: 2.5,
        );
    }

    public function testCreateLabelAllMailClassesAccepted(): void
    {
        // Verify each valid mail class passes validation (will fail at auth step, not validation)
        foreach (Client::MAIL_CLASSES as $mailClass) {
            try {
                $client = new Client('test-id', 'test-secret');
                $client->createLabel(
                    fromAddress: ['streetAddress' => '228 Park Ave S'],
                    toAddress: ['streetAddress' => '1600 Pennsylvania Ave NW'],
                    mailClass: $mailClass,
                    weight: 1.0,
                );
                $this->fail("Expected AuthException for mail class: {$mailClass}");
            } catch (AuthException $e) {
                // Expected — passes validation but fails at credential check
                $this->assertStringContainsString('BYOK', $e->getMessage());
            } catch (ValidationException $e) {
                $this->fail("Mail class '{$mailClass}' should not throw ValidationException");
            }
        }
    }

    // ── voidLabel validation ─────────────────────────────────────

    public function testVoidLabelEmptyTracking(): void
    {
        $client = new Client('test-id', 'test-secret');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trackingNumber is required');

        $client->voidLabel('');
    }

    // ── Constructor / tokenStatus ────────────────────────────────

    public function testTokenStatusFreshClient(): void
    {
        $client = new Client('test-id', 'test-secret');
        $status = $client->tokenStatus();

        $this->assertArrayHasKey('oauth', $status);
        $this->assertArrayHasKey('payment', $status);
        $this->assertFalse($status['oauth']['valid']);
        $this->assertFalse($status['payment']['valid']);
        $this->assertFalse($status['payment']['available']);
    }

    public function testTokenStatusWithBYOK(): void
    {
        $client = new Client(
            'test-id', 'test-secret',
            crid: '56982563',
            masterMid: '904128936',
            labelMid: '904128937',
            epaAccount: 'eps-account',
        );
        $status = $client->tokenStatus();

        $this->assertTrue($status['payment']['available']);
        $this->assertFalse($status['payment']['valid']); // no token fetched yet
    }

    // ── getTokenManager ──────────────────────────────────────────

    public function testGetTokenManagerReturnsInstance(): void
    {
        $client = new Client('test-id', 'test-secret');
        $tm = $client->getTokenManager();

        $this->assertInstanceOf(\RevAddress\USPSv3\TokenManager::class, $tm);
    }
}
