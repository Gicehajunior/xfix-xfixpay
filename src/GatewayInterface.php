<?php

namespace Xfix\XfixPay;

interface GatewayInterface
{
    public function setConfig(array $config): self;
    public function authenticate(): array;
    public function request(string $method, string $endpoint, array $data = []): array;
}