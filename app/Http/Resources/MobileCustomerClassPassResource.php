<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileCustomerClassPassResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'plan_name' => $this->plan_name,
            'plan_slug' => $this->plan_slug,
            'status' => $this->status->value,
            'payment_status' => $this->paymentStatus(),
            'sessions_count' => $this->sessions_count,
            'used_sessions_count' => $this->used_sessions_count,
            'reserved_sessions_count' => $this->reserved_sessions_count,
            'remaining_sessions_count' => $this->remainingSessionsCount(),
            'price_cents' => $this->price_cents,
            'paid_amount_cents' => $this->paidAmountCents(),
            'remaining_payment_cents' => $this->remainingPaymentCents(),
            'currency' => $this->currency,
            'purchased_at' => $this->purchased_at?->toIso8601String(),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'usable_until_at' => $this->usableUntilAt()?->toIso8601String(),
            'is_active' => $this->is_active,
        ];
    }
}
