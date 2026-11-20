<?php

namespace Xfix\XfixPay\Mpesa;

class MpesaConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $defaults = [
            'consumer_key'       => '',
            'consumer_secret'    => '',
            'passkey'            => '',
            'shortcode'          => '',
            'initiator'          => '',
            'initiator_password' => '',
            'environment'        => 'sandbox', // 'sandbox' or 'live'
            'cert_path'          => null,      // User must provide for B2C/Balance/Reversal
            'b2c_result_url'                   => null,
            'b2c_timeout_url'                  => null,
            'b2b_result_url'                   => null,
            'b2b_timeout_url'                  => null,
            'balance_result_url'               => null,
            'balance_timeout_url'              => null,
            'reversal_result_url'              => null,
            'reversal_timeout_url'             => null,
            'transaction_status_result_url'    => null,
            'transaction_status_timeout_url'   => null,
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

    public function getPasskey(): string
    {
        return $this->config['passkey'];
    }

    public function getShortcode(): string
    {
        return $this->config['shortcode'];
    }

    public function getInitiator(): string
    {
        return $this->config['initiator'];
    }

    public function getSecurityCredential(): string
    { 
        return $this->config['initiator_password'];
    }

    public function getEnvironment(): string
    {
        return $this->config['environment'];
    }
    
    public function getCertPath(): ?string
    {
        return $this->config['cert_path'] ?? null;
    }

    public function getCallbackUrl(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function setCertPath(string $path): self
    {
        $this->config['cert_path'] = $path;
        return $this;
    }

    public function getBaseUrl(): string
    {
        $urls = [
            'sandbox' => 'https://sandbox.safaricom.co.ke',
            'live'    => 'https://api.safaricom.co.ke',
        ];

        $environment = strtolower(trim((string) $this->config['environment']));
        if (!isset($urls[$environment])) {
            throw new \InvalidArgumentException('M-Pesa environment must be sandbox or live.');
        }

        return $urls[$environment];
    }
}