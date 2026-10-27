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
* Pesapal payment gateway integration
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

---

# Pesapal Usage

## 1. Register IPN URL

Register an IPN (Instant Payment Notification) URL to receive payment status updates.

```php
$response = $pesapal->registerIPN('https://your-server.com/ipn-handler');
$ipnId = $response['ipn_id'] ?? null;
```

### Important Parameters

* `ipnUrl` - Callback URL that receives payment status notifications

---

## 2. Submit Order

Initiate a payment request and get a redirect URL.

```php
$response = $pesapal->submitOrder(
    amount: 100.00,
    currency: 'KES',
    description: 'Order #123',
    callbackUrl: 'https://your-server.com/callback',
    billingAddress: 'Customer Billing',
    deliveryAddress: 'Customer Delivery'
);
$redirectUrl = $response['redirect_url'] ?? null;
$orderTrackingId = $response['order_tracking_id'] ?? null;
```

### Important Parameters

* `amount` - Payment amount
* `currency` - Currency code (e.g., KES)
* `description` - Order description
* `callbackUrl` - URL called after payment
* `billingAddress` - Customer billing address
* `deliveryAddress` - Customer delivery address
* `redirectUrl` - Payment redirect URL from Pesapal
* `orderTrackingId` - Order tracking ID for status queries

---

## 3. Check Transaction Status

Verify the outcome of a payment.

```php
$status = $pesapal->getTransactionStatus($orderTrackingId);

if ($status['status'] === 'completed') {
    // Payment successful
}
```

### Important Parameters

* `orderTrackingId` - Order tracking ID from submitOrder response

---

## 4. Request Refund

Request a refund for a completed payment.

```php
$refund = $pesapal->requestRefund($orderTrackingId, 100.00, 'Customer requested refund');
```

### Important Parameters

* `orderTrackingId` - Order tracking ID
* `amount` - Refund amount (0 for full refund)
* `remarks` - Refund remarks/message

---

## 5. Cancel Order

Cancel a pending or failed order.

```php
$cancel = $pesapal->cancelOrder($orderTrackingId, 'Order cancelled by customer');
```

### Important Parameters

* `orderTrackingId` - Order tracking ID
* `remarks` - Cancellation reason

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

Handle exceptions using `try/catch`:

```php
use Xfix\XfixPay\Exception\MpesaException;

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
```

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

$mpesa = $client->mpesa($mpesaConfig);
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
* **XfixPay** — Stablecoin and digital-asset payments

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
│   │   ├── PayPalException.php
│   │   ├── BankException.php
│   │   ├── StripeException.php 
│   │
│   ├── Mpesa/
│   │   ├── MpesaGateway.php
│   │   ├── MpesaConfig.php
│   │   └── MpesaAuth.php
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
│   ├── Stripe/
│   │   └── ... 
│
├── tests/
│   ├── Unit/
│   │   ├── MpesaGatewayTest.php
│   │   ├── MpesaAuthTest.php
│   │   └── XfixPayClientTest.php
│   └── Integration/
│       └── MpesaLiveTest.php
│
├── examples/
│   ├── mpesa_stk_push.php
│   ├── mpesa_b2c.php
│   ├── mpesa_balance.php
│   └── mpesa_reversal.php
│
└── docs/
    ├── mpesa.md
    └── contributing.md
```

---

## Key Components

| Component                            | Purpose                                              |
| ------------------------------------ | ---------------------------------------------------- |
| `src/XfixPayClient.php`            | Main SDK entry point and gateway factory             |
| `src/GatewayInterface.php`         | Common contract for payment gateways                 |
| `src/AbstractGateway.php`          | Shared gateway functionality                         |
| `src/Mpesa/MpesaGateway.php`       | M-Pesa API implementation                            |
| `src/Mpesa/MpesaAuth.php`          | M-Pesa OAuth authentication and token caching        |
| `src/Mpesa/MpesaConfig.php`        | M-Pesa configuration container                       |
| `src/Exception/MpesaException.php` | M-Pesa-specific exception                            |
| `src/Mpesa/certs/`                 | Optional certificate directory for local development |
| `examples/`                        | Common integration examples                          |
| `tests/`                           | Unit and integration tests                           |
| `docs/`                            | Extended technical documentation                     |

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
