<?php

declare(strict_types=1);

namespace RevAddress\USPSv3;

use RevAddress\USPSv3\Exception\RateLimitException;
use RevAddress\USPSv3\Exception\USPSException;

/**
 * HTTP transport for USPS v3 API calls.
 *
 * Uses PHP's built-in stream_context_create + file_get_contents.
 * Zero external dependencies — works in any PHP 8.0+ environment.
 */
class Http
{
    private const BASE_URL = 'https://apis.usps.com';

    private TokenManager $tokens;
    private int $timeout;

    public function __construct(TokenManager $tokens, int $timeout = 30)
    {
        $this->tokens  = $tokens;
        $this->timeout = $timeout;
    }

    /**
     * Authenticated GET request.
     */
    public function get(string $path): array
    {
        $url   = $this->resolveUrl($path);
        $token = $this->tokens->getOAuthToken();

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "Authorization: Bearer {$token}\r\nAccept: application/json",
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $body    = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];

        return $this->handleJsonResponse($body ?: '', $headers);
    }

    /**
     * Authenticated POST request (JSON body).
     */
    public function post(string $path, array $data): array
    {
        $url   = $this->resolveUrl($path);
        $token = $this->tokens->getOAuthToken();

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    "Authorization: Bearer {$token}",
                ]),
                'content'       => json_encode($data),
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $body    = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];

        return $this->handleJsonResponse($body ?: '', $headers);
    }

    /**
     * Authenticated POST for label creation (handles multipart response + Payment Auth).
     */
    public function postLabel(string $path, array $data, ?string $idempotencyKey = null): array
    {
        $url    = $this->resolveUrl($path);
        $tokens = $this->tokens->getBothTokens();

        $headerLines = [
            'Content-Type: application/json',
            'Accept: application/json',
            "Authorization: Bearer {$tokens['oauth']}",
            "X-Payment-Authorization-Token: {$tokens['payment']}",
        ];
        if ($idempotencyKey) {
            $headerLines[] = "Idempotency-Key: {$idempotencyKey}";
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headerLines),
                'content'       => json_encode($data),
                'timeout'       => 60,
                'ignore_errors' => true,
            ],
        ]);

        $body    = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];

        $statusCode = self::extractStatusCode($headers);
        $this->checkForErrors($statusCode, $body ?: '', $headers);

        // Check for multipart response
        $contentType = self::extractHeader($headers, 'Content-Type');
        if ($contentType && stripos($contentType, 'multipart') !== false) {
            return self::parseMultipart($body ?: '', $contentType);
        }

        return json_decode($body ?: '{}', true) ?? [];
    }

    /**
     * Authenticated DELETE request.
     */
    public function delete(string $path): array
    {
        $url   = $this->resolveUrl($path);
        $token = $this->tokens->getOAuthToken();

        $context = stream_context_create([
            'http' => [
                'method'        => 'DELETE',
                'header'        => "Authorization: Bearer {$token}\r\nAccept: application/json",
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $body    = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];

        $statusCode = self::extractStatusCode($headers);
        $this->checkForErrors($statusCode, $body ?: '', $headers);

        if ($body) {
            return json_decode($body, true) ?? [];
        }
        return ['status' => 'success'];
    }

    // ── Internal ───────────────────────────────────────────────────

    private function resolveUrl(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : self::BASE_URL . $path;
    }

    private function handleJsonResponse(string $body, array $headers): array
    {
        $statusCode = self::extractStatusCode($headers);
        $this->checkForErrors($statusCode, $body, $headers);
        return json_decode($body ?: '{}', true) ?? [];
    }

    private function checkForErrors(int $statusCode, string $body, array $headers): void
    {
        if ($statusCode === 429) {
            $retryAfter = null;
            $retryHeader = self::extractHeader($headers, 'Retry-After');
            if ($retryHeader !== null) {
                $retryAfter = (int)$retryHeader;
            }
            throw new RateLimitException($retryAfter);
        }

        if ($statusCode >= 400) {
            $decoded = json_decode($body, true) ?? [];
            $message = $decoded['error']['message']
                ?? $decoded['message']
                ?? $decoded['error']
                ?? "HTTP {$statusCode}";
            throw new USPSException((string)$message, $statusCode, $decoded);
        }
    }

    public static function extractStatusCode(array $headers): int
    {
        $code = 0;
        foreach ($headers as $header) {
            if (preg_match('/HTTP\/[\d.]+ (\d+)/', $header, $m)) {
                $code = (int)$m[1];
            }
        }
        return $code;
    }

    public static function extractHeader(array $headers, string $name): ?string
    {
        $prefix = strtolower($name) . ':';
        foreach ($headers as $header) {
            if (stripos($header, $prefix) === 0) {
                return trim(substr($header, strlen($prefix)));
            }
        }
        return null;
    }

    /**
     * Parse a USPS multipart response into metadata + label bytes.
     */
    public static function parseMultipart(string $rawBody, string $contentType): array
    {
        $boundary = null;
        foreach (explode(';', $contentType) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'boundary=')) {
                $boundary = trim(substr($part, 9), '"');
                break;
            }
        }

        if (!$boundary) {
            return json_decode($rawBody, true) ?? [];
        }

        $parts    = explode("--{$boundary}", $rawBody);
        $metadata = [];
        $labelData = null;

        foreach ($parts as $part) {
            if (!$part || trim($part) === '--' || trim($part) === '') {
                continue;
            }

            $bodyStart = strpos($part, "\r\n\r\n");
            if ($bodyStart !== false) {
                $headerSection = substr($part, 0, $bodyStart);
                $body          = substr($part, $bodyStart + 4);
            } else {
                $bodyStart = strpos($part, "\n\n");
                if ($bodyStart === false) continue;
                $headerSection = substr($part, 0, $bodyStart);
                $body          = substr($part, $bodyStart + 2);
            }

            $headerLower = strtolower($headerSection);

            if (str_contains($headerLower, 'labelmetadata') || str_contains($headerLower, 'application/json')) {
                $parsed = json_decode(trim($body), true);
                if (is_array($parsed)) {
                    $metadata = $parsed;
                }
            } elseif (str_contains($headerLower, 'labelimage') || str_contains($headerLower, 'application/pdf') || str_contains($headerLower, 'image/png')) {
                $labelData = trim($body);
            }
        }

        $result = $metadata;
        if ($labelData !== null) {
            $result['labelData'] = $labelData;
        }
        return $result;
    }
}
