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
        
        return $urls[$this->config['environment']] ?? $urls['sandbox'];
    }
}