<?php

namespace App\Models;

use App\Enums\CustomerClassPassStatus;
use Database\Factories\CustomerClassPassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['account_id', 'customer_id', 'class_pass_plan_id', 'code', 'source', 'issued_location_id', 'is_paid', 'issued_by_actor_user_id', 'issued_by_actor_trainer_id', 'issued_by_actor_name', 'issued_by_actor_email', 'issued_by_actor_role', 'status', 'plan_name', 'plan_slug', 'price_cents', 'paid_amount_cents', 'currency', 'sessions_count', 'validity_days', 'total_validity_days', 'available_from_time', 'available_until_time', 'allows_any_time', 'any_time_addon_price_cents', 'reserved_sessions_count', 'used_sessions_count', 'purchased_at', 'opened_at', 'expires_at', 'usable_until_at', 'closed_at', 'frozen_at', 'is_active'])]
class CustomerClassPass extends Model
{
    /** @use HasFactory<CustomerClassPassFactory> */
    use HasFactory;

    protected $attributes = [
        'source' => 'manual',
        'is_paid' => false,
        'status' => 'active',
        'currency' => 'UAH',
        'paid_amount_cents' => 0,
        'total_validity_days' => 180,
        'allows_any_time' => false,
        'reserved_sessions_count' => 0,
        'used_sessions_count' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CustomerClassPassStatus::class,
            'purchased_at' => 'datetime',
            'opened_at' => 'datetime',
            'expires_at' => 'datetime',
            'usable_until_at' => 'datetime',
            'closed_at' => 'datetime',
            'frozen_at' => 'datetime',
            'is_paid' => 'boolean',
            'paid_amount_cents' => 'integer',
            'allows_any_time' => 'boolean',
            'any_time_addon_price_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('status', CustomerClassPassStatus::Active->value);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('is_paid', true);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query
            ->where('is_paid', false)
            ->where('paid_amount_cents', '<=', 0);
    }

    public function scopePartiallyPaid(Builder $query): Builder
    {
        return $query
            ->where('is_paid', false)
            ->where('paid_amount_cents', '>', 0);
    }

    public function scopeFreezed(Builder $query): Builder
    {
        return $query->where('status', CustomerClassPassStatus::Freezed->value);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function classPassPlan(): BelongsTo
    {
        return $this->belongsTo(ClassPassPlan::class);
    }

    public function issuedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'issued_location_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(CustomerClassPassReservation::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(CustomerPurchase::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(CustomerClassPassAdjustment::class);
    }

    public function remainingSessionsCount(): int
    {
        return max(0, $this->sessions_count - $this->used_sessions_count - $this->reserved_sessions_count);
    }

    public function availableReservationSessionsCount(): int
    {
        return max(0, $this->sessions_count - $this->used_sessions_count - $this->reserved_sessions_count);
    }

    public function paidAmountCents(): int
    {
        return max(0, min((int) $this->paid_amount_cents, (int) $this->price_cents));
    }

    public function remainingPaymentCents(): int
    {
        return max(0, (int) $this->price_cents - $this->paidAmountCents());
    }

    public function isPartiallyPaid(): bool
    {
        return ! $this->is_paid && $this->paidAmountCents() > 0;
    }

    public function paymentStatus(): string
    {
        if ($this->is_paid) {
            return 'paid';
        }

        return $this->isPartiallyPaid() ? 'partial' : 'unpaid';
    }

    public function usableUntilAt(): ?Carbon
    {
        if ($this->usable_until_at) {
            return $this->usable_until_at;
        }

        $purchasedAt = $this->purchased_at ?? $this->created_at;

        return $purchasedAt?->copy()->addDays((int) $this->total_validity_days);
    }

    public function canReserveFor(ScheduledClass $scheduledClass): bool
    {
        $this->loadMissing('classPassPlan');

        if (! $this->classPassPlan || ! $this->is_active || $this->status !== CustomerClassPassStatus::Active) {
            return false;
        }

        if ($this->account_id !== $scheduledClass->account_id || $this->availableReservationSessionsCount() < 1) {
            return false;
        }

        if ($this->expires_at && $scheduledClass->starts_at->greaterThan($this->expires_at)) {
            return false;
        }

        $usableUntilAt = $this->usableUntilAt();

        if ($usableUntilAt && $scheduledClass->starts_at->greaterThanOrEqualTo($usableUntilAt)) {
            return false;
        }

        if (! $this->classPassPlan->matchesScheduledClass($scheduledClass, requireActivePlan: false)) {
            return false;
        }

        return $this->isWithinTimeWindow($scheduledClass) || $this->allowsAnyTimeAddonFor($scheduledClass);
    }

    public function anyTimeAddonAmountCentsFor(ScheduledClass $scheduledClass): ?int
    {
        if (! $this->allowsAnyTimeAddonFor($scheduledClass)) {
            return null;
        }

        return max(0, (int) $this->any_time_addon_price_cents);
    }

    public function requiresAnyTimeAddonPaymentFor(ScheduledClass $scheduledClass): bool
    {
        return ($this->anyTimeAddonAmountCentsFor($scheduledClass) ?? 0) > 0;
    }

    public function isWithinTimeWindow(ScheduledClass $scheduledClass): bool
    {
        $startsAt = $scheduledClass->starts_at
            ->copy()
            ->timezone($scheduledClass->displayTimezone())
            ->format('H:i:s');
        $availableFromTime = $this->normalizedTime($this->available_from_time);
        $availableUntilTime = $this->normalizedTime($this->available_until_time);

        if ($availableFromTime && $startsAt < $availableFromTime) {
            return false;
        }

        if ($availableUntilTime && $startsAt >= $availableUntilTime) {
            return false;
        }

        return true;
    }

    private function allowsAnyTimeAddonFor(ScheduledClass $scheduledClass): bool
    {
        return $this->allows_any_time
            && $this->any_time_addon_price_cents !== null
            && ! $this->isWithinTimeWindow($scheduledClass);
    }

    private function normalizedTime(mixed $time): ?string
    {
        if (blank($time)) {
            return null;
        }

        $time = (string) $time;

        return strlen($time) === 5 ? $time.':00' : substr($time, 0, 8);
    }
}
