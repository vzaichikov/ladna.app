<?php

namespace App\Models;

use Database\Factories\ClassPassPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['account_id', 'name', 'slug', 'description', 'price_cents', 'currency', 'sessions_count', 'validity_days', 'available_from_time', 'available_until_time', 'is_active', 'sort_order'])]
class ClassPassPlan extends Model
{
    /** @use HasFactory<ClassPassPlanFactory> */
    use HasFactory;

    protected $attributes = [
        'currency' => 'UAH',
        'validity_days' => 30,
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function activityDirections(): BelongsToMany
    {
        return $this->belongsToMany(ActivityDirection::class, 'class_pass_plan_activity_direction')
            ->withTimestamps();
    }

    public function isAvailableFor(ScheduledClass $scheduledClass): bool
    {
        if (! $this->is_active || $scheduledClass->account_id !== $this->account_id) {
            return false;
        }

        $scheduledClass->loadMissing('classType');
        $activityDirectionId = $scheduledClass->classType?->activity_direction_id;

        if (! $activityDirectionId || ! $this->activityDirections()->whereKey($activityDirectionId)->exists()) {
            return false;
        }

        $startsAt = $scheduledClass->starts_at->format('H:i:s');

        if ($this->available_from_time && $startsAt < $this->available_from_time) {
            return false;
        }

        if ($this->available_until_time && $startsAt >= $this->available_until_time) {
            return false;
        }

        return true;
    }
}
