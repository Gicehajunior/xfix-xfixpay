<?php

namespace Xfix\XfixPay\Mpesa;

use GuzzleHttp\Client;
use Xfix\XfixPay\Mpesa\Exception\MpesaException;

class MpesaAuth
{
    private Client $httpClient;

    private MpesaConfig $config;

    private ?string $cachedToken = null;
    
    private ?int $tokenExpiresAt = null;

    public function __construct(MpesaConfig $config)
    {
        $this->config = $config;
        $this->httpClient = new Client([
            'base_uri' => $config->getBaseUrl(),
            'timeout'  => 30,
        ]);
    }

    public function getAccessToken(): string
    {
        // Check if cached token is still valid (expiry time - 30s buffer)
        if ($this->cachedToken && $this->tokenExpiresAt > time()) {
            return $this->cachedToken;
        }

        $response = $this->httpClient->request('GET', '/oauth/v1/generate?grant_type=client_credentials', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(
                    $this->config->getConsumerKey() . ':' . $this->config->getConsumerSecret()
                ),
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if (!isset($data['access_token'])) {
            throw new MpesaException('Failed to get M-Pesa access token: ' . json_encode($data));
        }

        $this->cachedToken = $data['access_token'];
        $this->tokenExpiresAt = time() + ($data['expires_in'] ?? 3600) - 30;

        return $this->cachedToken;
    }
}