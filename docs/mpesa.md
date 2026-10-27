# XfixPay M-Pesa SDK Documentation

## Overview

This module provides M-Pesa (Safaricom Daraja) payment gateway integration for the XfixPay SDK. All M-Pesa-specific logic is isolated under `src/Mpesa/`.

## Configuration

```php
use Xfix\XfixPay\Mpesa\MpesaGateway;
use Xfix\XfixPay\Mpesa\MpesaConfig;

$config = new MpesaConfig([
    'consumer_key'       => 'your_consumer_key',
    'consumer_secret'    => 'your_consumer_secret',
    'passkey'            => 'your_passkey',
    'shortcode'          => '174379',
    'initiator'          => 'apitest',
    'initiator_password' => 'your_initiator_password',
    'environment'        => 'sandbox', // or 'live'
    'cert_path'          => '/path/to/safaricom.cer',
]);

$gateway = new MpesaGateway();
$gateway->setConfig($config->getConfig());
```

## Usage Examples

### 1. STK Push

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

### 2. Query STK Push Status

Check the status of an STK Push transaction using its `CheckoutRequestID`.

```php
$status = $mpesa->querySTKStatus($checkoutRequestId);

if ($status['ResultCode'] === '0') {
    // Payment successful
}
```

### 3. Register C2B URLs

Register the validation and confirmation URLs used for Customer-to-Business payments.

```php
$mpesa->registerC2BUrls(
    confirmationUrl: 'https://your-server.com/mpesa/confirm',
    validationUrl: 'https://your-server.com/mpesa/validate',
    responseType: 'Completed'
);
```

### 4. B2C Payment

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

### 5. Account Balance Inquiry

Retrieve the balance of an M-Pesa shortcode.

```php
$balance = $mpesa->balanceInquiry(
    partyA: null,
    identifierType: '4'
);
```

When `partyA` is `null`, the configured shortcode is used.

### 6. Transaction Reversal

Reverse a completed M-Pesa transaction using its M-Pesa receipt number.

```php
$reversal = $mpesa->reversal(
    transactionId: 'LKXXXX1234',
    amount: 100.00,
    remarks: 'Reversal due to error'
);
```

---

## Error Handling

M-Pesa operations may throw `MpesaException` for errors such as:

* Network failures
* Authentication failures
* Invalid configuration

All exceptions extend `Xfix\XfixPay\Exception\MpesaException`.

---

## Notes

* Base URLs are configured in `MpesaConfig`:
  - Sandbox: `https://api.safaricom.co.ke`
  - Live: `https://api.safaricom.co.ke`
* All methods return decoded JSON arrays from the M-Pesa API responses.
* The certificate path is required only for B2C, Balance Inquiry, and Reversal APIs.
* The certificate can also be configured after creating the gateway:

```php
$mpesa->setCertificatePath('/path/to/safaricom.cer');
```