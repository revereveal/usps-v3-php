# usps-v3-php

[![Tests](https://github.com/revereveal/usps-v3-php/actions/workflows/tests.yml/badge.svg)](https://github.com/revereveal/usps-v3-php/actions/workflows/tests.yml)

PHP client for the USPS v3 REST API. Drop-in replacement for the retired Web Tools XML API.

**Zero dependencies.** PHP 8.0+ with `ext-json` and `ext-openssl`. Works in WooCommerce, Magento, Laravel, Symfony, or standalone. **59 tests, 102 assertions.**

## Install

```bash
composer require revaddress/usps-v3-php
```

## Quick Start

```php
use RevAddress\USPSv3\Client;

$usps = new Client('your-client-id', 'your-client-secret');

// Validate an address
$result = $usps->validateAddress([
    'streetAddress' => '1600 Pennsylvania Ave NW',
    'city'          => 'Washington',
    'state'         => 'DC',
    'ZIPCode'       => '20500',
]);

echo $result['address']['streetAddress']; // "1600 PENNSYLVANIA AVE NW"
echo $result['address']['DPVConfirmation']; // "Y"
```

## Features

| Feature | Web Tools (retired) | This SDK |
|---------|-------------------|----------|
| Auth | USERID query param | OAuth 2.0 (auto-cached) |
| Format | XML | JSON |
| Rate limit | Unlimited | 60/hr (increasable) |
| Payment Auth | N/A | Two-token lifecycle |
| Label parsing | XML + base64 | Multipart auto-parsed |

## API Coverage

```php
// Addresses
$usps->validateAddress([...]);
$usps->cityStateLookup('20500');

// Tracking
$usps->trackPackage('9400111899223033005282');

// Rates
$usps->getDomesticPrices([
    'originZIPCode'      => '10001',
    'destinationZIPCode' => '90210',
    'weight'             => 2.5,
    'mailClass'          => 'PRIORITY_MAIL',
    'processingCategory' => 'MACHINABLE',
    'rateIndicator'      => 'DR',
    'priceType'          => 'RETAIL',
]);
$usps->getInternationalPrices([...]);

// Service standards
$usps->getServiceStandards('10001', '90210', 'PRIORITY_MAIL');

// Locations
$usps->getLocations('10001', radius: 5);

// Carrier pickup
$usps->schedulePickup([...]);
$usps->cancelPickup('confirmation-number');
```

## Label Creation

Labels require USPS Business Customer Gateway enrollment (CRID, MIDs, EPS account) and COP claims linking.

```php
$usps = new Client(
    'client-id',
    'client-secret',
    crid:       '56982563',
    masterMid:  '904128936',
    labelMid:   '904128937',
    epaAccount: 'your-eps-account',
);

$label = $usps->createLabel(
    fromAddress: [
        'firstName' => 'RevAddress',
        'streetAddress' => '228 Park Ave S',
        'city' => 'New York',
        'state' => 'NY',
        'ZIPCode' => '10003',
    ],
    toAddress: [
        'firstName' => 'Jane',
        'lastName' => 'Doe',
        'streetAddress' => '1600 Pennsylvania Ave NW',
        'city' => 'Washington',
        'state' => 'DC',
        'ZIPCode' => '20500',
    ],
    mailClass: 'PRIORITY_MAIL',
    weight: 2.5,
);

echo $label['trackingNumber'];
file_put_contents('label.pdf', $label['labelData']); // PDF bytes
```

## Token Management

OAuth tokens (8h lifetime) are cached to disk automatically with 30-minute expiry buffer. Payment Authorization tokens follow the same lifecycle.

```php
// Check token status
$status = $usps->tokenStatus();
// ['oauth' => ['valid' => true, 'ttl_seconds' => 25200], 'payment' => [...]]

// Force refresh
$usps->refreshTokens();

// Custom cache directory
$usps = new Client('id', 'secret', cacheDir: '/var/cache/usps');
```

## Error Handling

```php
use RevAddress\USPSv3\Exception\USPSException;
use RevAddress\USPSv3\Exception\AuthException;
use RevAddress\USPSv3\Exception\RateLimitException;
use RevAddress\USPSv3\Exception\ValidationException;

try {
    $result = $usps->validateAddress([...]);
} catch (RateLimitException $e) {
    // 429 — wait and retry
    $retryAfter = $e->getRetryAfter(); // seconds, or null
    sleep($retryAfter ?? 60);
} catch (AuthException $e) {
    // OAuth or Payment Auth failure
    $usps->refreshTokens();
} catch (ValidationException $e) {
    // Bad input
    echo $e->getField(); // which field failed
} catch (USPSException $e) {
    // All other USPS errors
    if ($e->isRetryable()) {
        // 500, 502, 503, 504 — transient
    }
    $body = $e->getResponseBody(); // raw USPS error response
}
```

## Migration from Web Tools

| Web Tools | v3 (this SDK) |
|-----------|---------------|
| `Address2` (street) | `streetAddress` |
| `Address1` (apt) | `secondaryAddress` |
| `<TrackID>` XML body | Path param (automatic) |
| `RateV4` GET all classes | `getDomesticPrices()` per class |
| Country name "Canada" | ISO alpha-2 "CA" |
| No auth token | OAuth auto-managed |

See [revaddress.com/blog/usps-web-tools-shutdown-2026](https://revaddress.com/blog/usps-web-tools-shutdown-2026) for the full migration guide.

## Platform Guides

- [WooCommerce USPS Migration](https://revaddress.com/blog/woocommerce-usps-migration)
- [Magento AC-15210 Fix](https://revaddress.com/blog/magento-usps-ac15210-fix)
- [USPS OAuth Troubleshooting](https://revaddress.com/blog/usps-oauth-troubleshooting)

## Testing

```bash
composer install
vendor/bin/phpunit --testdox
```

59 tests covering: Client validation, TokenManager cache lifecycle, Http multipart parsing, exception hierarchy and retryability. CI runs PHP 8.0–8.4.

## Requirements

- PHP 8.0+
- `ext-json`
- `ext-openssl` (for HTTPS)
- USPS Developer Portal account ([developers.usps.com](https://developers.usps.com))

## License

MIT
