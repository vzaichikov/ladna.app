<?php

namespace App\Actions;

use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
use App\Models\CustomerClassPass;
use Illuminate\Support\Facades\DB;

class NormalizeCustomerClassPasses
{
    public function execute(): int
    {
        $normalized = 0;

        CustomerClassPass::query()
            ->with('reservations')
            ->orderBy('id')
            ->chunkById(100, function ($customerClassPasses) use (&$normalized): void {
                foreach ($customerClassPasses as $customerClassPass) {
                    $this->forPass($customerClassPass);
                    $normalized++;
                }
            });

        return $normalized;
    }

    public function forPass(CustomerClassPass $customerClassPass): CustomerClassPass
    {
        return DB::transaction(function () use ($customerClassPass): CustomerClassPass {
            $customerClassPass->load('reservations');

            $reservedCount = $customerClassPass->reservations
                ->where('status', CustomerClassPassReservationStatus::Reserved)
                ->count();
            $usedReservations = $customerClassPass->reservations
                ->where('status', CustomerClassPassReservationStatus::Used)
                ->filter(fn ($reservation): bool => $reservation->used_at !== null)
                ->sortBy('used_at');
            $usedCount = $usedReservations->count();
            $openedAt = $usedReservations->first()?->used_at;
            $expiresAt = $openedAt?->copy()->addDays($customerClassPass->validity_days);
            $isExpired = $expiresAt && $expiresAt->lessThanOrEqualTo(now());
            $isUsedUp = $usedCount >= $customerClassPass->sessions_count;
            $status = CustomerClassPassStatus::Active;
            $isActive = true;
            $closedAt = null;

            if ($isUsedUp) {
                $status = CustomerClassPassStatus::UsedUp;
                $isActive = false;
                $closedAt = $customerClassPass->closed_at ?? now();
            } elseif ($isExpired) {
                $status = CustomerClassPassStatus::Expired;
                $isActive = false;
                $closedAt = $customerClassPass->closed_at ?? now();
            }

            $customerClassPass->forceFill([
                'reserved_sessions_count' => $reservedCount,
                'used_sessions_count' => $usedCount,
                'opened_at' => $openedAt,
                'expires_at' => $expiresAt,
                'status' => $status->value,
                'is_active' => $isActive,
                'closed_at' => $closedAt,
            ])->save();

            return $customerClassPass->refresh();
        });
    }
}
