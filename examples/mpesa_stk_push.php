<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load(); 

use Xfix\XfixPay\XfixPayClient;

$client = new XfixPayClient();

$mpesa = $client->mpesa([
    'consumer_key'       => $_ENV['MPESA_CONSUMER_KEY'],
    'consumer_secret'    => $_ENV['MPESA_CONSUMER_SECRET'],
    'passkey'            => $_ENV['MPESA_PASSKEY'],
    'shortcode'          => $_ENV['MPESA_SHORTCODE'], // Paybill/Till 
    'environment'        => $_ENV['MPESA_ENVIRONMENT'] ?? 'sandbox', // or 'live'
]);

// 1. Request STK Push
$response = $mpesa->requestSTKPush(
    phoneNumber: '254719462331',
    amount: 1.00,
    accountReference: 'INV-2026-004',
    transactionDesc: 'Test Payment',
    callbackUrl: 'https://alkalize-sequester-proximity.ngrok-free.dev/xfixpay/confirmation'
);

print_r($response);

// 2. Query status (using CheckoutRequestID from response)
if (isset($response['CheckoutRequestID'])) {
    $status = $mpesa->querySTKStatus($response['CheckoutRequestID']);
    print_r($status);
}