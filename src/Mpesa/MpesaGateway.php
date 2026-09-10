<?php

namespace Xfix\XfixPay\Mpesa;

use GuzzleHttp\Client;
use Xfix\XfixPay\GatewayInterface;
use Xfix\XfixPay\Exception\MpesaException;

class MpesaGateway implements GatewayInterface
{
    private MpesaConfig $config;

    private MpesaAuth $auth;

    private Client $httpClient;

    private ?string $cachedSecurityCredential = null;

    /**
     * Configure the gateway with an associative array.
     *
     * Required keys for most operations:
     * - consumer_key
     * - consumer_secret
     * - passkey (for STK Push)
     * - shortcode (Paybill or Till)
     * - initiator (for B2C, Balance, Reversal)
     * - initiator_password (for B2C, Balance, Reversal)
     * - environment ('sandbox' or 'live')
     * - cert_path (optional, required for B2C/Balance/Reversal)
     *
     * @param array $config
     * @return self
     */
    public function setConfig(array $config): self
    {
        $this->config = new MpesaConfig($config);

        $this->auth = new MpesaAuth($this->config);

        $this->httpClient = new Client([
            'base_uri' => $this->config->getBaseUrl(),
            'timeout'  => 30,
        ]);

        return $this;
    }

    /**
     * Explicitly set the certificate path (overrides config['cert_path']).
     */
    public function setCertificatePath(string $path): self
    {
        $this->config->setCertPath($path);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(): array
    {
        return ['access_token' => $this->auth->getAccessToken()];
    }

    /**
     * Perform a generic API request with authentication.
     *
     * @param string $method  HTTP method (GET, POST, etc.)
     * @param string $endpoint API endpoint (e.g., '/mpesa/stkpush/v1/processrequest')
     * @param array  $data    Payload for POST requests
     * @return array Decoded JSON response
     * @throws MpesaException
     */
    public function request(string $method, string $endpoint, array $data = []): array
    {
        $token = $this->auth->getAccessToken();
        try {
            $response = $this->httpClient->request($method, $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $data,
            ]);

            $body = $response->getBody()->getContents();

            return json_decode($body, true) ?: [];
        } catch (\Exception $e) {
            throw new MpesaException($e->getMessage(), $e->getCode(), $e);
        }
    }
    
    /**
     * Initiate an STK Push payment request.
     *
     * @param string $phoneNumber      Customer MSISDN (2547XXXXXXXX)
     * @param float  $amount           Amount to charge
     * @param string $accountReference Invoice/order reference (max 12 chars)
     * @param string $transactionDesc  Description (max 13 chars)
     * @param string $callbackUrl      HTTPS endpoint for payment notification
     * @return array Response from Safaricom (includes CheckoutRequestID)
     */
    public function requestSTKPush(string $phoneNumber, float $amount, string $accountReference, string $transactionDesc, string $callbackUrl): array {
        $timestamp = date('YmdHis');
        $password = base64_encode(
            $this->config->getShortcode() . $this->config->getPasskey() . $timestamp
        );

        $payload = [
            'BusinessShortCode' => $this->config->getShortcode(),
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => (int) round($amount),
            'PartyA'            => $this->normalizePhone($phoneNumber),
            'PartyB'            => $this->config->getShortcode(),
            'PhoneNumber'       => $this->normalizePhone($phoneNumber),
            'CallBackURL'       => $callbackUrl,
            'AccountReference'  => $accountReference,
            'TransactionDesc'   => $transactionDesc,
        ];

        return $this->request('POST', '/mpesa/stkpush/v1/processrequest', $payload);
    }
    
    /**
     * Query the status of an STK Push transaction.
     *
     * @param string $checkoutRequestId The CheckoutRequestID from the STK Push response.
     * @return array Status response (includes ResultCode, ResultDesc)
     */
    public function querySTKStatus(string $checkoutRequestId): array
    {
        $timestamp = date('YmdHis');
        $password = base64_encode(
            $this->config->getShortcode() . $this->config->getPasskey() . $timestamp
        );

        $payload = [
            'BusinessShortCode' => $this->config->getShortcode(),
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        return $this->request('POST', '/mpesa/stkpushquery/v1/query', $payload);
    }
    
    /**
     * Register confirmation and validation URLs for C2B payments.
     *
     * @param string $confirmationUrl HTTPS endpoint for successful payments
     * @param string $validationUrl   HTTPS endpoint for payment validation
     * @param string $responseType    'Completed' (default) or 'Canceled'
     * @return array Response (includes ConversationID, OriginatorCoversationID)
     */
    public function registerC2BUrls(string $confirmationUrl, string $validationUrl, string $responseType = 'Completed'): array {
        $payload = [
            'ShortCode'       => $this->config->getShortcode(),
            'ResponseType'    => strtolower($responseType),
            'ConfirmationURL' => $confirmationUrl,
            'ValidationURL'   => $validationUrl,
        ];

        return $this->request('POST', '/mpesa/c2b/v2/registerurl', $payload);
    }
    
    /**
     * Send a Business‑to‑Customer payment.
     *
     * @param string $phoneNumber Recipient MSISDN (2547XXXXXXXX)
     * @param float  $amount      Amount to send
     * @param string $command     SalaryPayment, BusinessPayment, PromotionPayment
     * @param string $remarks     Remarks (max 100 chars)
     * @param string $occasion    Optional occasion (max 100 chars)
     * @return array Response (includes ConversationID, OriginatorCoversationID)
     * @throws MpesaException if certificate path is missing/invalid
     */
    public function sendB2C(string $phoneNumber, float $amount, string $command = 'SalaryPayment', string $remarks = 'Payment', string $occasion = ''): array {
        $securityCredential = $this->getSecurityCredential();

        $payload = [
            'InitiatorName'      => $this->config->getInitiator(),
            'SecurityCredential' => $securityCredential,
            'CommandID'          => $command,
            'Amount'             => (int) round($amount),
            'PartyA'             => $this->config->getShortcode(),
            'PartyB'             => $this->normalizePhone($phoneNumber),
            'Remarks'            => $remarks,
            'QueueTimeOutURL'    => $this->config->getBaseUrl() . '/b2c/timeout', // Replace with your own
            'ResultURL'          => $this->config->getBaseUrl() . '/b2c/result',
            'Occasion'           => $occasion,
        ];

        return $this->request('POST', '/mpesa/b2c/v1/paymentrequest', $payload);
    }
    
    /**
     * Check the balance of a Paybill or Till.
     *
     * @param string|null $partyA         Shortcode (defaults to configured shortcode)
     * @param string      $identifierType 1=MSISDN, 2=Till, 4=Paybill
     * @return array Response (includes ConversationID, OriginatorCoversationID)
     * @throws MpesaException if certificate path is missing/invalid
     */
    public function balanceInquiry(string $partyA = null, string $identifierType = '4'): array
    {
        $securityCredential = $this->getSecurityCredential();

        $payload = [
            'Initiator'          => $this->config->getInitiator(),
            'SecurityCredential' => $securityCredential,
            'CommandID'          => 'AccountBalance',
            'PartyA'             => $partyA ?? $this->config->getShortcode(),
            'IdentifierType'     => $identifierType,
            'Remarks'            => 'Balance inquiry',
            'QueueTimeOutURL'    => $this->config->getBaseUrl() . '/balance/timeout',
            'ResultURL'          => $this->config->getBaseUrl() . '/balance/result',
        ];

        return $this->request('POST', '/mpesa/accountbalance/v1/query', $payload);
    }
    
    /**
     * Reverse a completed transaction.
     *
     * @param string $transactionId M-Pesa transaction ID (from receipt)
     * @param float  $amount        Amount to reverse (must match original)
     * @param string $remarks       Remarks (max 100 chars)
     * @return array Response (includes ConversationID, OriginatorCoversationID)
     * @throws MpesaException if certificate path is missing/invalid
     */
    public function reversal(string $transactionId, float $amount, string $remarks = 'Reversal'): array
    {
        $securityCredential = $this->getSecurityCredential();

        $payload = [
            'Initiator'              => $this->config->getInitiator(),
            'SecurityCredential'     => $securityCredential,
            'CommandID'              => 'TransactionReversal',
            'TransactionID'          => $transactionId,
            'Amount'                 => (int) round($amount),
            'ReceiverParty'          => $this->config->getShortcode(),
            'RecieverIdentifierType' => '4', // 4 = Paybill
            'Remarks'                => $remarks,
            'QueueTimeOutURL'        => $this->config->getBaseUrl() . '/reversal/timeout',
            'ResultURL'              => $this->config->getBaseUrl() . '/reversal/result',
        ];

        return $this->request('POST', '/mpesa/reversal/v1/request', $payload);
    }
    
    /**
     * Encrypt the initiator password using the user‑provided certificate.
     *
     * @param string $password Plain initiator password
     * @return string Base64‑encoded encrypted password
     * @throws MpesaException if certificate file is missing or invalid
     */
    private function encryptPassword(string $password): string
    {
        $certPath = $this->config->getCertPath();
        if (empty($certPath) || !file_exists($certPath)) {
            throw new MpesaException(
                'Certificate path not set or file not found. ' .
                'Please provide a valid certificate path via config["cert_path"] or setCertificatePath().'
            );
        }

        $pubKey = openssl_pkey_get_public(file_get_contents($certPath));
        if (!$pubKey) {
            throw new MpesaException('Invalid certificate file: ' . openssl_error_string());
        }

        openssl_public_encrypt($password, $encrypted, $pubKey);
        return base64_encode($encrypted);
    }

    /**
     * Get the cached security credential (encrypted initiator password).
     * The encryption is static; we compute it once per instance.
     *
     * @return string Base64‑encoded encrypted credential
     * @throws MpesaException
     */
    private function getSecurityCredential(): string
    {
        if ($this->cachedSecurityCredential === null) {
            $this->cachedSecurityCredential = $this->encryptPassword(
                $this->config->getSecurityCredential()
            );
        }

        return $this->cachedSecurityCredential;
    }

    /**
     * Normalise a phone number to the 254XXXXXXXXX format.
     *
     * Removes any non‑digit characters, strips leading 0 or +254,
     * and ensures the country code is 254.
     *
     * @param string $phone
     * @return string
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (substr($phone, 0, 1) === '0') {
            $phone = '254' . substr($phone, 1);
        }
        if (substr($phone, 0, 3) !== '254') {
            $phone = '254' . $phone;
        }
        return $phone;
    }
}