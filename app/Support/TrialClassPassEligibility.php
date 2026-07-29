<?php

namespace App\Support;

use App\Enums\CustomerClassPassReservationStatus;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TrialClassPassEligibility
{
    public const SourceManual = 'manual';

    public const SourceOnlinePayment = 'online_payment';

    /**
     * @return array<int, string>
     */
    public static function sources(): array
    {
        return [
            self::SourceManual,
            self::SourceOnlinePayment,
        ];
    }

    public function assertAvailable(
        Account $account,
        Customer $customer,
        ClassPassPlan $classPassPlan,
        string $source,
    ): void {
        if (! $classPassPlan->is_trial) {
            return;
        }

        if ($this->evaluate($account, $customer, $source)['status'] === 'eligible') {
            return;
        }

        throw ValidationException::withMessages([
            'class_pass_plan_id' => __('app.trial_class_pass_not_available'),
        ]);
    }

    /**
     * @return array{
     *     status: 'eligible'|'ineligible',
     *     reason_codes: array<int, string>,
     *     counted_bookings_count: int,
     *     active_reservations_count: int
     * }
     */
    public function evaluate(
        Account $account,
        Customer $customer,
        string $source = self::SourceManual,
        ?Carbon $asOf = null,
    ): array {
        $this->ensureValidContext($account, $customer, $source);

        $bookings = $this->countedBookings($account, $customer, $asOf);
        $bookingCount = (clone $bookings)->count();
        $activeReservationsCount = $this->activeReservationBookings($bookings, $asOf)->count();

        if ($bookingCount === 0) {
            return $this->result('eligible', 'no_existing_bookings', 0, 0);
        }

        if ($source !== self::SourceManual) {
            return $this->result(
                'ineligible',
                'existing_booking_non_manual',
                $bookingCount,
                $activeReservationsCount,
            );
        }

        if ($bookingCount !== 1) {
            return $this->result(
                'ineligible',
                'multiple_existing_bookings',
                $bookingCount,
                $activeReservationsCount,
            );
        }

        if ($activeReservationsCount > 0) {
            return $this->result(
                'ineligible',
                'active_reservation_exists',
                $bookingCount,
                $activeReservationsCount,
            );
        }

        return $this->result(
            'eligible',
            'single_unreserved_booking_manual_exception',
            $bookingCount,
            0,
        );
    }

    /**
     * @return Builder<ClassBooking>
     */
    public function countedBookings(
        Account $account,
        Customer $customer,
        ?Carbon $asOf = null,
    ): Builder {
        $query = ClassBooking::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($customer);

        if ($asOf === null) {
            return $query->notCorrectedRemoved();
        }

        $asOfUtc = $asOf->copy()->utc();

        return $query
            ->where('created_at', '<=', $asOfUtc)
            ->where(function (Builder $query) use ($asOfUtc): void {
                $query->whereNull('corrected_removed_at')
                    ->orWhere('corrected_removed_at', '>', $asOfUtc);
            });
    }

    /**
     * @param  Builder<ClassBooking>  $bookings
     * @return Builder<ClassBooking>
     */
    private function activeReservationBookings(Builder $bookings, ?Carbon $asOf): Builder
    {
        if ($asOf === null) {
            return (clone $bookings)->whereHas(
                'classPassReservation',
                fn (Builder $query) => $query->whereIn('status', [
                    CustomerClassPassReservationStatus::Reserved->value,
                    CustomerClassPassReservationStatus::Used->value,
                ]),
            );
        }

        $asOfUtc = $asOf->copy()->utc();

        return (clone $bookings)->whereHas('classPassReservation', function (Builder $query) use ($asOfUtc): void {
            $query
                ->where(function (Builder $query) use ($asOfUtc): void {
                    $query->where('reserved_at', '<=', $asOfUtc)
                        ->orWhere(function (Builder $query) use ($asOfUtc): void {
                            $query->whereNull('reserved_at')
                                ->where('created_at', '<=', $asOfUtc);
                        });
                })
                ->where(function (Builder $query) use ($asOfUtc): void {
                    $query->whereNull('released_at')
                        ->orWhere('released_at', '>', $asOfUtc);
                });
        });
    }

    private function ensureValidContext(Account $account, Customer $customer, string $source): void
    {
        if ((int) $customer->account_id !== (int) $account->id) {
            throw new InvalidArgumentException('The customer does not belong to the account.');
        }

        if (! in_array($source, self::sources(), true)) {
            throw new InvalidArgumentException('The trial class-pass source is invalid.');
        }
    }

    /**
     * @return array{
     *     status: 'eligible'|'ineligible',
     *     reason_codes: array<int, string>,
     *     counted_bookings_count: int,
     *     active_reservations_count: int
     * }
     */
    private function result(
        string $status,
        string $reasonCode,
        int $bookingCount,
        int $activeReservationsCount,
    ): array {
        return [
            'status' => $status,
            'reason_codes' => [$reasonCode],
            'counted_bookings_count' => $bookingCount,
            'active_reservations_count' => $activeReservationsCount,
        ];
    }
}
