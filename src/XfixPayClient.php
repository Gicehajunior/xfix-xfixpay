<?php

namespace Xfix\XfixPay;

use Xfix\XfixPay\Mpesa\MpesaGateway;

class XfixPayClient
{
    /** @var array<string, object> */
    private array $gateways = [];

    /** Global defaults merged into every gateway config. */
    private array $defaultConfig;

    public function __construct(array $defaultConfig = [])
    {
        $this->defaultConfig = $defaultConfig;
    }

    public function mpesa(array $config = []): MpesaGateway
    {
        return $this->makeGateway('mpesa', $config, MpesaGateway::class);
    }

    // Future gateways:
    // public function paypal(array $config = []): PayPalGateway
    //     => $this->makeGateway('paypal', $config, PayPalGateway::class);
    // public function bank(array $config = []): BankGateway
    //     => $this->makeGateway('bank', $config, BankGateway::class);
    // public function stripe(array $config = []): StripeGateway
    //     => $this->makeGateway('stripe', $config, StripeGateway::class);
    // public function streamPay(array $config = []): StreamPayGateway
    //     => $this->makeGateway('streamPay', $config, StreamPayGateway::class);

    /**
     * Generic gateway factory with config merging and instance caching.
     *
     * @template T of object
     * @param  class-string<T> $class
     * @return T
     */
    private function makeGateway(string $type, array $config, string $class): object
    {
        // Merge defaults with per-call overrides. Per-call wins.
        $merged = array_replace_recursive($this->defaultConfig, $config);

        // Explicitly normalise environment so 'production' and 'live' behave the same.
        if (isset($merged['environment'])) {
            $merged['environment'] = $this->normaliseEnvironment($merged['environment']);
        }

        // Cache key must reflect the *merged* config, not the raw input.
        $key = $type . '_' . md5(json_encode($merged, JSON_THROW_ON_ERROR));

        if (!isset($this->gateways[$key])) {
            $gateway = new $class();
            if (!method_exists($gateway, 'setConfig')) {
                throw new \InvalidArgumentException(
                    sprintf('%s must implement a setConfig(array $config) method.', $class)
                );
            }
            $gateway->setConfig($merged);
            $this->gateways[$key] = $gateway;
        }

        return $this->gateways[$key];
    }

    /**
     * Map numeric and common string variants to the canonical environment value.
     */
    private function normaliseEnvironment($value): string
    {
        if (is_string($value) && !ctype_digit($value)) {
            return match (strtolower(trim($value))) {
                'live', 'production', 'prod', '1' => 'live',
                'sandbox', 'test', 'dev', '0'    => 'sandbox',
                default                            => strtolower($value),
            };
        }

        return match ((int) $value) {
            1 => 'live',
            0 => 'sandbox',
            default => (string) $value,
        };
    }
}