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
            $now = now();
            $customerClassPass->load('reservations');

            $usedReservations = $customerClassPass->reservations
                ->where('status', CustomerClassPassReservationStatus::Used)
                ->filter(fn ($reservation): bool => $reservation->used_at !== null)
                ->sortBy('used_at');
            $usedCount = $usedReservations->count();
            $openedAt = $usedReservations->first()?->used_at;
            $expiresAt = $openedAt?->copy()->addDays($customerClassPass->validity_days);
            $usableUntilAt = $customerClassPass->usableUntilAt();
            $isExpired = $expiresAt && $expiresAt->lessThanOrEqualTo($now);
            $isTotalExpired = $usableUntilAt && $usableUntilAt->lessThanOrEqualTo($now);
            $isUsedUp = $usedCount >= $customerClassPass->sessions_count;
            $wasActivePass = $customerClassPass->is_active && $customerClassPass->status === CustomerClassPassStatus::Active;

            if ($wasActivePass && $isTotalExpired) {
                $customerClassPass->reservations()
                    ->where('status', CustomerClassPassReservationStatus::Reserved->value)
                    ->update([
                        'status' => CustomerClassPassReservationStatus::Released->value,
                        'released_at' => $now,
                    ]);

                $customerClassPass->load('reservations');
            }

            $reservedCount = $customerClassPass->reservations
                ->where('status', CustomerClassPassReservationStatus::Reserved)
                ->count();
            $status = $customerClassPass->status;
            $isActive = (bool) $customerClassPass->is_active;
            $closedAt = $customerClassPass->closed_at;

            if (! $wasActivePass) {
                $isActive = false;
                $closedAt = $closedAt ?? ($status === CustomerClassPassStatus::Active ? null : $now);
            } elseif ($isUsedUp) {
                $status = CustomerClassPassStatus::UsedUp;
                $isActive = false;
                $closedAt = $closedAt ?? $now;
            } elseif ($isExpired || $isTotalExpired) {
                $status = CustomerClassPassStatus::Expired;
                $isActive = false;
                $closedAt = $closedAt ?? $now;
            }

            $customerClassPass->forceFill([
                'reserved_sessions_count' => $reservedCount,
                'used_sessions_count' => $usedCount,
                'opened_at' => $openedAt,
                'expires_at' => $expiresAt,
                'usable_until_at' => $usableUntilAt,
                'status' => $status->value,
                'is_active' => $isActive,
                'closed_at' => $closedAt,
            ])->save();

            return $customerClassPass->refresh();
        });
    }
}
