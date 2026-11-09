<?php

namespace Xfix\XfixPay\PayPal;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Xfix\XfixPay\Exception\PayPalException;

class PayPalAuth
{
    private PayPalConfig $config;

    private Client $httpClient;

    private ?string $cachedAccessToken = null;

    private ?float $cachedTokenExpiresAt = null;

    public function __construct(PayPalConfig $config)
    {
        $this->config = $config;

        $this->httpClient = new Client([
            'base_uri'        => $config->getBaseUrl() . '/',
            'timeout'         => 30,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Obtain a PayPal OAuth2 access token using client credentials.
     *
     * @return string Access token
     * @throws PayPalException
     */
    public function getAccessToken(): string
    {
        // Return cached token if still valid (with 60s skew).
        if ($this->cachedAccessToken !== null
            && $this->cachedTokenExpiresAt !== null
            && microtime(true) < ($this->cachedTokenExpiresAt - 60)) {
            return $this->cachedAccessToken;
        }

        try {
            $response = $this->httpClient->request('POST', 'v1/oauth2/token', [
                'auth' => [$this->config->getClientId(), $this->config->getClientSecret()],
                'headers' => [
                    'Accept'       => 'application/json',
                    'Accept-Language' => 'en_US',
                ],
                'form_params' => [
                    'grant_type' => 'client_credentials',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (!isset($body['access_token'])) {
                throw new PayPalException(
                    'PayPal OAuth response did not contain an access_token.',
                    $response->getStatusCode()
                );
            }

            $this->cachedAccessToken = $body['access_token'];
            $this->cachedTokenExpiresAt = microtime(true) + (int) ($body['expires_in'] ?? 3600);

            return $this->cachedAccessToken;
        } catch (GuzzleException $e) {
            throw new PayPalException(
                'Failed to obtain PayPal access token: ' . $e->getMessage(),
                $e->getCode() ?: 0,
                $e
            );
        }
    }
}
