# XfixPay Pesapal SDK Documentation

## Overview

This module provides Pesapal payment gateway integration for the XfixPay SDK. All Pesapal-specific logic is isolated under `src/Pesapal/`.

## Method Mapping

| XfixPay Method                                                                                     | Pesapal Endpoint                       | HTTP | Purpose                              |
| -------------------------------------------------------------------------------------------------- | -------------------------------------- | ---- | ------------------------------------ |
| `registerIPN($ipnUrl)`                                                                           | `/URLSetup/RegisterIPN`              | POST | Register IPN URL, get `ipn_id`         |
| `submitOrderRequest($merchantReference, $amount, $currency, $description, $callbackUrl, $notificationId, $billingAddress)` | `/Transactions/SubmitOrderRequest`   | POST | Create payment, get `redirect_url`     |
| `getTransactionStatus($orderTrackingId)`                                                         | `/Transactions/GetTransactionStatus` | GET  | Verify payment outcome               |
| `requestRefund($orderTrackingId, $amount, $remarks)`                                             | `/Transactions/RefundRequest`        | POST | Request refund for completed payment |
| `cancelOrder($orderTrackingId, $remarks)`                                                        | `/Transactions/CancelOrder`          | POST | Cancel pending/failed order          |
| ~~`submitOrder(...)`~~                                                                             | —                                      | —    | **Deprecated** — throws `PesapalException`. Use `submitOrderRequest()`. |

## Configuration

```php
use Xfix\XfixPay\Pesapal\PesapalGateway;
use Xfix\XfixPay\Pesapal\PesapalConfig;

$config = new PesapalConfig([
    'consumer_key'    => 'your_consumer_key',
    'consumer_secret' => 'your_consumer_secret',
    'environment'     => 'sandbox', // or 'live'
]);

$gateway = new PesapalGateway();
$gateway->setConfig($config->getConfig());
```

## Usage Example

```php
use Xfix\XfixPay\XfixPayClient;
use Xfix\XfixPay\Exception\PesapalException;

$client = new XfixPayClient();
$pesapal = $client->pesapal([
    'consumer_key'    => 'your_consumer_key',
    'consumer_secret' => 'your_consumer_secret',
    'environment'     => 'sandbox',
]);

// 1. Register IPN URL — required before submitting an order.
$response = $pesapal->registerIPN('https://example.com/ipn-handler');
$ipnId = $response['ipn_id'] ?? null;

if (!$ipnId) {
    throw new \RuntimeException('Failed to obtain Pesapal IPN ID.');
}

// 2. Submit order — requires a unique merchant reference and the ipn_id.
$response = $pesapal->submitOrderRequest(
    merchantReference: 'INV-' . time(), // Must be unique per order
    amount: 100.00,
    currency: 'KES',
    description: 'Order #123',
    callbackUrl: 'https://example.com/callback',
    notificationId: $ipnId, // Required
    billingAddress: [
        'email_address' => 'customer@example.com',
        'phone_number'  => '254722336262',
        'country_code'  => 'KE',
    ]
);
$redirectUrl     = $response['redirect_url'] ?? null;
$orderTrackingId = $response['order_tracking_id'] ?? null;

// 3. Check transaction status — defensive, handles error envelopes.
$verification = $pesapal->getTransactionStatus($orderTrackingId);

$errorMessage = $verification['error']['message']    ?? null;
$errorCode    = $verification['error']['code']       ?? null;
$errorType    = $verification['error']['error_type'] ?? null;

$hasError = $errorMessage || $errorCode || $errorType;

if ($hasError || empty($verification['payment_status_description'])) {
    throw new PesapalException(
        'Pesapal status verification failed: '
        . ($errorMessage ?: $verification['message'] ?? 'Unknown error')
    );
}

if ($verification['payment_status_description'] === 'Completed') {
    // Mark the order as paid, fulfil, etc.
}

// 4. Request refund (for completed payments).
$refund = $pesapal->requestRefund($orderTrackingId, 100.00, 'Customer requested refund');

// 5. Cancel order (pending/failed orders).
$cancel = $pesapal->cancelOrder($orderTrackingId, 'Order cancelled by customer');
```

## Deprecation: `submitOrder()`

The legacy `submitOrder()` method is **deprecated** and will throw a `PesapalException` when called. It is retained only for backward-compatibility signalling; it does not perform a request.

Use `submitOrderRequest()` instead. The new method:

- Requires a unique **`merchantReference`** per order (sent as the order `id` to Pesapal).
- Requires a **`notificationId`** (the `ipn_id` returned by `registerIPN()`), which Pesapal now mandates on order submission.
- Accepts a structured **`billingAddress`** array (`email_address`, `phone_number`, `country_code`) rather than a free-form string.

## Transaction Status Verification

`getTransactionStatus()` returns the raw decoded JSON from Pesapal — it does **not** normalise the response. Callers must therefore handle two shapes defensively:

- **Success envelope** — contains `payment_status_description` (e.g. `Completed`, `Pending`, `Failed`). This is the authoritative status field; there is no top-level `status` key.
- **Error envelope** — contains an `error` object with `message`, `code`, and `error_type`. Any non-null value (or the presence of the envelope when the inner status is absent) indicates a failure. Inner fields may be `null`; do not dereference them blindly.

See the defensive pattern in the usage example above.

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
- `getTransactionStatus()` may also return a well-formed **error envelope** (HTTP 200) rather than throwing — always apply the defensive status check shown above before treating a payment as successful