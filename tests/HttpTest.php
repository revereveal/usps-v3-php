<?php

declare(strict_types=1);

namespace RevAddress\USPSv3\Tests;

use PHPUnit\Framework\TestCase;
use RevAddress\USPSv3\Http;

/**
 * Tests for Http utility methods (no network calls).
 *
 * Covers: status code extraction, header extraction, multipart parsing.
 */
class HttpTest extends TestCase
{
    // ── extractStatusCode ────────────────────────────────────────

    public function testExtractStatusCode200(): void
    {
        $headers = ['HTTP/1.1 200 OK', 'Content-Type: application/json'];
        $this->assertSame(200, Http::extractStatusCode($headers));
    }

    public function testExtractStatusCode404(): void
    {
        $headers = ['HTTP/1.1 404 Not Found'];
        $this->assertSame(404, Http::extractStatusCode($headers));
    }

    public function testExtractStatusCode429(): void
    {
        $headers = ['HTTP/1.1 429 Too Many Requests'];
        $this->assertSame(429, Http::extractStatusCode($headers));
    }

    public function testExtractStatusCodeRedirectChain(): void
    {
        // PHP's $http_response_header includes all redirects — last one wins
        $headers = [
            'HTTP/1.1 301 Moved Permanently',
            'Location: https://apis.usps.com/new-path',
            'HTTP/1.1 200 OK',
            'Content-Type: application/json',
        ];
        $this->assertSame(200, Http::extractStatusCode($headers));
    }

    public function testExtractStatusCodeEmptyHeaders(): void
    {
        $this->assertSame(0, Http::extractStatusCode([]));
    }

    public function testExtractStatusCodeHttp2(): void
    {
        $headers = ['HTTP/2 201 Created'];
        $this->assertSame(201, Http::extractStatusCode($headers));
    }

    // ── extractHeader ────────────────────────────────────────────

    public function testExtractHeaderContentType(): void
    {
        $headers = [
            'HTTP/1.1 200 OK',
            'Content-Type: application/json; charset=utf-8',
            'X-Request-Id: abc123',
        ];
        $this->assertSame(
            'application/json; charset=utf-8',
            Http::extractHeader($headers, 'Content-Type')
        );
    }

    public function testExtractHeaderCaseInsensitive(): void
    {
        $headers = ['content-type: text/plain'];
        $this->assertSame('text/plain', Http::extractHeader($headers, 'Content-Type'));
    }

    public function testExtractHeaderRetryAfter(): void
    {
        $headers = [
            'HTTP/1.1 429 Too Many Requests',
            'Retry-After: 3600',
        ];
        $this->assertSame('3600', Http::extractHeader($headers, 'Retry-After'));
    }

    public function testExtractHeaderMissing(): void
    {
        $headers = ['HTTP/1.1 200 OK'];
        $this->assertNull(Http::extractHeader($headers, 'X-Missing'));
    }

    // ── parseMultipart ───────────────────────────────────────────

    public function testParseMultipartLabelResponse(): void
    {
        $boundary = '----=_Part_12345';
        $contentType = "multipart/mixed; boundary=\"{$boundary}\"";

        $metadata = json_encode([
            'trackingNumber'     => '9400111899223033005282',
            'postage'            => 8.55,
            'zone'               => '4',
            'routingBarcode'     => '420205009400111899223033005282',
        ]);

        $rawBody = implode("\r\n", [
            "--{$boundary}",
            "Content-Type: application/json",
            "Content-Disposition: form-data; name=\"labelMetadata\"",
            "",
            $metadata,
            "--{$boundary}",
            "Content-Type: application/pdf",
            "Content-Disposition: form-data; name=\"labelImage\"",
            "",
            "%PDF-1.4-FAKE-BINARY-DATA",
            "--{$boundary}--",
        ]);

        $result = Http::parseMultipart($rawBody, $contentType);

        $this->assertSame('9400111899223033005282', $result['trackingNumber']);
        $this->assertSame(8.55, $result['postage']);
        $this->assertSame('4', $result['zone']);
        $this->assertSame('%PDF-1.4-FAKE-BINARY-DATA', $result['labelData']);
    }

    public function testParseMultipartPngLabel(): void
    {
        $boundary = 'boundary_xyz';
        $contentType = "multipart/mixed; boundary={$boundary}";

        $rawBody = implode("\r\n", [
            "--{$boundary}",
            "Content-Type: application/json",
            "Content-Disposition: form-data; name=\"labelMetadata\"",
            "",
            '{"trackingNumber":"EX123456789US","postage":25.50}',
            "--{$boundary}",
            "Content-Type: image/png",
            "Content-Disposition: form-data; name=\"labelImage\"",
            "",
            "PNG-BINARY-CONTENT-HERE",
            "--{$boundary}--",
        ]);

        $result = Http::parseMultipart($rawBody, $contentType);

        $this->assertSame('EX123456789US', $result['trackingNumber']);
        $this->assertSame(25.50, $result['postage']);
        $this->assertSame('PNG-BINARY-CONTENT-HERE', $result['labelData']);
    }

    public function testParseMultipartNoBoundary(): void
    {
        $body = '{"fallback": true}';
        $result = Http::parseMultipart($body, 'application/json');

        $this->assertSame(['fallback' => true], $result);
    }

    public function testParseMultipartLfOnly(): void
    {
        // Some servers send LF instead of CRLF
        $boundary = 'test-boundary';
        $contentType = "multipart/mixed; boundary={$boundary}";

        $rawBody = implode("\n", [
            "--{$boundary}",
            "Content-Type: application/json",
            "Content-Disposition: form-data; name=\"labelMetadata\"",
            "",
            '{"trackingNumber":"LF123"}',
            "--{$boundary}",
            "Content-Type: application/pdf",
            "Content-Disposition: form-data; name=\"labelImage\"",
            "",
            "PDF-LF-DATA",
            "--{$boundary}--",
        ]);

        $result = Http::parseMultipart($rawBody, $contentType);

        $this->assertSame('LF123', $result['trackingNumber']);
        $this->assertSame('PDF-LF-DATA', $result['labelData']);
    }

    public function testParseMultipartMetadataOnly(): void
    {
        $boundary = 'meta-only';
        $contentType = "multipart/mixed; boundary={$boundary}";

        $rawBody = implode("\r\n", [
            "--{$boundary}",
            "Content-Type: application/json",
            "",
            '{"status":"created"}',
            "--{$boundary}--",
        ]);

        $result = Http::parseMultipart($rawBody, $contentType);

        $this->assertSame('created', $result['status']);
        $this->assertArrayNotHasKey('labelData', $result);
    }
}
