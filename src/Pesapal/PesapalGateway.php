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
}