<?php

namespace Xfix\XfixPay;

use Xfix\XfixPay\Mpesa\MpesaGateway;

class XfixPayClient
{
    private array $gateways = [];

    public function mpesa(array $config = []): MpesaGateway
    {
        $key = 'mpesa_' . md5(json_encode($config));
        
        if (!isset($this->gateways[$key])) {
            $gateway = new MpesaGateway();
            $gateway->setConfig($config);
            $this->gateways[$key] = $gateway;
        }

        return $this->gateways[$key];
    }

    // Future gateways:
    // public function paypal(array $config = []): PayPalGateway { ... }
    // public function bank(array $config = []): BankGateway { ... }
    // public function stripe(array $config = []): StripeGateway { ... }
}