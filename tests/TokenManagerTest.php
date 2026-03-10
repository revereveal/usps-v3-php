<?php

declare(strict_types=1);

namespace RevAddress\USPSv3\Tests;

use PHPUnit\Framework\TestCase;
use RevAddress\USPSv3\TokenManager;

/**
 * Tests for TokenManager — file caching, credential checks, status reporting.
 *
 * Does NOT test actual token refresh (that hits apis.usps.com).
 * Tests the cache lifecycle, credential gating, and status output.
 */
class TokenManagerTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/usps_v3_test_' . uniqid();
        mkdir($this->cacheDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $cacheFile = $this->cacheDir . '/.usps_v3_tokens.json';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
        if (is_dir($this->cacheDir)) {
            rmdir($this->cacheDir);
        }
    }

    // ── hasPaymentCredentials ────────────────────────────────────

    public function testHasPaymentCredentialsAllSet(): void
    {
        $tm = new TokenManager(
            'test-id', 'test-secret', $this->cacheDir,
            '56982563', '904128936', '904128937', 'eps-account'
        );
        $this->assertTrue($tm->hasPaymentCredentials());
    }

    public function testHasPaymentCredentialsMissingCrid(): void
    {
        $tm = new TokenManager(
            'test-id', 'test-secret', $this->cacheDir,
            null, '904128936', '904128937', 'eps-account'
        );
        $this->assertFalse($tm->hasPaymentCredentials());
    }

    public function testHasPaymentCredentialsMissingMasterMid(): void
    {
        $tm = new TokenManager(
            'test-id', 'test-secret', $this->cacheDir,
            '56982563', null, '904128937', 'eps-account'
        );
        $this->assertFalse($tm->hasPaymentCredentials());
    }

    public function testHasPaymentCredentialsMissingEpa(): void
    {
        $tm = new TokenManager(
            'test-id', 'test-secret', $this->cacheDir,
            '56982563', '904128936', '904128937', null
        );
        $this->assertFalse($tm->hasPaymentCredentials());
    }

    public function testLabelMidDefaultsToMasterMid(): void
    {
        // When labelMid is null, it defaults to masterMid
        // We test this indirectly via hasPaymentCredentials (labelMid gets set)
        $tm = new TokenManager(
            'test-id', 'test-secret', $this->cacheDir,
            '56982563', '904128936', null, 'eps-account'
        );
        $this->assertTrue($tm->hasPaymentCredentials());
    }

    public function testNoCredentialsAtAll(): void
    {
        $tm = new TokenManager('test-id', 'test-secret', $this->cacheDir);
        $this->assertFalse($tm->hasPaymentCredentials());
    }

    // ── status ───────────────────────────────────────────────────

    public function testStatusFreshInstance(): void
    {
        $tm = new TokenManager('test-id', 'test-secret', $this->cacheDir);
        $status = $tm->status();

        $this->assertFalse($status['oauth']['valid']);
        $this->assertSame(0, $status['oauth']['ttl_seconds']);
        $this->assertFalse($status['payment']['valid']);
        $this->assertSame(0, $status['payment']['ttl_seconds']);
        $this->assertFalse($status['payment']['available']);
    }

    public function testStatusWithPaymentCredentials(): void
    {
        $tm = new TokenManager(
            'test-id', 'test-secret', $this->cacheDir,
            '56982563', '904128936', '904128937', 'eps'
        );
        $status = $tm->status();

        $this->assertTrue($status['payment']['available']);
        $this->assertFalse($status['payment']['valid']); // no token yet
    }

    // ── Cache hydration ──────────────────────────────────────────

    public function testHydrateFromValidCache(): void
    {
        // Write a valid cache file
        $cacheFile = $this->cacheDir . '/.usps_v3_tokens.json';
        $futureTime = time() + 3600; // 1 hour from now
        file_put_contents($cacheFile, json_encode([
            'oauth_token'        => 'cached-oauth-token-abc',
            'oauth_expires_at'   => $futureTime,
            'payment_token'      => 'cached-payment-token-xyz',
            'payment_expires_at' => $futureTime,
            'cached_at'          => time(),
        ]));

        $tm = new TokenManager('test-id', 'test-secret', $this->cacheDir);
        $status = $tm->status();

        $this->assertTrue($status['oauth']['valid']);
        $this->assertGreaterThan(3500, $status['oauth']['ttl_seconds']);
    }

    public function testHydrateFromExpiredCache(): void
    {
        // Write an expired cache file
        $cacheFile = $this->cacheDir . '/.usps_v3_tokens.json';
        file_put_contents($cacheFile, json_encode([
            'oauth_token'        => 'expired-token',
            'oauth_expires_at'   => time() - 100, // expired
            'payment_token'      => null,
            'payment_expires_at' => 0,
            'cached_at'          => time() - 200,
        ]));

        $tm = new TokenManager('test-id', 'test-secret', $this->cacheDir);
        $status = $tm->status();

        $this->assertFalse($status['oauth']['valid']);
    }

    public function testHydrateFromCorruptCache(): void
    {
        $cacheFile = $this->cacheDir . '/.usps_v3_tokens.json';
        file_put_contents($cacheFile, 'NOT-JSON');

        $tm = new TokenManager('test-id', 'test-secret', $this->cacheDir);
        $status = $tm->status();

        $this->assertFalse($status['oauth']['valid']);
    }

    public function testHydrateFromMissingCache(): void
    {
        // No cache file at all
        $tm = new TokenManager('test-id', 'test-secret', $this->cacheDir);
        $status = $tm->status();

        $this->assertFalse($status['oauth']['valid']);
        $this->assertFalse($status['payment']['valid']);
    }

    // ── getOAuthToken (cache hit) ────────────────────────────────

    public function testGetOAuthTokenFromCache(): void
    {
        // Pre-populate cache with a valid token
        $cacheFile = $this->cacheDir . '/.usps_v3_tokens.json';
        file_put_contents($cacheFile, json_encode([
            'oauth_token'        => 'test-bearer-token-12345',
            'oauth_expires_at'   => time() + 7200,
            'payment_token'      => null,
            'payment_expires_at' => 0,
            'cached_at'          => time(),
        ]));

        $tm = new TokenManager('test-id', 'test-secret', $this->cacheDir);
        $token = $tm->getOAuthToken();

        $this->assertSame('test-bearer-token-12345', $token);
    }

    // ── getBothTokens (cache hit) ────────────────────────────────

    public function testGetBothTokensFromCache(): void
    {
        $cacheFile = $this->cacheDir . '/.usps_v3_tokens.json';
        file_put_contents($cacheFile, json_encode([
            'oauth_token'        => 'oauth-abc',
            'oauth_expires_at'   => time() + 7200,
            'payment_token'      => 'payment-xyz',
            'payment_expires_at' => time() + 7200,
            'cached_at'          => time(),
        ]));

        $tm = new TokenManager(
            'test-id', 'test-secret', $this->cacheDir,
            '56982563', '904128936', '904128937', 'eps'
        );
        $tokens = $tm->getBothTokens();

        $this->assertSame('oauth-abc', $tokens['oauth']);
        $this->assertSame('payment-xyz', $tokens['payment']);
    }

    // ── Cache directory creation ─────────────────────────────────

    public function testCacheDirCreatedOnDemand(): void
    {
        $nested = $this->cacheDir . '/nested/deep/cache';
        $this->assertDirectoryDoesNotExist($nested);

        // TokenManager creates cache dir on demand (during persist)
        // We verify the constructor doesn't crash with a non-existent dir
        $tm = new TokenManager('test-id', 'test-secret', $nested);
        $this->assertFalse($tm->hasPaymentCredentials());
    }
}
