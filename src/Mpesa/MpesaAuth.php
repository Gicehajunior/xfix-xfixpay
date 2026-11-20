<?php

namespace Xfix\XfixPay\Mpesa;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Xfix\XfixPay\Exception\MpesaException;

class MpesaAuth
{
    private Client $httpClient;

    private MpesaConfig $config;

    private ?string $cachedToken = null;
    
    private ?int $tokenExpiresAt = null;

    public function __construct(MpesaConfig $config, ?Client $httpClient = null)
    {
        $this->config = $config;
        $this->httpClient = $httpClient ?: new Client([
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

        try {
            $response = $this->httpClient->request('GET', '/oauth/v1/generate', [
                'query' => [
                    'grant_type' => 'client_credentials',
                ],
                'auth' => [
                    $this->config->getConsumerKey(),
                    $this->config->getConsumerSecret(),
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json; charset=utf8',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new MpesaException(
                'M-Pesa authentication request failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || empty($data['access_token'])) {
            throw new MpesaException('Failed to get a valid M-Pesa access token response.');
        }

        $this->cachedToken = $data['access_token'];
        $this->tokenExpiresAt = time() + ($data['expires_in'] ?? 3600) - 30;

        return $this->cachedToken;
    }
}