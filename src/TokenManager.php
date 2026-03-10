<?php

declare(strict_types=1);

namespace RevAddress\USPSv3;

use RevAddress\USPSv3\Exception\AuthException;

/**
 * OAuth 2.0 + Payment Authorization token management for USPS v3.
 *
 * Two-token lifecycle:
 *   1. OAuth Bearer Token (8h) — required for all API calls
 *   2. Payment Authorization Token (8h) — required for label creation only
 *
 * Token caching: file-based at $cacheDir/.usps_v3_tokens.json with 30-min buffer.
 */
class TokenManager
{
    private const OAUTH_URL       = 'https://apis.usps.com/oauth2/v3/token';
    private const PAYMENT_URL     = 'https://apis.usps.com/payments/v3/payment-authorization';
    private const TOKEN_LIFETIME  = 28800;  // 8 hours
    private const EXPIRY_BUFFER   = 1800;   // 30-minute safety margin
    private const CACHE_FILE      = '.usps_v3_tokens.json';

    private string $clientId;
    private string $clientSecret;
    private string $cacheDir;

    // In-memory token state
    private ?string $oauthToken       = null;
    private float   $oauthExpiresAt   = 0;
    private ?string $paymentToken     = null;
    private float   $paymentExpiresAt = 0;

    // BYOK enrollment credentials (required for Payment Auth)
    private ?string $crid       = null;
    private ?string $masterMid  = null;
    private ?string $labelMid   = null;
    private ?string $epaAccount = null;

    public function __construct(
        string  $clientId,
        string  $clientSecret,
        ?string $cacheDir   = null,
        ?string $crid       = null,
        ?string $masterMid  = null,
        ?string $labelMid   = null,
        ?string $epaAccount = null
    ) {
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->cacheDir     = $cacheDir ?? sys_get_temp_dir();
        $this->crid         = $crid;
        $this->masterMid    = $masterMid;
        $this->labelMid     = $labelMid ?? $masterMid;
        $this->epaAccount   = $epaAccount;

        $this->hydrateFromCache();
    }

    /**
     * Get a valid OAuth bearer token, refreshing if needed.
     */
    public function getOAuthToken(): string
    {
        $now = time();

        if ($this->oauthToken && $this->oauthExpiresAt > $now + 60) {
            return $this->oauthToken;
        }

        $this->hydrateFromCache();
        if ($this->oauthToken && $this->oauthExpiresAt > $now + 60) {
            return $this->oauthToken;
        }

        return $this->refreshOAuth();
    }

    /**
     * Get a valid Payment Authorization token (for label creation).
     *
     * Requires crid, masterMid, labelMid, and epaAccount.
     * These come from USPS Business Customer Gateway enrollment.
     */
    public function getPaymentToken(): string
    {
        $now = time();

        if ($this->paymentToken && $this->paymentExpiresAt > $now + 60) {
            return $this->paymentToken;
        }

        $this->hydrateFromCache();
        if ($this->paymentToken && $this->paymentExpiresAt > $now + 60) {
            return $this->paymentToken;
        }

        // Ensure OAuth token is valid first
        $this->getOAuthToken();

        return $this->refreshPaymentAuth();
    }

    /**
     * Get both tokens in one call (convenience for label creation).
     *
     * @return array{oauth: string, payment: string}
     */
    public function getBothTokens(): array
    {
        return [
            'oauth'   => $this->getOAuthToken(),
            'payment' => $this->getPaymentToken(),
        ];
    }

    /**
     * Force refresh of all tokens.
     */
    public function forceRefresh(): array
    {
        $result = ['oauth' => false, 'payment' => false];

        try {
            $this->refreshOAuth();
            $result['oauth'] = true;
        } catch (AuthException $e) {
            $result['oauth_error'] = $e->getMessage();
        }

        if ($this->crid && $this->masterMid) {
            try {
                $this->refreshPaymentAuth();
                $result['payment'] = true;
            } catch (AuthException $e) {
                $result['payment_error'] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Current token status (for debugging/health checks).
     */
    public function status(): array
    {
        $now = time();
        return [
            'oauth' => [
                'valid'       => $this->oauthToken !== null && $this->oauthExpiresAt > $now,
                'ttl_seconds' => max(0, (int)($this->oauthExpiresAt - $now)),
            ],
            'payment' => [
                'valid'       => $this->paymentToken !== null && $this->paymentExpiresAt > $now,
                'ttl_seconds' => max(0, (int)($this->paymentExpiresAt - $now)),
                'available'   => $this->crid !== null && $this->masterMid !== null,
            ],
        ];
    }

    /**
     * Whether Payment Authorization is configured (BYOK credentials set).
     */
    public function hasPaymentCredentials(): bool
    {
        return $this->crid !== null
            && $this->masterMid !== null
            && $this->labelMid !== null
            && $this->epaAccount !== null;
    }

    // ── Internal ───────────────────────────────────────────────────

    private function refreshOAuth(): string
    {
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => 'addresses tracking prices labels payments pickup locations service-standards',
        ]);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json",
                'content'       => $body,
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(self::OAUTH_URL, false, $context);
        $decoded  = json_decode($response ?: '{}', true) ?? [];

        $statusCode = $this->extractStatusCode($http_response_header ?? []);
        if ($statusCode >= 400 || !isset($decoded['access_token'])) {
            throw new AuthException(
                'OAuth token refresh failed: ' . ($decoded['error_description'] ?? $decoded['error'] ?? "HTTP {$statusCode}"),
                $statusCode,
                $decoded
            );
        }

        $expiresIn = (int)($decoded['expires_in'] ?? self::TOKEN_LIFETIME);
        $this->oauthToken     = $decoded['access_token'];
        $this->oauthExpiresAt = time() + $expiresIn - self::EXPIRY_BUFFER;

        $this->persistCache();
        return $this->oauthToken;
    }

    private function refreshPaymentAuth(): string
    {
        if (!$this->oauthToken) {
            throw new AuthException('Cannot refresh Payment Auth without a valid OAuth token');
        }

        if (!$this->hasPaymentCredentials()) {
            throw new AuthException(
                'Payment Auth requires crid, masterMid, labelMid, and epaAccount. '
                . 'These come from your USPS Business Customer Gateway enrollment.'
            );
        }

        $payload = json_encode([
            'roles' => [
                [
                    'roleName'      => 'PAYER',
                    'CRID'          => $this->crid,
                    'MID'           => $this->masterMid,
                    'manifestMID'   => $this->masterMid,
                    'accountType'   => 'EPS',
                    'accountNumber' => $this->epaAccount,
                ],
                [
                    'roleName'      => 'LABEL_OWNER',
                    'CRID'          => $this->crid,
                    'MID'           => $this->labelMid,
                    'manifestMID'   => $this->masterMid,
                    'accountType'   => 'EPS',
                    'accountNumber' => $this->epaAccount,
                ],
            ],
        ]);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    "Authorization: Bearer {$this->oauthToken}",
                ]),
                'content'       => $payload,
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(self::PAYMENT_URL, false, $context);
        $decoded  = json_decode($response ?: '{}', true) ?? [];

        $statusCode = $this->extractStatusCode($http_response_header ?? []);
        if ($statusCode >= 400 || !isset($decoded['paymentAuthorizationToken'])) {
            throw new AuthException(
                'Payment Auth failed: ' . ($decoded['error']['message'] ?? $decoded['message'] ?? "HTTP {$statusCode}"),
                $statusCode,
                $decoded
            );
        }

        $expiresIn = (int)($decoded['expiresIn'] ?? $decoded['expires_in'] ?? self::TOKEN_LIFETIME);
        $this->paymentToken     = $decoded['paymentAuthorizationToken'];
        $this->paymentExpiresAt = time() + $expiresIn - self::EXPIRY_BUFFER;

        $this->persistCache();
        return $this->paymentToken;
    }

    private function hydrateFromCache(): void
    {
        $path = $this->cacheDir . '/' . self::CACHE_FILE;
        if (!file_exists($path)) return;

        $data = @json_decode(file_get_contents($path), true);
        if (!is_array($data)) return;

        $now = time();
        if (($data['oauth_expires_at'] ?? 0) > $now) {
            $this->oauthToken     = $data['oauth_token'] ?? null;
            $this->oauthExpiresAt = (float)($data['oauth_expires_at'] ?? 0);
        }
        if (($data['payment_expires_at'] ?? 0) > $now) {
            $this->paymentToken     = $data['payment_token'] ?? null;
            $this->paymentExpiresAt = (float)($data['payment_expires_at'] ?? 0);
        }
    }

    private function persistCache(): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0700, true);
        }

        $path = $this->cacheDir . '/' . self::CACHE_FILE;
        $data = [
            'oauth_token'        => $this->oauthToken,
            'oauth_expires_at'   => $this->oauthExpiresAt,
            'payment_token'      => $this->paymentToken,
            'payment_expires_at' => $this->paymentExpiresAt,
            'cached_at'          => time(),
        ];

        file_put_contents($path, json_encode($data), LOCK_EX);
        @chmod($path, 0600);
    }

    private function extractStatusCode(array $headers): int
    {
        $code = 0;
        foreach ($headers as $header) {
            if (preg_match('/HTTP\/[\d.]+ (\d+)/', $header, $m)) {
                $code = (int)$m[1];
            }
        }
        return $code;
    }
}
