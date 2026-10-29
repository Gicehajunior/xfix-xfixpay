# XfixPay PHP SDK

A unified PHP SDK for integrating with multiple payment gateways through a consistent, extensible API.

XfixPay currently supports **M-Pesa in Kenya** and **Pesapal payment gateway**, with additional payment providers planned, including PayPal, Stripe, bank transfers, and other payment services.

## Features

* Unified interface for multiple payment gateways
* M-Pesa STK Push
* STK Push status queries
* C2B URL registration
* B2C payments
* Account balance inquiries
* Transaction reversals
* Automatic M-Pesa OAuth token management and caching
* Automatic encryption of Safaricom security credentials
* Pesapal payment gateway integration (API 3.0)
* Pesapal IPN registration, order submission, status, refunds, and cancellations
* Defensive Pesapal status verification (handles error envelopes and null inner fields)
* PSR-4 autoloading
* PSR-12 compatible codebase
* Extensible gateway architecture

---

## Requirements

* PHP 7.4 or higher
* Composer
* GuzzleHTTP 7.x or higher
* OpenSSL PHP extension
* Safaricom Developer account for M-Pesa integrations
* Safaricom M-Pesa security certificate (`.cer`) for B2C, Balance Inquiry, and Reversal APIs
* Pesapal merchant account for Pesapal integrations

---

## Installation

Install XfixPay using Composer:

```bash
composer require xfix/xfixpay
```

Composer will automatically install the required dependencies.

---

## Configuration

XfixPay uses a shared client configuration while allowing each gateway to define its own credentials and settings.

### Global Configuration

```php
use Xfix\XfixPay\XfixPayClient;

$client = new XfixPayClient([
    'environment' => 'sandbox', // sandbox or live
    'timeout' => 30,
]);
```

The global configuration can be shared across gateways.

### M-Pesa Configuration

```php
$mpesa = $client->mpesa([
    'consumer_key'       => 'your_consumer_key',
    'consumer_secret'    => 'your_consumer_secret',
    'passkey'            => 'your_passkey',
    'shortcode'          => '174379',
    'initiator'          => 'apitest',
    'initiator_password' => 'your_initiator_password',
    'environment'        => 'sandbox',
    'cert_path'          => '/path/to/safaricom.cer',
]);
```

Where:

| Configuration          | Description                                   |
| ---------------------- | --------------------------------------------- |
| `consumer_key`       | Safaricom Daraja consumer key                 |
| `consumer_secret`    | Safaricom Daraja consumer secret              |
| `passkey`            | Lipa Na M-Pesa Online passkey                 |
| `shortcode`          | PayBill or Till number                        |
| `initiator`          | Initiator name for B2C, Balance, and Reversal |
| `initiator_password` | Initiator password                            |
| `environment`        | `sandbox` or `live`                       |
| `cert_path`          | Path to the Safaricom`.cer` certificate     |

The `cert_path` is required only for:

* B2C payments
* Account Balance Inquiry
* Transaction Reversal

The certificate can also be configured after creating the gateway:

```php
$mpesa->setCertificatePath('/path/to/safaricom.cer');
```

### Pesapal Configuration

```php
$pesapal = $client->pesapal([
    'consumer_key'    => 'your_pesapal_consumer_key',
    'consumer_secret' => 'your_pesapal_consumer_secret',
    'environment'     => 'sandbox',
]);
```

Where:

| Configuration       | Description                     |
| ------------------- | ------------------------------- |
| `consumer_key`    | Pesapal API 3.0 consumer key    |
| `consumer_secret` | Pesapal API 3.0 consumer secret |
| `environment`     | `sandbox` or `live`         |

The Pesapal gateway automatically selects the correct API base URL based on the environment:

* **Sandbox** — `https://cybqa.pesapal.com/pesapalv3/api`
* **Live** — `https://pay.pesapal.com/v3/api`

---

# M-Pesa Usage

## 1. STK Push

Initiate a Lipa Na M-Pesa Online payment request.

```php
$response = $mpesa->requestSTKPush(
    phoneNumber: '254722336262',
    amount: 1.00,
    accountReference: 'INV-001',
    transactionDesc: 'Payment',
    callbackUrl: 'https://your-server.com/mpesa/callback'
);
```

The response contains the M-Pesa checkout request identifier.

```php
$checkoutRequestId = $response['CheckoutRequestID'];
```

Store the `CheckoutRequestID` if you need to query the transaction status later.

### Important Parameters

* `phoneNumber` — Customer MSISDN in international format
* `amount` — Payment amount
* `accountReference` — Account or invoice reference
* `transactionDesc` — Transaction description
* `callbackUrl` — URL that receives the STK callback

---

## 2. Query STK Push Status

Check the status of an STK Push transaction using its `CheckoutRequestID`.

```php
$status = $mpesa->querySTKStatus($checkoutRequestId);

if ($status['ResultCode'] === '0') {
    // Payment successful
}
```

The returned response should be evaluated according to the M-Pesa API response codes.

---

## 3. Register C2B URLs

Register the validation and confirmation URLs used for Customer-to-Business payments.

```php
$mpesa->registerC2BUrls(
    confirmationUrl: 'https://your-server.com/mpesa/confirm',
    validationUrl: 'https://your-server.com/mpesa/validate',
    responseType: 'Completed'
);
```

Supported response types include:

```php
'Completed'
```

or:

```php
'Canceled'
```

The validation endpoint can be used to validate incoming transactions before confirmation.

---

## 4. B2C Payment

Send money from a business M-Pesa account to a customer's M-Pesa account.

```php
$response = $mpesa->sendB2C(
    phoneNumber: '254722336262',
    amount: 100.00,
    command: 'SalaryPayment',
    remarks: 'Salary for July',
    occasion: 'Monthly salary'
);
```

Supported commands include:

```php
'SalaryPayment'
'BusinessPayment'
'PromotionPayment'
```

The `occasion` parameter is optional.

---

## 5. Account Balance Inquiry

Retrieve the balance of an M-Pesa shortcode.

```php
$balance = $mpesa->balanceInquiry(
    partyA: null,
    identifierType: '4'
);
```

When `partyA` is `null`, the configured shortcode is used.

Supported identifier types include:

| Type  | Description |
| ----- | ----------- |
| `1` | MSISDN      |
| `2` | Till Number |
| `4` | PayBill     |

---

## 6. Transaction Reversal

Reverse a completed M-Pesa transaction using its M-Pesa receipt number.

```php
$reversal = $mpesa->reversal(
    transactionId: 'LKXXXX1234',
    amount: 100.00,
    remarks: 'Reversal due to error'
);
```

Use transaction reversals carefully and only when the transaction meets Safaricom's reversal requirements.

---

# Pesapal Usage

## 1. Register IPN URL

Before submitting any order, you **must** register an IPN (Instant Payment Notification) URL. The returned `ipn_id` is **required** when submitting an order via `submitOrderRequest()`.

```php
$response = $pesapal->registerIPN('https://your-server.com/ipn-handler');
$ipnId = $response['ipn_id'] ?? null;

if (!$ipnId) {
    throw new \RuntimeException('Failed to obtain Pesapal IPN ID.');
}
```

### Important Parameters

* `ipnUrl` — Callback URL that receives payment status notifications
* `ipnId` — Returned identifier; store this and pass it as `notificationId` when submitting an order

---

## 2. Submit Order

Initiate a payment request and get a redirect URL.

> **Note:** The legacy `submitOrder()` method is deprecated. It throws a `PesapalException` and must not be used. Use `submitOrderRequest()` instead, which requires a `merchantReference` and a `notificationId`.

```php
$response = $pesapal->submitOrderRequest(
    merchantReference: 'INV-' . time(), // Must be unique per order
    amount: 100.00,
    currency: 'KES',
    description: 'Order #123',
    callbackUrl: 'https://your-server.com/callback',
    notificationId: $ipnId, // Required — obtained from registerIPN()
    billingAddress: [
        'email_address' => 'customer@example.com',
        'phone_number'  => '254722336262',
        'country_code'  => 'KE',
    ]
);

$redirectUrl     = $response['redirect_url'] ?? null;
$orderTrackingId = $response['order_tracking_id'] ?? null;
```

Store the `orderTrackingId` if you need to query the transaction status later, and redirect the customer to the `redirect_url` to complete payment.

### Important Parameters

* `merchantReference` — Unique merchant reference for the order (required)
* `amount` — Payment amount
* `currency` — Currency code (e.g., KES)
* `description` — Order description
* `callbackUrl` — URL called after payment
* `notificationId` — IPN ID from `registerIPN()` (required)
* `billingAddress` — Optional structured billing address array (`email_address`, `phone_number`, `country_code`)
* `redirectUrl` — Payment redirect URL from Pesapal (in response)
* `orderTrackingId` — Order tracking ID for status queries (in response)

---

## 3. Check Transaction Status

Verify the outcome of a payment using the `order_tracking_id` returned by `submitOrderRequest()` (or the `transaction_id` from your callback/IPN payload).

Pesapal may respond with an **error envelope** (`error.message`, `error.code`, `error.error_type`) whose inner fields are `null`, *or* with a success envelope that still lacks a usable status. Both cases must be treated as failures, so the check should be defensive rather than assuming a top-level `status` key exists.

```php
use Xfix\XfixPay\XfixPayClient;
use Illuminate\Support\Facades\Log; // or your logger of choice

$client  = new XfixPayClient();
$pesapal = $client->pesapal($configuration);

$verification = $pesapal->getTransactionStatus($parsed['transaction_id']);

Log::debug('Verification response', $verification);

// Distinguish "Pesapal returned an actual error" from
// "response has an error envelope with null inner fields".
$errorMessage = $verification['error']['message']    ?? null;
$errorCode    = $verification['error']['code']       ?? null;
$errorType    = $verification['error']['error_type'] ?? null;

$hasError = $errorMessage || $errorCode || $errorType;

// Status must be present and non-empty.
if ($hasError || empty($verification['payment_status_description'])) {
    throw new \Exception(
        'Pesapal status verification failed: '
        . ($errorMessage ?: $verification['message'] ?? 'Unknown error')
    );
}

// At this point the payload is well-formed and carries a usable status.
if ($verification['payment_status_description'] === 'Completed') {
    // Mark the order as paid, fulfil, etc.
}
```

### Important Parameters

* `orderTrackingId` — Order tracking ID from the `submitOrderRequest()` response (or the `transaction_id` supplied in the IPN/callback payload)

### Response Shape Notes

* **Success envelope** — contains `payment_status_description` (e.g. `Completed`, `Pending`, `Failed`). This is the authoritative status field; do **not** rely on a `status` key.
* **Error envelope** — contains an `error` object with `message`, `code`, and `error_type`. Any of these being non-null indicates a failure, **even if the inner fields are otherwise `null`** (e.g. `"error": {"message": null, "code": null, "error_type": null}` should still be treated as an error when the surrounding response indicates a failure).
* The defensive check above throws a single, descriptive exception that surfaces whichever error message is actually present, falling back to the top-level `message` and finally to `'Unknown error'`.

### Why This Pattern Matters

The naive check `if ($status['status'] === 'completed')` is unsafe with Pesapal API 3.0 because:

1. The status field is `payment_status_description`, not `status`.
2. A response can arrive with an `error` envelope whose fields are all `null` — the presence of the `error` key itself is meaningful, and the inner values must not be dereferenced blindly.
3. When the inner status is absent, the call should fail loudly rather than silently skip fulfilment logic.

---

## 4. Request Refund

Request a refund for a completed payment.

```php
$refund = $pesapal->requestRefund($orderTrackingId, 100.00, 'Customer requested refund');
```

### Important Parameters

* `orderTrackingId` — Order tracking ID
* `amount` — Refund amount (0 for full refund)
* `remarks` — Refund remarks/message

---

## 5. Cancel Order

Cancel a pending or failed order.

```php
$cancel = $pesapal->cancelOrder($orderTrackingId, 'Order cancelled by customer');
```

### Important Parameters

* `orderTrackingId` — Order tracking ID
* `remarks` — Cancellation reason

---

# Security Certificate

Safaricom requires the initiator password used by the B2C, Balance Inquiry, and Reversal APIs to be encrypted using Safaricom's public certificate.

The certificate is normally provided as a `.cer` file.

Example:

```php
$mpesa->setCertificatePath('/path/to/safaricom.cer');
```

XfixPay handles the following automatically:

1. Loads the Safaricom certificate.
2. Encrypts the initiator password.
3. Reuses the encrypted credential for subsequent requests within the gateway instance.

The SDK does **not** bundle Safaricom certificates.

If the certificate path is missing, invalid, or unreadable, XfixPay throws an `MpesaException` with a descriptive error message.

---

# Error Handling

M-Pesa operations may throw `MpesaException` for errors such as:

* Network failures
* Authentication failures
* Invalid configuration
* Missing certificates
* Invalid API responses
* M-Pesa API errors

Pesapal operations may throw `PesapalException` for errors such as:

* Authentication failures
* Invalid configuration
* Missing or invalid `notificationId`
* Calls to the deprecated `submitOrder()` method
* Invalid API responses
* Pesapal API errors

Handle exceptions using `try/catch`:

```php
use Xfix\XfixPay\Exception\MpesaException;
use Xfix\XfixPay\Exception\PesapalException;

try {
    $response = $mpesa->requestSTKPush(
        phoneNumber: '254722336262',
        amount: 1.00,
        accountReference: 'INV-001',
        transactionDesc: 'Payment',
        callbackUrl: 'https://your-server.com/mpesa/callback'
    );
} catch (MpesaException $e) {
    // Log the exception and handle the failure gracefully.
}

try {
    $order = $pesapal->submitOrderRequest(
        merchantReference: 'INV-' . time(),
        amount: 100.00,
        currency: 'KES',
        description: 'Order #123',
        callbackUrl: 'https://your-server.com/callback',
        notificationId: $ipnId
    );
} catch (PesapalException $e) {
    // Log the exception and handle the failure gracefully.
}
```

Because `getTransactionStatus()` may return a well-formed error envelope rather than throwing, status verification should be wrapped in an explicit defensive check **after** the call — see [Pesapal Usage → 3. Check Transaction Status](#3-check-transaction-status).

Applications should log relevant error information without exposing credentials or other sensitive data.

---

# Testing

Run the PHPUnit test suite with:

```bash
vendor/bin/phpunit
```

The SDK is designed to support HTTP client mocking, allowing unit tests to run without making real requests to payment providers.

Integration tests can be used separately for environments where real API credentials are available.

---

# Gateway Architecture

XfixPay is designed around an extensible gateway architecture.

Each payment provider is implemented as an independent gateway while sharing common infrastructure through the main client.

Example:

```php
$client = new XfixPayClient([
    'environment' => 'sandbox',
]);

$mpesa   = $client->mpesa($mpesaConfig);
$pesapal = $client->pesapal($pesapalConfig);
```

Future gateways will follow the same pattern:

```php
$paypal = $client->paypal($paypalConfig);

$stripe = $client->stripe($stripeConfig);

$bank = $client->bank($bankConfig);
```

This allows applications to integrate multiple payment providers without having to learn a completely different SDK structure for each provider.

---

# Planned Gateways

The following gateways are planned for future releases:

* **PayPal** — REST API
* **Stripe** — Stripe API / Connect
* **Bank Transfers** — Equity, KCB, and other supported banks
* **XfixPay** — Digital-asset payments

Gateway availability depends on implementation status and the relevant provider's API requirements.

---

# Project Structure

```text
xfixpay/
├── composer.json
├── README.md
├── LICENSE
├── .gitignore
│
├── src/
│   ├── XfixPayClient.php
│   ├── GatewayInterface.php
│   ├── AbstractGateway.php
│   │
│   ├── Exception/
│   │   ├── XfixPayException.php
│   │   ├── MpesaException.php
│   │   ├── PesapalException.php
│   │   ├── PayPalException.php
│   │   ├── BankException.php
│   │   └── StripeException.php
│   │
│   ├── Mpesa/
│   │   ├── MpesaGateway.php
│   │   ├── MpesaConfig.php
│   │   └── MpesaAuth.php
│   │
│   ├── Pesapal/
│   │   ├── PesapalGateway.php
│   │   ├── PesapalConfig.php
│   │   └── PesapalAuth.php
│   │
│   ├── PayPal/
│   │   ├── PayPalGateway.php
│   │   ├── PayPalConfig.php
│   │   └── PayPalAuth.php
│   │
│   ├── Bank/
│   │   ├── BankGateway.php
│   │   └── BankConfig.php
│   │
│   └── Stripe/
│       └── ...
│
├── tests/
│   ├── Unit/
│   │   ├── MpesaGatewayTest.php
│   │   ├── MpesaAuthTest.php
│   │   ├── PesapalGatewayTest.php
│   │   └── XfixPayClientTest.php
│   └── Integration/
│       └── MpesaLiveTest.php
│
├── examples/
│   ├── mpesa_stk_push.php
│   ├── mpesa_b2c.php
│   ├── mpesa_balance.php
│   ├── mpesa_reversal.php
│   └── pesapal_checkout.php
│
└── docs/
    ├── mpesa.md
    ├── pesapal.md
    └── contributing.md
```

---

## Key Components

| Component                              | Purpose                                              |
| -------------------------------------- | ---------------------------------------------------- |
| `src/XfixPayClient.php`              | Main SDK entry point and gateway factory             |
| `src/GatewayInterface.php`           | Common contract for payment gateways                 |
| `src/AbstractGateway.php`            | Shared gateway functionality                         |
| `src/Mpesa/MpesaGateway.php`         | M-Pesa API implementation                            |
| `src/Mpesa/MpesaAuth.php`            | M-Pesa OAuth authentication and token caching        |
| `src/Mpesa/MpesaConfig.php`          | M-Pesa configuration container                       |
| `src/Pesapal/PesapalGateway.php`     | Pesapal API 3.0 implementation                       |
| `src/Pesapal/PesapalAuth.php`        | Pesapal OAuth authentication and token caching       |
| `src/Pesapal/PesapalConfig.php`      | Pesapal configuration container                      |
| `src/Exception/MpesaException.php`   | M-Pesa-specific exception                            |
| `src/Exception/PesapalException.php` | Pesapal-specific exception                           |
| `src/Mpesa/certs/`                   | Optional certificate directory for local development |
| `examples/`                          | Common integration examples                          |
| `tests/`                             | Unit and integration tests                           |
| `docs/`                              | Extended technical documentation                     |

### Gateway Isolation

Each payment provider is kept within its own namespace and directory.

For example:

```text
src/Mpesa/
src/Pesapal/
src/PayPal/
src/Stripe/
src/Bank/
```

This keeps provider-specific authentication, configuration, exceptions, and API implementations isolated from one another.

---

# Certificate Handling

The SDK does not include payment-provider certificates.

For local development, users may optionally place their own certificates under:

```text
src/Mpesa/certs/
```

However, production applications should preferably store certificates outside the source repository and reference them through configuration or environment variables.

For example:

```php
'cert_path' => getenv('MPESA_CERT_PATH'),
```

Never commit private credentials, certificates, API secrets, or production configuration to source control.

---

# Contributing

Contributions are welcome.

Before submitting a pull request:

1. Follow PSR-12 coding standards.
2. Add or update tests for changed functionality.
3. Ensure the PHPUnit test suite passes.
4. Keep gateway-specific logic isolated within its gateway namespace.
5. Do not commit credentials, certificates, tokens, or other secrets.

Please open an issue before making substantial architectural changes.

---

# License

XfixPay is released under the **MIT License**.

See the [`LICENSE`](LICENSE) file for the full license text.
