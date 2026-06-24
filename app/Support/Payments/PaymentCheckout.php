<?php

namespace App\Support\Payments;

class PaymentCheckout
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $gatewayPayload
     */
    private function __construct(
        public readonly string $type,
        public readonly string $url,
        public readonly string $method = 'GET',
        public readonly array $fields = [],
        public readonly array $gatewayPayload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $gatewayPayload
     */
    public static function redirect(string $url, array $gatewayPayload = []): self
    {
        return new self('redirect', $url, gatewayPayload: $gatewayPayload);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $gatewayPayload
     */
    public static function form(string $url, array $fields, array $gatewayPayload = [], string $method = 'POST'): self
    {
        return new self('form', $url, $method, $fields, $gatewayPayload);
    }

    public function isRedirect(): bool
    {
        return $this->type === 'redirect';
    }
}
