# XfixPay Pesapal SDK Documentation

## Overview

This module provides Pesapal payment gateway integration for the XfixPay SDK. All Pesapal-specific logic is isolated under `src/Pesapal/`.

## Method Mapping

| XfixPay Method                                                                                     | Pesapal Endpoint                       | HTTP | Purpose                              |
| -------------------------------------------------------------------------------------------------- | -------------------------------------- | ---- | ------------------------------------ |
| `registerIPN($ipnUrl)`                                                                           | `/URLSetup/RegisterIPN`              | POST | Register IPN URL, get ipn_id         |
| `submitOrder($amount, $currency, $description, $callbackUrl, $billingAddress, $deliveryAddress)` | `/Transactions/SubmitOrderRequest`   | POST | Create payment, get redirect_url     |
| `getTransactionStatus($orderTrackingId)`                                                         | `/Transactions/GetTransactionStatus` | GET  | Verify payment outcome               |
| `requestRefund($orderTrackingId, $amount, $remarks)`                                             | `/Transactions/RefundRequest`        | POST | Request refund for completed payment |
| `cancelOrder($orderTrackingId, $remarks)`                                                        | `/Transactions/CancelOrder`          | POST | Cancel pending/failed order          |

## Configuration

```php
use Xfix\XfixPay\Pesapal\PesapalGateway;
use Xfix\XfixPay\Pesapal\PesapalConfig;

$config = new PesapalConfig([
    'consumer_key' => 'your_consumer_key',
    'consumer_secret' => 'your_consumer_secret',
    'environment' => 'sandbox', // or 'live'
]);

$gateway = new PesapalGateway();
$gateway->setConfig($config->getConfig());
```

## Usage Example

```php
use Xfix\XfixPay\XfixPayClient;

$client = new XfixPayClient();
$pesapal = $client->pesapal([
    'consumer_key' => 'your_consumer_key',
    'consumer_secret' => 'your_consumer_secret',
    'environment' => 'sandbox',
]);

// Register IPN URL
$response = $pesapal->registerIPN('https://example.com/ipn-handler');
$ipnId = $response['ipn_id'] ?? null;

// Submit order
$response = $pesapal->submitOrder(
    100.00,       // amount
    'KES',        // currency
    'Order #123', // description
    'https://example.com/callback', // callback URL
    'Customer Billing', // billing address
    'Customer Delivery' // delivery address
);
$redirectUrl = $response['redirect_url'] ?? null;
$orderTrackingId = $response['order_tracking_id'] ?? null;

// Check transaction status
$status = $pesapal->getTransactionStatus($orderTrackingId);

// Request refund
$refund = $pesapal->requestRefund($orderTrackingId, 100.00, 'Customer requested refund');

// Cancel order
$cancel = $pesapal->cancelOrder($orderTrackingId, 'Order cancelled by customer');
```

## Authentication

- Token generation via `/api/Auth/RequestToken`
- Bearer token with 5-minute TTL cache buffer
- Automatic token caching and refresh

## Gateway Factory

Use the XfixPay client to access the Pesapal gateway:

```php
$client = new XfixPayClient();
$pesapal = $client->pesapal($config);
```

All methods return decoded JSON arrays from the Pesapal API responses.

## Notes

- Base URLs are configured in `PesapalConfig`:
  - Sandbox: `https://cybqa.pesapal.com/pesapalv3/api`
  - Live: `https://pay.pesapal.com/v3/api`
- Endpoint paths do not include `/api/` prefix as the base URL already includes it
- All methods throw `Xfix\XfixPay\Exception\PesapalException` on API errors
