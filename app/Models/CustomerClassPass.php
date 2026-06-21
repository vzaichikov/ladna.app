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

#[Fillable(['account_id', 'customer_id', 'class_pass_plan_id', 'code', 'source', 'status', 'plan_name', 'plan_slug', 'price_cents', 'currency', 'sessions_count', 'validity_days', 'reserved_sessions_count', 'used_sessions_count', 'purchased_at', 'opened_at', 'expires_at', 'closed_at', 'is_active'])]
class CustomerClassPass extends Model
{
    /** @use HasFactory<CustomerClassPassFactory> */
    use HasFactory;

    protected $attributes = [
        'source' => 'manual',
        'status' => 'active',
        'currency' => 'UAH',
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

    public function remainingSessionsCount(): int
    {
        return max(0, $this->sessions_count - $this->used_sessions_count);
    }

    public function availableReservationSessionsCount(): int
    {
        return max(0, $this->sessions_count - $this->used_sessions_count - $this->reserved_sessions_count);
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

        return $this->classPassPlan->isAvailableFor($scheduledClass, requireActivePlan: false);
    }
}
