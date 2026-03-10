<?php

declare(strict_types=1);

namespace RevAddress\USPSv3;

use RevAddress\USPSv3\Exception\AuthException;
use RevAddress\USPSv3\Exception\ValidationException;

/**
 * USPS v3 REST API Client for PHP.
 *
 * Drop-in replacement for retired USPS Web Tools XML API.
 * Handles OAuth 2.0, Payment Authorization, token caching, and multipart label parsing.
 *
 * Basic usage:
 *   $usps = new \RevAddress\USPSv3\Client('your-client-id', 'your-client-secret');
 *   $result = $usps->validateAddress(['streetAddress' => '1600 Pennsylvania Ave NW', ...]);
 *
 * With label creation (requires BCG enrollment):
 *   $usps = new \RevAddress\USPSv3\Client('id', 'secret', crid: '12345', masterMid: '67890', ...);
 *   $label = $usps->createLabel([...]);
 *
 * @package RevAddress\USPSv3
 * @version 1.0.0
 * @see https://revaddress.com/docs
 */
class Client
{
    private TokenManager $tokens;
    private Http $http;

    // Valid domestic mail classes
    public const MAIL_CLASSES = [
        'PRIORITY_MAIL_EXPRESS',
        'PRIORITY_MAIL',
        'FIRST-CLASS_PACKAGE_SERVICE',
        'PARCEL_SELECT',
        'LIBRARY_MAIL',
        'MEDIA_MAIL',
        'BOUND_PRINTED_MATTER',
        'USPS_GROUND_ADVANTAGE',
    ];

    /**
     * @param string      $clientId     USPS OAuth Client ID (from developers.usps.com)
     * @param string      $clientSecret USPS OAuth Client Secret
     * @param string|null $cacheDir     Token cache directory (default: sys_get_temp_dir())
     * @param string|null $crid         Customer Registration ID (for labels)
     * @param string|null $masterMid    Master Mailer ID (for payment auth)
     * @param string|null $labelMid     Label Mailer ID (defaults to masterMid)
     * @param string|null $epaAccount   Enterprise Payment System account number
     * @param int         $timeout      HTTP timeout in seconds
     */
    public function __construct(
        string  $clientId,
        string  $clientSecret,
        ?string $cacheDir   = null,
        ?string $crid       = null,
        ?string $masterMid  = null,
        ?string $labelMid   = null,
        ?string $epaAccount = null,
        int     $timeout    = 30,
    ) {
        $this->tokens = new TokenManager(
            $clientId,
            $clientSecret,
            $cacheDir,
            $crid,
            $masterMid,
            $labelMid,
            $epaAccount,
        );
        $this->http = new Http($this->tokens, $timeout);
    }

    // ── Addresses ──────────────────────────────────────────────────

    /**
     * Validate and standardize a US address.
     *
     * @param array $address Keys: streetAddress, secondaryAddress, city, state, ZIPCode
     * @return array Standardized address with DPV confirmation
     */
    public function validateAddress(array $address): array
    {
        $query = http_build_query(array_filter([
            'streetAddress'    => $address['streetAddress'] ?? $address['street'] ?? '',
            'secondaryAddress' => $address['secondaryAddress'] ?? $address['apt'] ?? '',
            'city'             => $address['city'] ?? '',
            'state'            => $address['state'] ?? '',
            'ZIPCode'          => $address['ZIPCode'] ?? $address['zip'] ?? '',
        ]));

        return $this->http->get("/addresses/v3/address?{$query}");
    }

    /**
     * Look up city and state from a ZIP code.
     */
    public function cityStateLookup(string $zipCode): array
    {
        return $this->http->get("/addresses/v3/city-state?ZIPCode={$zipCode}");
    }

    // ── Tracking ───────────────────────────────────────────────────

    /**
     * Get tracking information for a package.
     *
     * @param string $trackingNumber USPS tracking number
     * @param string $expand         'DETAIL' for full history, 'SUMMARY' for latest only
     */
    public function trackPackage(string $trackingNumber, string $expand = 'DETAIL'): array
    {
        $encoded = urlencode($trackingNumber);
        return $this->http->get("/tracking/v3/tracking/{$encoded}?expand={$expand}");
    }

    // ── Rates & Pricing ────────────────────────────────────────────

    /**
     * Get domestic shipping rates.
     *
     * @param array $params Keys: originZIPCode, destinationZIPCode, weight (lbs),
     *                      mailClass, processingCategory, rateIndicator, priceType
     */
    public function getDomesticPrices(array $params): array
    {
        return $this->http->post('/prices/v3/total-rates/search', $params);
    }

    /**
     * Get international shipping rates.
     *
     * @param array $params Keys: originZIPCode, destinationCountryCode (ISO 3166-1 alpha-2),
     *                      weight, mailClass, priceType
     */
    public function getInternationalPrices(array $params): array
    {
        return $this->http->post('/international-prices/v3/total-rates/search', $params);
    }

    // ── Labels ─────────────────────────────────────────────────────

    /**
     * Create a domestic shipping label.
     *
     * Requires USPS Business Customer Gateway enrollment:
     * - COP claims linking at cop.usps.com
     * - CRID, Master MID, Label MID, EPA account set in constructor
     *
     * @param array       $fromAddress    Sender address
     * @param array       $toAddress      Recipient address
     * @param string      $mailClass      e.g. 'PRIORITY_MAIL', 'USPS_GROUND_ADVANTAGE'
     * @param float       $weight         Package weight in pounds
     * @param string      $imageType      'PDF' or 'PNG'
     * @param string      $labelType      '4X6LABEL' (default)
     * @param string      $rateIndicator  'SP' (single piece), 'DR' (dimensional)
     * @param string|null $idempotencyKey Deduplication key
     * @return array Metadata with trackingNumber, postage, zone, labelData (PDF/PNG bytes)
     */
    public function createLabel(
        array   $fromAddress,
        array   $toAddress,
        string  $mailClass,
        float   $weight,
        string  $imageType      = 'PDF',
        string  $labelType      = '4X6LABEL',
        string  $rateIndicator  = 'SP',
        ?string $idempotencyKey = null,
    ): array {
        if (!$fromAddress) {
            throw new ValidationException('fromAddress is required', 'fromAddress');
        }
        if (!$toAddress) {
            throw new ValidationException('toAddress is required', 'toAddress');
        }
        if (!in_array($mailClass, self::MAIL_CLASSES, true)) {
            throw new ValidationException(
                "Invalid mailClass '{$mailClass}'. Valid: " . implode(', ', self::MAIL_CLASSES),
                'mailClass'
            );
        }

        if (!$this->tokens->hasPaymentCredentials()) {
            throw new AuthException(
                'Label creation requires BYOK credentials (crid, masterMid, labelMid, epaAccount). '
                . 'Set them in the Client constructor.'
            );
        }

        $labelRequest = [
            'imageInfo' => [
                'imageType'       => $imageType,
                'labelType'       => $labelType,
                'receiptOption'   => 'NONE',
                'suppressPostage' => false,
            ],
            'toAddress'   => $toAddress,
            'fromAddress' => $fromAddress,
            'packageDescription' => [
                'mailClass'          => $mailClass,
                'rateIndicator'      => $rateIndicator,
                'weightUOM'          => 'lb',
                'weight'             => $weight,
                'processingCategory' => 'MACHINABLE',
                'mailingDate'        => date('Y-m-d'),
                'destinationEntryFacilityType' => 'NONE',
            ],
        ];

        return $this->http->postLabel('/labels/v3/label', $labelRequest, $idempotencyKey);
    }

    /**
     * Void/refund a label by tracking number.
     */
    public function voidLabel(string $trackingNumber): array
    {
        if (!$trackingNumber) {
            throw new ValidationException('trackingNumber is required', 'trackingNumber');
        }
        return $this->http->delete('/labels/v3/label/' . urlencode($trackingNumber));
    }

    // ── Service Standards ──────────────────────────────────────────

    /**
     * Get delivery time estimates between two ZIP codes.
     */
    public function getServiceStandards(
        string  $originZIP,
        string  $destZIP,
        string  $mailClass  = 'USPS_GROUND_ADVANTAGE',
        ?string $acceptDate = null,
    ): array {
        $params = http_build_query([
            'originZIPCode'      => $originZIP,
            'destinationZIPCode' => $destZIP,
            'mailClass'          => $mailClass,
            'acceptanceDate'     => $acceptDate ?? date('Y-m-d'),
        ]);
        return $this->http->get("/service-standards/v3/estimates?{$params}");
    }

    // ── Locations ──────────────────────────────────────────────────

    /**
     * Find USPS post offices and drop-off locations near a ZIP code.
     */
    public function getLocations(string $zipCode, int $radius = 10, string $type = 'PO'): array
    {
        $params = http_build_query([
            'ZIPCode' => $zipCode,
            'radius'  => $radius,
            'type'    => $type,
        ]);
        return $this->http->get("/locations/v3/post-office-locator?{$params}");
    }

    // ── Carrier Pickup ─────────────────────────────────────────────

    /**
     * Schedule a carrier pickup.
     */
    public function schedulePickup(array $pickupData): array
    {
        return $this->http->post('/pickup/v3/carrier-pickup', $pickupData);
    }

    /**
     * Cancel a scheduled carrier pickup.
     */
    public function cancelPickup(string $confirmationNumber): array
    {
        return $this->http->delete('/pickup/v3/carrier-pickup/' . urlencode($confirmationNumber));
    }

    // ── Diagnostics ────────────────────────────────────────────────

    /**
     * Get current token status (for debugging/health checks).
     */
    public function tokenStatus(): array
    {
        return $this->tokens->status();
    }

    /**
     * Force refresh all tokens.
     */
    public function refreshTokens(): array
    {
        return $this->tokens->forceRefresh();
    }

    /**
     * Get the underlying TokenManager (for advanced use).
     */
    public function getTokenManager(): TokenManager
    {
        return $this->tokens;
    }
}
