<?php

namespace Xfix\XfixPay\Pesapal;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Xfix\XfixPay\GatewayInterface;
use Xfix\XfixPay\Exception\PesapalException;

class PesapalGateway implements GatewayInterface
{
    private PesapalConfig $config;

    private PesapalAuth $auth;

    private Client $httpClient;

    private LoggerInterface $logger;

    /**
     * Configure the gateway with an associative array.
     *
     * Required keys:
     * - consumer_key    Pesapal consumer key
     * - consumer_secret Pesapal consumer secret
     * - environment     'sandbox' or 'live'
     *
     * Optional keys:
     * - logger          PSR-3 logger (Psr\Log\LoggerInterface)
     *
     * @param array $config
     * @return self
     */
    public function setConfig(array $config): self
    {
        $this->config = new PesapalConfig($config);

        $this->auth = new PesapalAuth($this->config);

        // Guzzle treats `base_uri` WITHOUT a trailing slash as a "file" and
        // drops the last segment when resolving a relative path. For
        // https://pay.pesapal.com/v3 this silently loses "/v3" and produces
        // https://pay.pesapal.com/Transactions/... which returns an HTML 404
        // page (often 200 from a catch-all), which then json_decode -> null.
        // Force a trailing slash so "/v3" survives RFC 3986 §5.3 resolution.
        $baseUri = rtrim($this->config->getBaseUrl(), '/') . '/';

        $this->httpClient = new Client([
            'base_uri'        => $baseUri,
            'timeout'         => 30,
            'connect_timeout' => 10,
        ]);

        if (isset($config['logger']) && $config['logger'] instanceof LoggerInterface) {
            $this->logger = $config['logger'];
        }

        if (!isset($this->logger)) {
            $this->logger = new NullLogger();
        }

        return $this;
    }

    /**
     * Inject a PSR-3 logger post-construction.
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

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
     * Execute an authenticated request against Pesapal.
     *
     * @param string $method    HTTP verb
     * @param string $endpoint  Relative path (no leading slash),
     *                          e.g. "Transactions/GetTransactionStatus"
     * @param array  $options   ['query' => [...]] for GET,
     *                          ['json'  => [...]] for POST/PUT/PATCH
     *
     * @return array Decoded JSON body
     *
     * @throws PesapalException On transport failure or non-JSON response
     */
    public function request(string $method, string $endpoint, array $options = []): array
    {
        $method   = strtoupper($method);
        $endpoint = ltrim($endpoint, '/');

        try {
            $token = $this->auth->getAccessToken();

            $requestOptions = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                ],
            ];

            // GET - query string; POST/PUT/PATCH - JSON body.
            // Previously POST endpoints were passing ['query' => [...]] and
            // the body became {"query":{...}} — malformed for Pesapal.
            if ($method === 'GET' && isset($options['query'])) {
                $requestOptions['query'] = $options['query'];
            }

            if (isset($options['json'])) {
                $requestOptions['json'] = $options['json'];
            }

            // Guzzle Response has no getEffectiveUri(); TransferStats does.
            // Capture the fully-resolved URL after the request completes,
            // before redirects are collapsed, so debugging shows the real
            // URL Guzzle hit (including any lost "/v3").
            $effectiveUri = null;
            $requestOptions['on_stats'] = static function (TransferStats $stats) use (&$effectiveUri): void {
                $effectiveUri = (string) $stats->getEffectiveUri();
            };

            $this->logger->debug('Pesapal request', [
                'method'   => $method,
                'endpoint' => $endpoint,
                'base_uri' => (string) $this->httpClient->getConfig('base_uri'),
                'query'    => $requestOptions['query'] ?? null,
                'body'     => isset($requestOptions['json'])
                    ? json_encode($requestOptions['json'])
                    : null,
            ]);

            $response = $this->httpClient->request($method, $endpoint, $requestOptions);

            return $this->decodeResponse($response, $method, $endpoint, $effectiveUri);
        } catch (GuzzleException $e) {
            $this->logger->error('Pesapal HTTP error', [
                'method'   => $method,
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
            ]);

            throw new PesapalException(
                'Pesapal request failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Decode the response body and fail loudly on non-JSON.
     *
     * Previous code did `json_decode($body, true) ?: []`, which silently
     * turned every HTML/empty-body error into an empty array and produced
     * "Unknown error" downstream.
     */
    private function decodeResponse(ResponseInterface $response, string $method, string $endpoint, ?string $effectiveUri = null): array {
        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();
        $uri    = $effectiveUri ?? $endpoint;

        $this->logger->debug('Pesapal response', [
            'method' => $method,
            'url'    => $uri,
            'status' => $status,
            'body'   => substr($body, 0, 1000),
        ]);

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new PesapalException(sprintf(
                'Pesapal non-JSON response [HTTP %d] from %s %s: %s',
                $status,
                $method,
                $uri,
                substr($body, 0, 300)
            ));
        }

        return $decoded;
    }

    /**
     * Get the status of a Pesapal transaction using the OrderTrackingId.
     *
     * Pesapal requires calling GetTransactionStatus after receiving an IPN
     * or callback to confirm the actual payment status.
     *
     * @param string $orderTrackingId The OrderTrackingId received from Pesapal callback/IPN
     * @return array Pesapal transaction status response
     * @throws PesapalException
     */
    public function getTransactionStatus(string $orderTrackingId): array
    {
        return $this->request('GET', 'Transactions/GetTransactionStatus', [
            'query' => [
                'orderTrackingId' => $orderTrackingId,
            ],
        ]);
    }

    /**
     * Register an IPN URL. The response contains `ipn_id` (notification_id)
     * which is REQUIRED when submitting an order.
     *
     * @param string $ipnUrl
     * @param string $notificationType 'POST' or 'GET'
     * @return array ['url' => ..., 'ipn_id' => ..., ...]
     */
    public function registerIPN(string $ipnUrl, string $notificationType = 'POST'): array
    {
        return $this->request('POST', 'URLSetup/RegisterIPN', [
            'json' => [
                'url'                   => $ipnUrl,
                'ipn_notification_type' => strtoupper($notificationType),
            ],
        ]);
    }

    /**
     * @deprecated Cannot send notification_id — Pesapal requires it.
     *             Use submitOrderRequest() instead.
     */
    public function submitOrder(float $amount, string $currency, string $description, string $callbackUrl, string $billingAddress = '', string $deliveryAddress = ''): array
    {
        throw new PesapalException(
            'submitOrder() is deprecated: Pesapal SubmitOrderRequest requires ' .
            '`id` (merchant reference) and `notification_id` (from registerIPN()). ' .
            'Use submitOrderRequest() instead.'
        );
    }

    /**
     * Submit an order to Pesapal. Returns `order_tracking_id` and `redirect_url`.
     *
     * @param string $merchantReference Your unique per-order reference (e.g. "XFIX-123-20261028-001")
     * @param float  $amount
     * @param string $currency
     * @param string $description
     * @param string $callbackUrl
     * @param string $notificationId    ipn_id returned by registerIPN()
     * @param array  $billingAddress    Optional structured billing address
     *
     * @return array ['order_tracking_id' => ..., 'redirect_url' => ..., 'merchant_reference' => ...]
     * @throws PesapalException
     */
    public function submitOrderRequest(string $merchantReference, float  $amount, string $currency, string $description, string $callbackUrl, string $notificationId, array  $billingAddress = []): array {
        return $this->request('POST', 'Transactions/SubmitOrderRequest', [
            'json' => [
                'id'              => $merchantReference,
                'currency'        => strtoupper($currency),
                'amount'          => $amount,
                'description'     => $description,
                'callback_url'    => $callbackUrl,
                'notification_id' => $notificationId,
                'billing_address' => (object) $billingAddress,
            ],
        ]);
    }

    /**
     * Request a refund.
     *
     * NOTE: Pesapal's RefundRequest wants the `confirmation_code`, NOT the
     * orderTrackingId. Pass the confirmation_code from GetTransactionStatus.
     *
     * @param string $confirmationCode
     * @param float  $amount 0 for full refund
     * @param string $remarks
     *
     * @return array
     * @throws PesapalException
     */
    public function requestRefund(string $confirmationCode, float $amount = 0, string $remarks = 'Refund'): array
    {
        return $this->request('POST', 'Transactions/RefundRequest', [
            'json' => [
                'confirmation_code' => $confirmationCode,
                'amount'            => $amount > 0 ? $amount : 0,
                'remarks'           => $remarks,
            ],
        ]);
    }

    /**
     * Cancel an unpaid order.
     *
     * @param string $orderTrackingId
     * @param string $remarks
     *
     * @return array
     * @throws PesapalException
     */
    public function cancelOrder(string $orderTrackingId, string $remarks = 'Order cancelled'): array
    {
        return $this->request('POST', 'Transactions/CancelOrder', [
            'json' => [
                'order_tracking_id' => $orderTrackingId,
                'remarks'           => $remarks,
            ],
        ]);
    }
}