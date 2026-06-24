<?php

namespace App\Support\Payments;

use App\Models\CustomerPurchase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PaymentCallbackLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function log(
        ?CustomerPurchase $purchase,
        string $provider,
        ?string $orderId,
        Request $request,
        string $event,
        array $context = [],
    ): void {
        $accountDirectory = $purchase ? 'accounts/'.$purchase->account_id : 'accounts/unknown';
        $safeOrderId = (string) Str::of($orderId ?: 'unknown')
            ->replaceMatches('/[^A-Za-z0-9_.-]/', '_')
            ->limit(80, '');
        $timestamp = now()->format('Ymd-His-u');
        $path = "payment-callbacks/{$accountDirectory}/{$provider}/{$safeOrderId}/{$timestamp}-{$event}.log";

        Storage::disk('local')->put($path, json_encode([
            'event' => $event,
            'account_id' => $purchase?->account_id,
            'customer_purchase_id' => $purchase?->id,
            'provider' => $provider,
            'order_id' => $orderId,
            'ip' => $request->ip(),
            'headers' => $request->headers->all(),
            'body' => $request->getContent(),
            'input' => $request->all(),
            'context' => $context,
            'logged_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
