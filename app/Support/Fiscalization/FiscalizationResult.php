<?php

namespace App\Support\Fiscalization;

use App\Enums\FiscalReceiptStatus;

class FiscalizationResult
{
    /**
     * Create a new class instance.
     *
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly FiscalReceiptStatus $status,
        public readonly ?string $providerReceiptId = null,
        public readonly ?string $providerStatus = null,
        public readonly ?string $fiscalNumber = null,
        public readonly ?string $error = null,
        public readonly array $payload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fiscalized(
        ?string $providerReceiptId,
        ?string $providerStatus,
        ?string $fiscalNumber,
        array $payload,
    ): self {
        return new self(
            FiscalReceiptStatus::Fiscalized,
            $providerReceiptId,
            $providerStatus,
            $fiscalNumber,
            null,
            $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function processing(
        ?string $providerReceiptId,
        ?string $providerStatus,
        array $payload,
    ): self {
        return new self(
            FiscalReceiptStatus::Processing,
            $providerReceiptId,
            $providerStatus,
            null,
            null,
            $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function failed(
        ?string $providerReceiptId,
        ?string $providerStatus,
        string $error,
        array $payload = [],
    ): self {
        return new self(
            FiscalReceiptStatus::Failed,
            $providerReceiptId,
            $providerStatus,
            null,
            $error,
            $payload,
        );
    }
}
