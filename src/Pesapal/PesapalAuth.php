<?php

namespace Xfix\XfixPay\Pesapal;

use GuzzleHttp\Client;
use Xfix\XfixPay\Exception\PesapalException;

class PesapalAuth
{
    private Client $httpClient;

    private PesapalConfig $config;

    private ?string $cachedToken = null;

    private ?int $tokenExpiresAt = null;

    public function __construct(PesapalConfig $config)
    {
        $this->config = $config;
        $this->httpClient = new Client([
            'base_uri' => $config->getBaseUrl(),
            'timeout'  => 30,
        ]);
    }

    public function getAccessToken(): string
    {
        // Check if cached token is still valid (expiry time - 300s buffer, Pesapal tokens ~1 hour)
        if ($this->cachedToken && $this->tokenExpiresAt > time()) {
            return $this->cachedToken;
        }

        $response = $this->httpClient->request('POST', '/api/Auth/RequestToken', [
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'consumer_key'    => $this->config->getConsumerKey(),
                'consumer_secret' => $this->config->getConsumerSecret(),
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if (!isset($data['token'])) {
            throw new PesapalException('Failed to get Pesapal bearer token: ' . json_encode($data));
        }

        $this->cachedToken = $data['token'];
        // Pesapal tokens typically expire after 1 hour; cache with 5 minute buffer
        $this->tokenExpiresAt = time() + 3300;

        return $this->cachedToken;
    }
}