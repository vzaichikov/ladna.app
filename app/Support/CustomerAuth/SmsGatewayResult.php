<?php

namespace App\Support\CustomerAuth;

class SmsGatewayResult
{
    public function __construct(
        public bool $sent,
        public ?string $message = null,
        public ?string $providerMessageId = null,
    ) {}

    public static function sent(?string $providerMessageId = null): self
    {
        return new self(true, providerMessageId: $providerMessageId);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
