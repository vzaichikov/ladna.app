<?php

namespace App\Support\CustomerAuth;

readonly class SmsGatewayBalanceResult
{
    public function __construct(
        public bool $successful,
        public ?string $amount = null,
        public ?string $currency = null,
        public ?string $message = null,
    ) {}

    public static function success(string $amount, string $currency): self
    {
        return new self(true, $amount, $currency);
    }

    public static function failed(string $message): self
    {
        return new self(false, message: $message);
    }
}
