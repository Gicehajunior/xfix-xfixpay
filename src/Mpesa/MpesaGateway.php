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

    private ?Client $injectedHttpClient;

    private ?string $cachedSecurityCredential = null;

    public function __construct(?Client $httpClient = null)
    {
        $this->injectedHttpClient = $httpClient;
    }

    /**
     * Configure the gateway with an associative array.
     *
     * Required keys for most operations:
     * - consumer_key
     * - consumer_secret
     * - passkey (for STK Push)
     * - shortcode (Paybill or Till)
     * - initiator (for B2C, B2B, Balance, Reversal, Transaction Status)
     * - initiator_password (for B2C, B2B, Balance, Reversal, Transaction Status)
     * - environment ('sandbox' or 'live')
     * - cert_path (optional, required for initiator-based operations)
     *
     * @param array $config
     * @return self
     */
    public function setConfig(array $config): self
    {
        $this->config = new MpesaConfig($config);

        $this->httpClient = $this->injectedHttpClient ?: new Client([
            'base_uri' => $this->config->getBaseUrl(),
            'timeout'  => 30,
        ]);
        $this->auth = new MpesaAuth($this->config, $this->httpClient);

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
            $decoded = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                throw new MpesaException('Safaricom returned an invalid JSON response.');
            }

            return $decoded;
        } catch (MpesaException $e) {
            throw $e;
        } catch (\Throwable $e) {
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
    public function requestSTKPush(
        string $phoneNumber,
        float $amount,
        string $accountReference,
        string $transactionDesc,
        string $callbackUrl,
        string $transactionType = 'CustomerPayBillOnline'
    ): array {
        $amount = $this->validateAmount($amount);
        $callbackUrl = $this->validateCallbackUrl($callbackUrl);
        if (!in_array($transactionType, ['CustomerPayBillOnline', 'CustomerBuyGoodsOnline'], true)) {
            throw new \InvalidArgumentException('Unsupported STK Push transaction type.');
        }

        $timestamp = date('YmdHis');
        $password = base64_encode(
            $this->config->getShortcode() . $this->config->getPasskey() . $timestamp
        );

        $payload = [
            'BusinessShortCode' => $this->config->getShortcode(),
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => $transactionType,
            'Amount'            => $amount,
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
     * Request a customer deposit using STK Push.
     *
     * The wallet should only be credited after the server receives and verifies
     * Safaricom's asynchronous callback.
     */
    public function deposit(
        string $phoneNumber,
        float $amount,
        string $accountReference,
        string $transactionDesc,
        string $callbackUrl,
        string $transactionType = 'CustomerPayBillOnline'
    ): array {
        return $this->requestSTKPush(
            $phoneNumber,
            $amount,
            $accountReference,
            $transactionDesc,
            $callbackUrl,
            $transactionType
        );
    }

    /**
     * Send a B2C business payment commonly used to refund a customer.
     *
     * This is a new payment to the customer's phone, not a reversal of the
     * original M-Pesa receipt. Use reverseTransaction() for provider reversals.
     */
    public function refundToPhone(
        string $phoneNumber,
        float $amount,
        string $remarks = 'Refund',
        string $occasion = '',
        ?string $resultUrl = null,
        ?string $queueTimeOutUrl = null
    ): array {
        return $this->sendB2C(
            $phoneNumber,
            $amount,
            'BusinessPayment',
            $remarks,
            $occasion,
            $resultUrl,
            $queueTimeOutUrl
        );
    }
    
    /**
     * Query the status of an STK Push transaction.
     *
     * @param string $checkoutRequestId The CheckoutRequestID from the STK Push response.
     * @return array Status response (includes ResultCode, ResultDesc)
     */
    public function querySTKStatus(string $checkoutRequestId): array
    {
        $checkoutRequestId = trim($checkoutRequestId);
        if ($checkoutRequestId === '') {
            throw new \InvalidArgumentException('Checkout request ID is required.');
        }

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
        $confirmationUrl = $this->validateCallbackUrl($confirmationUrl);
        $validationUrl = $this->validateCallbackUrl($validationUrl);
        $responseType = ucfirst(strtolower(trim($responseType)));

        if (!in_array($responseType, ['Completed', 'Canceled'], true)) {
            throw new \InvalidArgumentException('Response type must be Completed or Canceled.');
        }

        $payload = [
            'ShortCode'       => $this->config->getShortcode(),
            'ResponseType'    => $responseType,
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
    public function sendB2C(
        string $phoneNumber,
        float $amount,
        string $command = 'SalaryPayment',
        string $remarks = 'Payment',
        string $occasion = '',
        ?string $resultUrl = null,
        ?string $queueTimeOutUrl = null
    ): array {
        $amount = $this->validateAmount($amount);
        $phoneNumber = $this->normalizePhone($phoneNumber);

        if (!in_array($command, ['SalaryPayment', 'BusinessPayment', 'PromotionPayment'], true)) {
            throw new \InvalidArgumentException('Unsupported B2C command.');
        }

        $resultUrl = $this->resolveCallbackUrl($resultUrl, 'b2c_result_url');
        $queueTimeOutUrl = $this->resolveCallbackUrl($queueTimeOutUrl, 'b2c_timeout_url');
        $securityCredential = $this->getSecurityCredential();

        $payload = [
            'InitiatorName'      => $this->config->getInitiator(),
            'SecurityCredential' => $securityCredential,
            'CommandID'          => $command,
            'Amount'             => $amount,
            'PartyA'             => $this->config->getShortcode(),
            'PartyB'             => $phoneNumber,
            'Remarks'            => $remarks,
            'QueueTimeOutURL'    => $queueTimeOutUrl,
            'ResultURL'          => $resultUrl,
            'Occasion'           => $occasion,
        ];

        return $this->request('POST', '/mpesa/b2c/v1/paymentrequest', $payload);
    }

    /**
     * Request a withdrawal from the configured business shortcode to a phone.
     */
    public function withdraw(
        string $phoneNumber,
        float $amount,
        string $remarks = 'Withdrawal',
        string $occasion = '',
        ?string $resultUrl = null,
        ?string $queueTimeOutUrl = null
    ): array {
        return $this->sendB2C(
            $phoneNumber,
            $amount,
            'BusinessPayment',
            $remarks,
            $occasion,
            $resultUrl,
            $queueTimeOutUrl
        );
    }

    /**
     * Send a B2B payment from the configured shortcode to another shortcode.
     */
    public function sendB2B(
        string $receiverShortcode,
        float $amount,
        string $accountReference,
        string $remarks = 'Transfer',
        string $command = 'BusinessPayBill',
        string $senderIdentifierType = '4',
        string $receiverIdentifierType = '4',
        ?string $resultUrl = null,
        ?string $queueTimeOutUrl = null
    ): array {
        $amount = $this->validateAmount($amount);
        $receiverShortcode = trim($receiverShortcode);
        $accountReference = trim($accountReference);

        if ($receiverShortcode === '' || !ctype_digit($receiverShortcode)) {
            throw new \InvalidArgumentException('Receiver shortcode must contain digits only.');
        }
        if ($accountReference === '') {
            throw new \InvalidArgumentException('Account reference is required.');
        }
        if (!in_array((string) $senderIdentifierType, ['1', '2', '4'], true)
            || !in_array((string) $receiverIdentifierType, ['1', '2', '4'], true)) {
            throw new \InvalidArgumentException('Identifier type must be 1, 2, or 4.');
        }

        $resultUrl = $this->resolveCallbackUrl($resultUrl, 'b2b_result_url');
        $queueTimeOutUrl = $this->resolveCallbackUrl($queueTimeOutUrl, 'b2b_timeout_url');
        $securityCredential = $this->getSecurityCredential();

        $payload = [
            'Initiator'              => $this->config->getInitiator(),
            'SecurityCredential'     => $securityCredential,
            'CommandID'              => $command,
            'SenderIdentifierType'   => (string) $senderIdentifierType,
            'RecieverIdentifierType' => (string) $receiverIdentifierType,
            'Amount'                 => $amount,
            'PartyA'                 => $this->config->getShortcode(),
            'PartyB'                 => $receiverShortcode,
            'AccountReference'       => $accountReference,
            'Remarks'                => $remarks,
            'QueueTimeOutURL'        => $queueTimeOutUrl,
            'ResultURL'              => $resultUrl,
        ];

        return $this->request('POST', '/mpesa/b2b/v1/paymentrequest', $payload);
    }

    /**
     * Transfer funds to another PayBill or Till through the B2B API.
     */
    public function transfer(
        string $receiverShortcode,
        float $amount,
        string $accountReference,
        string $remarks = 'Transfer',
        string $command = 'BusinessPayBill',
        string $senderIdentifierType = '4',
        string $receiverIdentifierType = '4',
        ?string $resultUrl = null,
        ?string $queueTimeOutUrl = null
    ): array {
        return $this->sendB2B(
            $receiverShortcode,
            $amount,
            $accountReference,
            $remarks,
            $command,
            $senderIdentifierType,
            $receiverIdentifierType,
            $resultUrl,
            $queueTimeOutUrl
        );
    }
    
    /**
     * Check the balance of a Paybill or Till.
     *
     * @param string|null $partyA         Shortcode (defaults to configured shortcode)
     * @param string      $identifierType 1=MSISDN, 2=Till, 4=Paybill
     * @return array Response (includes ConversationID, OriginatorCoversationID)
     * @throws MpesaException if certificate path is missing/invalid
     */
    public function balanceInquiry(
        ?string $partyA = null,
        string $identifierType = '4',
        ?string $resultUrl = null,
        ?string $queueTimeOutUrl = null
    ): array
    {
        if (!in_array((string) $identifierType, ['1', '2', '4'], true)) {
            throw new \InvalidArgumentException('Identifier type must be 1, 2, or 4.');
        }

        $resultUrl = $this->resolveCallbackUrl($resultUrl, 'balance_result_url');
        $queueTimeOutUrl = $this->resolveCallbackUrl($queueTimeOutUrl, 'balance_timeout_url');
        $securityCredential = $this->getSecurityCredential();

        $payload = [
            'Initiator'          => $this->config->getInitiator(),
            'SecurityCredential' => $securityCredential,
            'CommandID'          => 'AccountBalance',
            'PartyA'             => $partyA ?? $this->config->getShortcode(),
            'IdentifierType'     => $identifierType,
            'Remarks'            => 'Balance inquiry',
            'QueueTimeOutURL'    => $queueTimeOutUrl,
            'ResultURL'          => $resultUrl,
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
    public function reversal(
        string $transactionId,
        float $amount,
        string $remarks = 'Reversal',
        ?string $resultUrl = null,
        ?string $queueTimeOutUrl = null
    ): array {
        return $this->reverseTransaction(
            $transactionId,
            $amount,
            $remarks,
            $resultUrl,
            $queueTimeOutUrl
        );
    }

    /**
     * Request reversal of an existing M-Pesa transaction.
     */
    public function reverseTransaction(
        string $transactionId,
        float $amount,
        string $remarks = 'Reversal',
        ?string $resultUrl = null,
        ?string $queueTimeOutUrl = null,
        ?string $receiverParty = null,
        string $receiverIdentifierType = '4',
        string $occasion = ''
    ): array {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            throw new \InvalidArgumentException('M-Pesa transaction ID is required.');
        }

        $amount = $this->validateAmount($amount);
        if (!in_array((string) $receiverIdentifierType, ['1', '2', '4'], true)) {
            throw new \InvalidArgumentException('Receiver identifier type must be 1, 2, or 4.');
        }

        $resultUrl = $this->resolveCallbackUrl($resultUrl, 'reversal_result_url');
        $queueTimeOutUrl = $this->resolveCallbackUrl($queueTimeOutUrl, 'reversal_timeout_url');
        $securityCredential = $this->getSecurityCredential();

        $payload = [
            'Initiator'              => $this->config->getInitiator(),
            'SecurityCredential'     => $securityCredential,
            'CommandID'              => 'TransactionReversal',
            'TransactionID'          => $transactionId,
            'Amount'                 => $amount,
            'ReceiverParty'          => $receiverParty ?? $this->config->getShortcode(),
            'RecieverIdentifierType' => (string) $receiverIdentifierType,
            'Remarks'                => $remarks,
            'QueueTimeOutURL'        => $queueTimeOutUrl,
            'ResultURL'              => $resultUrl,
            'Occasion'               => $occasion,
        ];

        return $this->request('POST', '/mpesa/reversal/v1/request', $payload);
    }

    /**
     * Query the status of an M-Pesa transaction by its receipt ID.
     */
    public function queryTransactionStatus(
        string $transactionId,
        ?string $resultUrl = null,
        ?string $queueTimeOutUrl = null,
        string $identifierType = '4',
        string $remarks = 'Transaction status query',
        string $occasion = ''
    ): array {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            throw new \InvalidArgumentException('M-Pesa transaction ID is required.');
        }
        if (!in_array((string) $identifierType, ['1', '2', '4'], true)) {
            throw new \InvalidArgumentException('Identifier type must be 1, 2, or 4.');
        }

        $resultUrl = $this->resolveCallbackUrl($resultUrl, 'transaction_status_result_url');
        $queueTimeOutUrl = $this->resolveCallbackUrl($queueTimeOutUrl, 'transaction_status_timeout_url');
        $securityCredential = $this->getSecurityCredential();

        $payload = [
            'Initiator'          => $this->config->getInitiator(),
            'SecurityCredential' => $securityCredential,
            'CommandID'          => 'TransactionStatusQuery',
            'TransactionID'      => $transactionId,
            'PartyA'             => $this->config->getShortcode(),
            'IdentifierType'     => (string) $identifierType,
            'ResultURL'          => $resultUrl,
            'QueueTimeOutURL'    => $queueTimeOutUrl,
            'Remarks'            => $remarks,
            'Occasion'           => $occasion,
        ];

        return $this->request('POST', '/mpesa/transactionstatus/v1/query', $payload);
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

        $certificate = file_get_contents($certPath);
        if ($certificate === false) {
            throw new MpesaException('Unable to read the Safaricom certificate file.');
        }

        $pubKey = openssl_pkey_get_public($certificate);
        if (!$pubKey) {
            throw new MpesaException('Invalid certificate file: ' . openssl_error_string());
        }

        if (!openssl_public_encrypt($password, $encrypted, $pubKey)) {
            throw new MpesaException('Unable to encrypt the M-Pesa initiator credential.');
        }

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

        if (!preg_match('/^254[17][0-9]{8}$/', $phone)) {
            throw new \InvalidArgumentException('Phone number must be a valid Kenyan mobile number.');
        }

        return $phone;
    }

    private function validateAmount(float $amount): int
    {
        if (!is_finite($amount) || $amount <= 0 || floor($amount) !== $amount) {
            throw new \InvalidArgumentException('M-Pesa amount must be a positive whole number.');
        }

        return (int) $amount;
    }

    private function validateCallbackUrl(string $url): string
    {
        $url = trim($url);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string) $scheme) !== 'https') {
            throw new \InvalidArgumentException('Safaricom callback URLs must be valid HTTPS URLs.');
        }

        return $url;
    }

    private function resolveCallbackUrl(?string $url, string $configKey): string
    {
        $url = $url ?: $this->config->getCallbackUrl($configKey);

        if (!$url) {
            throw new \InvalidArgumentException(
                sprintf('Callback URL is required; pass it to the method or configure "%s".', $configKey)
            );
        }

        return $this->validateCallbackUrl($url);
    }
}