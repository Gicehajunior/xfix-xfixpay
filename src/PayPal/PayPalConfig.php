<?php

namespace Xfix\XfixPay\PayPal;

class PayPalConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $defaults = [
            'client_id'     => '',
            'client_secret' => '',
            'environment'   => 'sandbox', // 'sandbox' or 'production'
            'webhook_url'   => '',
        ];

        $this->config = array_merge($defaults, $config);
    }

    public function getClientId(): string
    {
        return $this->config['client_id'];
    }

    public function getClientSecret(): string
    {
        return $this->config['client_secret'];
    }

    public function getEnvironment(): string
    {
        return $this->config['environment'];
    }

    public function getWebhookUrl(): string
    {
        return $this->config['webhook_url'];
    }

    public function getBaseUrl(): string
    {
        $env = strtolower(trim($this->config['environment']));

        // Normalize 'production' to 'live' so both map to the live PayPal endpoint.
        if ($env === 'production') {
            $env = 'live';
        }

        $urls = [
            'sandbox' => 'https://api-m.sandbox.paypal.com',
            'live'    => 'https://api-m.paypal.com',
        ];

        return $urls[$env] ?? $urls['sandbox'];
    }

    public function getOAuthUrl(): string
    {
        return $this->getBaseUrl() . '/v1/oauth2/token';
    }

    public function getWebhookRegisterUrl(): string
    {
        return $this->getBaseUrl() . '/v1/notifications/webhooks';
    }
}
