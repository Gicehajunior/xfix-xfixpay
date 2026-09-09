<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Xfix\XfixPay\XfixPayClient;

$client = new XfixPayClient();

$mpesa = $client->mpesa([
    'consumer_key'       => 'YOUR_CONSUMER_KEY',
    'consumer_secret'    => 'YOUR_CONSUMER_SECRET',
    'passkey'            => 'YOUR_PASSKEY',
    'shortcode'          => '174379', // Paybill/Till
    'initiator'          => 'apitest',
    'initiator_password' => 'YourInitiatorPassword',
    'environment'        => 'sandbox', // or 'live'
]);

// 1. Request STK Push
$response = $mpesa->requestSTKPush(
    phoneNumber: '254722336262',
    amount: 1.00,
    accountReference: 'INV-001',
    transactionDesc: 'Test Payment',
    callbackUrl: 'https://your-server.com/mpesa/callback'
);

print_r($response);

// 2. Query status (using CheckoutRequestID from response)
if (isset($response['CheckoutRequestID'])) {
    $status = $mpesa->querySTKStatus($response['CheckoutRequestID']);
    print_r($status);
}