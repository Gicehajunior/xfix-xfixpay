# XfixPay M-Pesa SDK

The M-Pesa gateway supports STK Push/C2B deposits, B2C withdrawals and refunds,
B2B transfers, balance inquiries, transaction-status queries and reversals.

## Configuration

```php
use Xfix\XfixPay\Mpesa\MpesaGateway;

$mpesa = (new MpesaGateway())->setConfig([
    'consumer_key'       => 'your_consumer_key',
    'consumer_secret'    => 'your_consumer_secret',
    'passkey'            => 'your_passkey',
    'shortcode'          => '174379',
    'initiator'          => 'apitest',
    'initiator_password' => 'your_initiator_password',
    'environment'        => 'sandbox',
    'cert_path'          => '/path/to/safaricom.cer',
    'b2c_result_url'     => 'https://your-server.com/mpesa/b2c/result',
    'b2c_timeout_url'    => 'https://your-server.com/mpesa/b2c/timeout',
    'b2b_result_url'     => 'https://your-server.com/mpesa/b2b/result',
    'b2b_timeout_url'    => 'https://your-server.com/mpesa/b2b/timeout',
    'balance_result_url' => 'https://your-server.com/mpesa/balance/result',
    'balance_timeout_url' => 'https://your-server.com/mpesa/balance/timeout',
    'reversal_result_url' => 'https://your-server.com/mpesa/reversal/result',
    'reversal_timeout_url' => 'https://your-server.com/mpesa/reversal/timeout',
    'transaction_status_result_url' => 'https://your-server.com/mpesa/transaction-status/result',
    'transaction_status_timeout_url' => 'https://your-server.com/mpesa/transaction-status/timeout',
]);
```

The callback settings are required for B2C, B2B, balance, reversal and
transaction-status operations. Pass the URLs to an individual method instead,
or configure them here. Safaricom callback URLs must be valid HTTPS URLs.
The certificate is required for operations using the initiator security
credential: B2C, B2B, balance, reversal and transaction-status queries.

## Deposit with STK Push

`deposit()` is a convenience method for initiating an STK Push. The returned
response means the request was accepted; credit the wallet only after verifying
the asynchronous Safaricom callback.

```php
$response = $mpesa->deposit(
    phoneNumber: '254722336262',
    amount: 100,
    accountReference: 'WALLET-17',
    transactionDesc: 'Wallet deposit',
    callbackUrl: 'https://your-server.com/mpesa/stk/callback',
    transactionType: 'CustomerPayBillOnline'
);
```

For Till payments, pass `CustomerBuyGoodsOnline` as `transactionType`.

Use `requestSTKPush()` for the same operation. Use `querySTKStatus()` with the
returned `CheckoutRequestID` when an STK callback has not arrived.

## Customer-initiated C2B

Register the validation and confirmation endpoints for PayBill/Till payments:

```php
$mpesa->registerC2BUrls(
    confirmationUrl: 'https://your-server.com/mpesa/confirm',
    validationUrl: 'https://your-server.com/mpesa/validate',
    responseType: 'Completed'
);
```

`responseType` must be `Completed` or `Canceled`.

## Withdrawal with B2C

`withdraw()` sends a `BusinessPayment` to a customer's mobile number. Wait for
the result callback before finalizing the wallet debit.

```php
$response = $mpesa->withdraw(
    phoneNumber: '254722336262',
    amount: 100,
    remarks: 'Wallet withdrawal'
);
```

Use `sendB2C()` to choose another supported B2C command.

## Refund to a phone

`refundToPhone()` sends a new B2C payment to the customer. This is different
from reversing an existing M-Pesa receipt.

```php
$response = $mpesa->refundToPhone(
    phoneNumber: '254722336262',
    amount: 100,
    remarks: 'Customer refund'
);
```

## Business-to-business transfer

`transfer()` sends money from the configured shortcode to another PayBill or
Till using the B2B API.

```php
$response = $mpesa->transfer(
    receiverShortcode: '600001',
    amount: 500,
    accountReference: 'INV-204',
    remarks: 'Supplier transfer'
);
```

Use `sendB2B()` when you need to specify the command or identifier types.

## Balance inquiry

```php
$response = $mpesa->balanceInquiry(
    partyA: null,
    identifierType: '4'
);
```

When `partyA` is `null`, the configured shortcode is used. Identifier types
include `1` (MSISDN), `2` (Till) and `4` (PayBill).

## Transaction status query

Query the status of an M-Pesa receipt. The result arrives asynchronously at the
configured callback URL.

```php
$response = $mpesa->queryTransactionStatus('LKXXXX1234');
```

## Reversal

Request a provider reversal for an eligible completed M-Pesa transaction:

```php
$response = $mpesa->reversal(
    transactionId: 'LKXXXX1234',
    amount: 100,
    remarks: 'Reversal due to error'
);
```

The response acknowledges the request; use the configured result callback to
record the final reversal outcome. Reversal eligibility is determined by
Safaricom.

## Errors and safety

Invalid amounts, phone numbers, callback URLs and identifiers throw
`\InvalidArgumentException`. Network, authentication and provider errors throw
`MpesaException`. Requests that move money are asynchronous and must not be
treated as completed based solely on the initial API response.
