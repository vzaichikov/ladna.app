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

#[Fillable(['account_id', 'customer_id', 'class_pass_plan_id', 'code', 'source', 'issued_by_actor_user_id', 'issued_by_actor_trainer_id', 'issued_by_actor_name', 'issued_by_actor_email', 'issued_by_actor_role', 'status', 'plan_name', 'plan_slug', 'price_cents', 'currency', 'sessions_count', 'validity_days', 'total_validity_days', 'reserved_sessions_count', 'used_sessions_count', 'purchased_at', 'opened_at', 'expires_at', 'usable_until_at', 'closed_at', 'is_active'])]
class CustomerClassPass extends Model
{
    /** @use HasFactory<CustomerClassPassFactory> */
    use HasFactory;

    protected $attributes = [
        'source' => 'manual',
        'status' => 'active',
        'currency' => 'UAH',
        'total_validity_days' => 180,
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
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('status', CustomerClassPassStatus::Active->value);
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

    public function reservations(): HasMany
    {
        return $this->hasMany(CustomerClassPassReservation::class);
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

        return $this->classPassPlan->isAvailableFor($scheduledClass, requireActivePlan: false);
    }
}
