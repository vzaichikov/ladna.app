<?php

namespace App\Support\Salary;

use App\Enums\CustomerClassPassReservationStatus;
use App\Models\Account;
use App\Models\CustomerClassPassReservation;
use Illuminate\Support\Collection;

class ClassPassSessionValueResolver
{
    /**
     * @param  Collection<int, CustomerClassPassReservation>  $reservations
     * @return Collection<int, int>
     */
    public function positionsFor(Account $account, Collection $reservations): Collection
    {
        $customerClassPassIds = $reservations
            ->pluck('customer_class_pass_id')
            ->unique()
            ->values();

        if ($customerClassPassIds->isEmpty()) {
            return collect();
        }

        return CustomerClassPassReservation::query()
            ->whereBelongsTo($account)
            ->whereIn('customer_class_pass_id', $customerClassPassIds)
            ->whereIn('status', [
                CustomerClassPassReservationStatus::Reserved->value,
                CustomerClassPassReservationStatus::Used->value,
            ])
            ->orderBy('customer_class_pass_id')
            ->orderBy('reserved_at')
            ->orderBy('id')
            ->get(['id', 'customer_class_pass_id', 'reserved_at'])
            ->groupBy('customer_class_pass_id')
            ->reduce(function (Collection $positions, Collection $passReservations): Collection {
                $passReservations->values()->each(function (CustomerClassPassReservation $reservation, int $index) use ($positions): void {
                    $positions->put($reservation->id, $index + 1);
                });

                return $positions;
            }, collect());
    }

    /**
     * @param  Collection<int, int>  $positions
     */
    public function amountCents(CustomerClassPassReservation $reservation, Collection $positions): ?int
    {
        $customerClassPass = $reservation->customerClassPass;
        $sessionsCount = (int) ($customerClassPass?->sessions_count ?? 0);

        if (! $customerClassPass || $sessionsCount < 1) {
            return null;
        }

        $priceCents = max(0, (int) $customerClassPass->price_cents);
        $baseAmount = intdiv($priceCents, $sessionsCount);
        $remainder = $priceCents % $sessionsCount;
        $position = (int) $positions->get($reservation->id, 0);

        return $baseAmount + ($position > 0 && $position <= $remainder ? 1 : 0);
    }
}
