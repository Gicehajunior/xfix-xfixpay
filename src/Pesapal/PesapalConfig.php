<?php

namespace Xfix\XfixPay\Pesapal;

class PesapalConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $defaults = [
            'consumer_key'    => '',
            'consumer_secret' => '',
            'environment'     => 'sandbox', // 'sandbox' or 'live'
        ];

        $this->config = array_merge($defaults, $config);
    }

    public function getConsumerKey(): string
    {
        return $this->config['consumer_key'];
    }

    public function getConsumerSecret(): string
    {
        return $this->config['consumer_secret'];
    }

    public function getEnvironment(): string
    {
        return $this->config['environment'];
    }

    public function getBaseUrl(): string
    {
        $urls = [
            'sandbox' => 'https://cybqa.pesapal.com/pesapalv3/api',
            'live'    => 'https://pay.pesapal.com/v3/api',
        ];

        return $urls[$this->config['environment']] ?? $urls['sandbox'];
    }
}