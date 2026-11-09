<?php

namespace Xfix\XfixPay\Exception;

class PayPalException extends XfixPayException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
