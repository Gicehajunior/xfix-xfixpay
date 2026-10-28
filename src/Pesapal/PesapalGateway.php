<?php

namespace Xfix\XfixPay\Pesapal;

use GuzzleHttp\Client;
use Xfix\XfixPay\GatewayInterface;
use Xfix\XfixPay\Exception\PesapalException;

class PesapalGateway implements GatewayInterface
{
    private PesapalConfig $config;

    private PesapalAuth $auth;

    private Client $httpClient;

    /**
     * Configure the gateway with an associative array.
     *
     * Required keys:
     * - consumer_key    Pesapal consumer key
     * - consumer_secret Pesapal consumer secret
     * - environment     'sandbox' or 'live'
     *
     * @param array $config
     * @return self
     */
    public function setConfig(array $config): self
    {
        $this->config = new PesapalConfig($config);

        $this->auth = new PesapalAuth($this->config);

        $this->httpClient = new Client([
            'base_uri' => $this->config->getBaseUrl(),
            'timeout'  => 30,
        ]);

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
     * {@inheritdoc}
     */
    public function request(string $method, string $endpoint, array $data = []): array
    {
        $token = $this->auth->getAccessToken();
        try {
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
            ];

            // GET requests use query parameters, POST/PUT/PATCH use JSON body
            if (strtoupper($method) === 'GET' && isset($data['query'])) {
                $options['query'] = $data['query'];
            } else {
                $options['json'] = $data;
            }

            $endpoint = ltrim($endpoint, '/');
            $response = $this->httpClient->request($method, $endpoint, $options);

            $body = $response->getBody()->getContents();

            return json_decode($body, true) ?: [];
        } catch (\Exception $e) {
            throw new PesapalException($e->getMessage(), $e->getCode(), $e);
        }
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
        return $this->request('GET', '/Transactions/GetTransactionStatus', [
            'query' => [
                'orderTrackingId' => $orderTrackingId,
            ],
        ]);
    }

    public function registerIPN(string $ipnUrl): array
    {
        return $this->request('POST', '/URLSetup/RegisterIPN', [
            'query' => [
                'ipnUrl' => $ipnUrl,
            ],
        ]);
    }

    public function submitOrder(float $amount, string $currency, string $description, string $callbackUrl, string $billingAddress = '', string $deliveryAddress = ''): array
    {
        return $this->request('POST', '/Transactions/SubmitOrderRequest', [
            'query' => [
                'amount'       => $amount,
                'currency'     => $currency,
                'description'  => $description,
                'callbackUrl'  => $callbackUrl,
                'billingAddress' => $billingAddress,
                'deliveryAddress' => $deliveryAddress,
            ],
        ]);
    }

    public function requestRefund(string $orderTrackingId, float $amount = 0, string $remarks = 'Refund'): array
    {
        return $this->request('POST', '/Transactions/RefundRequest', [
            'query' => [
                'orderTrackingId' => $orderTrackingId,
                'amount'          => $amount > 0 ? $amount : 0,
                'remarks'         => $remarks,
            ],
        ]);
    }

    public function cancelOrder(string $orderTrackingId, string $remarks = 'Order cancelled'): array
    {
        return $this->request('POST', '/Transactions/CancelOrder', [
            'query' => [
                'orderTrackingId' => $orderTrackingId,
                'remarks'         => $remarks,
            ],
        ]);
    }
}