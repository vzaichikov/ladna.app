<?php

namespace App\Support\Payments;

use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use Illuminate\Support\Carbon;

class TicketPaymentTiming
{
    public const int DefaultInvoiceValiditySeconds = 1800;

    public const int MonopayCallbackGraceSeconds = 300;

    private const int MinimumMonopayValiditySeconds = 60;

    private const int MaximumMonopayValiditySeconds = 2592000;

    /**
     * @return array{validity_seconds: int, payment_expires_at: Carbon, expires_at: Carbon}
     */
    public function resolve(IntegrationSetting $setting): array
    {
        $startedAt = now();
        $validitySeconds = self::DefaultInvoiceValiditySeconds;
        $graceSeconds = 0;

        if ($setting->provider === IntegrationProvider::Monopay) {
            $credentials = $setting->readableCredentials();
            $validitySeconds = max(
                self::MinimumMonopayValiditySeconds,
                min(
                    self::MaximumMonopayValiditySeconds,
                    (int) ($credentials['invoice_validity_seconds'] ?? self::DefaultInvoiceValiditySeconds),
                ),
            );
            $graceSeconds = self::MonopayCallbackGraceSeconds;
        }

        $paymentExpiresAt = $startedAt->copy()->addSeconds($validitySeconds);

        return [
            'validity_seconds' => $validitySeconds,
            'payment_expires_at' => $paymentExpiresAt,
            'expires_at' => $paymentExpiresAt->copy()->addSeconds($graceSeconds),
        ];
    }
}
