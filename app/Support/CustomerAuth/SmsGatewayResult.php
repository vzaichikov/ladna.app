<?php

namespace App\Support\CustomerAuth;

class SmsGatewayResult
{
    public SmsGatewayAcceptanceStatus $acceptanceStatus;

    public function __construct(
        public bool $sent,
        public ?string $message = null,
        public ?string $providerMessageId = null,
        ?SmsGatewayAcceptanceStatus $acceptanceStatus = null,
        public ?int $providerSegmentCount = null,
        public ?int $wholesaleCostMinor = null,
        public ?string $wholesaleCostCurrency = null,
        public ?SmsGatewayDeliveryStatus $deliveryStatus = null,
    ) {
        $this->acceptanceStatus = $acceptanceStatus
            ?? ($sent ? SmsGatewayAcceptanceStatus::Accepted : SmsGatewayAcceptanceStatus::Rejected);
    }

    public static function sent(
        ?string $providerMessageId = null,
        ?int $providerSegmentCount = null,
        ?int $wholesaleCostMinor = null,
        ?string $wholesaleCostCurrency = null,
        ?SmsGatewayDeliveryStatus $deliveryStatus = SmsGatewayDeliveryStatus::Accepted,
    ): self {
        return new self(
            sent: true,
            providerMessageId: $providerMessageId,
            acceptanceStatus: SmsGatewayAcceptanceStatus::Accepted,
            providerSegmentCount: $providerSegmentCount,
            wholesaleCostMinor: $wholesaleCostMinor,
            wholesaleCostCurrency: $wholesaleCostCurrency,
            deliveryStatus: $deliveryStatus,
        );
    }

    public static function failed(
        string $message,
        ?SmsGatewayDeliveryStatus $deliveryStatus = null,
    ): self {
        return new self(
            sent: false,
            message: $message,
            acceptanceStatus: SmsGatewayAcceptanceStatus::Rejected,
            deliveryStatus: $deliveryStatus,
        );
    }

    public static function unknown(string $message): self
    {
        return new self(
            sent: false,
            message: $message,
            acceptanceStatus: SmsGatewayAcceptanceStatus::Unknown,
            deliveryStatus: SmsGatewayDeliveryStatus::Unknown,
        );
    }
}
