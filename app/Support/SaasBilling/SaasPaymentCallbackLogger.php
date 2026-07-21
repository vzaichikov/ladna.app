<?php

namespace App\Support\SaasBilling;

use App\Models\AccountSubscriptionPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SaasPaymentCallbackLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function log(
        ?AccountSubscriptionPayment $payment,
        string $provider,
        ?string $orderId,
        Request $request,
        string $event,
        array $context = [],
    ): void {
        $sanitizedInput = $this->sanitize($request->all());
        $accountDirectory = $payment?->account_id ? 'accounts/'.$payment->account_id : 'accounts/unknown';
        $safeOrderId = (string) Str::of($orderId ?: 'unknown')
            ->replaceMatches('/[^A-Za-z0-9_.-]/', '_')
            ->limit(80, '');
        $timestamp = now()->format('Ymd-His-u');
        $path = "payment-callbacks/saas/{$accountDirectory}/{$provider}/{$safeOrderId}/{$timestamp}-{$event}.log";

        Storage::disk('local')->put($path, json_encode([
            'event' => $event,
            'account_id' => $payment?->account_id,
            'account_subscription_payment_id' => $payment?->id,
            'account_signup_request_id' => $payment?->account_signup_request_id,
            'provider' => $provider,
            'order_id' => $orderId,
            'ip' => $request->ip(),
            'headers' => $request->headers->all(),
            'body' => json_encode($sanitizedInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'input' => $sanitizedInput,
            'context' => $context,
            'logged_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), ['cardtoken', 'walletid'], true)) {
                $payload[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->sanitize($value);
            }
        }

        return $payload;
    }
}
