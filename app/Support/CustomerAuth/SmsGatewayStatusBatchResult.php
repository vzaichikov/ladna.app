<?php

namespace App\Support\CustomerAuth;

readonly class SmsGatewayStatusBatchResult
{
    /**
     * @param  array<string, SmsGatewayDeliveryStatus>  $statuses
     * @param  array<string, string>  $providerStatuses
     */
    public function __construct(
        public bool $successful,
        public array $statuses = [],
        public array $providerStatuses = [],
        public ?string $message = null,
    ) {}

    /**
     * @param  array<string, SmsGatewayDeliveryStatus>  $statuses
     * @param  array<string, string>  $providerStatuses
     */
    public static function success(array $statuses, array $providerStatuses): self
    {
        return new self(true, $statuses, $providerStatuses);
    }

    public static function failed(string $message): self
    {
        return new self(false, message: $message);
    }
}
