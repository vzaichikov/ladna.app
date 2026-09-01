<?php

namespace App\Support;

use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchase;
use App\Models\User;
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
        ?Carbon $asOf = null,
    ): void {
        if (! $classPassPlan->is_trial) {
            return;
        }

        if ($this->evaluate($account, $customer, $source, $asOf)['status'] === 'eligible') {
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
     * @return array{
     *     status: 'available'|'unavailable'|'actor_permissions_not_evaluated',
     *     available: bool,
     *     customer_qualifies: bool,
     *     reason_codes: array<int, string>,
     *     source: string,
     *     normal_eligibility_status: 'eligible'|'ineligible',
     *     class_pass_history_count: int,
     *     successful_payments_count: int,
     *     actor_permissions_evaluated: bool,
     *     actor_has_required_permissions: bool|null,
     *     required_permissions: array<int, string>,
     *     requires_comment: true
     * }
     */
    public function evaluateManualOverride(
        Account $account,
        Customer $customer,
        string $source = self::SourceManual,
        ?Carbon $asOf = null,
        ?User $actor = null,
        bool $evaluateActorPermissions = true,
    ): array {
        $normalEligibility = $this->evaluate($account, $customer, $source, $asOf);
        $classPassHistoryCount = $this->classPassHistoryCount($account, $customer, $asOf);
        $successfulPaymentsCount = $this->successfulPaymentsCount($account, $customer, $asOf);
        $requiredPermissions = [
            StudioPermission::IssueCustomerClassPasses->value,
            StudioPermission::ManageCustomerClassPasses->value,
        ];
        $actorHasRequiredPermissions = $evaluateActorPermissions
            ? $actor !== null
                && $account->userCan($actor, StudioPermission::IssueCustomerClassPasses)
                && $account->userCan($actor, StudioPermission::ManageCustomerClassPasses)
            : null;
        $reasonCodes = [];

        if ($source !== self::SourceManual) {
            $reasonCodes[] = 'manual_source_required';
        }

        if ($normalEligibility['status'] !== 'ineligible') {
            $reasonCodes[] = 'normal_trial_eligibility_available';
        }

        if ($classPassHistoryCount > 0) {
            $reasonCodes[] = 'existing_class_pass_history';
        }

        if ($successfulPaymentsCount > 0) {
            $reasonCodes[] = 'successful_payment_history';
        }

        $customerQualifies = $reasonCodes === [];

        if (! $evaluateActorPermissions) {
            $reasonCodes[] = 'actor_permissions_not_evaluated';
        } elseif (! $actorHasRequiredPermissions) {
            $reasonCodes[] = 'missing_required_permissions';
        }

        $available = $customerQualifies && $actorHasRequiredPermissions === true;

        if ($available) {
            $reasonCodes = ['manual_override_available'];
        }

        return [
            'status' => $available
                ? 'available'
                : ($customerQualifies && ! $evaluateActorPermissions
                    ? 'actor_permissions_not_evaluated'
                    : 'unavailable'),
            'available' => $available,
            'customer_qualifies' => $customerQualifies,
            'reason_codes' => $reasonCodes,
            'source' => $source,
            'normal_eligibility_status' => $normalEligibility['status'],
            'class_pass_history_count' => $classPassHistoryCount,
            'successful_payments_count' => $successfulPaymentsCount,
            'actor_permissions_evaluated' => $evaluateActorPermissions,
            'actor_has_required_permissions' => $actorHasRequiredPermissions,
            'required_permissions' => $requiredPermissions,
            'requires_comment' => true,
        ];
    }

    public function paidOnlineOverrideIsAvailable(
        Account $account,
        Customer $customer,
        CustomerPurchase $purchase,
        User $actor,
    ): bool {
        return $purchase->account_id === $account->id
            && $purchase->customer_id === $customer->id
            && $purchase->provider === 'monopay'
            && $purchase->payment_source === CustomerPurchase::SourceOnlineCheckout
            && $purchase->trial_eligibility_validated_at === null
            && $purchase->customer_class_pass_id === null
            && $this->evaluate($account, $customer, self::SourceOnlinePayment)['status'] === 'ineligible'
            && $this->classPassHistoryCount($account, $customer, null) === 0
            && CustomerPurchase::query()
                ->whereBelongsTo($account)
                ->whereBelongsTo($customer)
                ->whereKeyNot($purchase->id)
                ->where('status', CustomerPurchaseStatus::PaymentPaid->value)
                ->doesntExist()
            && $account->userCan($actor, StudioPermission::IssueCustomerClassPasses)
            && $account->userCan($actor, StudioPermission::ManageCustomerClassPasses);
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

    private function classPassHistoryCount(
        Account $account,
        Customer $customer,
        ?Carbon $asOf,
    ): int {
        return CustomerClassPass::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($customer)
            ->when($asOf, fn (Builder $query, Carbon $asOf) => $query->where('created_at', '<=', $asOf->copy()->utc()))
            ->count();
    }

    private function successfulPaymentsCount(
        Account $account,
        Customer $customer,
        ?Carbon $asOf,
    ): int {
        return CustomerPurchase::query()
            ->whereBelongsTo($account)
            ->whereBelongsTo($customer)
            ->where('status', CustomerPurchaseStatus::PaymentPaid->value)
            ->when($asOf, function (Builder $query, Carbon $asOf): void {
                $asOfUtc = $asOf->copy()->utc();

                $query->where(function (Builder $query) use ($asOfUtc): void {
                    $query->where('paid_at', '<=', $asOfUtc)
                        ->orWhere(function (Builder $query) use ($asOfUtc): void {
                            $query->whereNull('paid_at')
                                ->where('created_at', '<=', $asOfUtc);
                        });
                });
            })
            ->count();
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
