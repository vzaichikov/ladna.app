<?php

namespace App\Models;

use Database\Factories\ClassPassPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'name', 'slug', 'description', 'price_cents', 'currency', 'sessions_count', 'validity_days', 'available_from_time', 'available_until_time', 'allows_any_time', 'any_time_addon_price_cents', 'is_trial', 'is_active', 'sort_order'])]
class ClassPassPlan extends Model
{
    /** @use HasFactory<ClassPassPlanFactory> */
    use HasFactory;

    protected $attributes = [
        'currency' => 'UAH',
        'validity_days' => 30,
        'allows_any_time' => false,
        'is_trial' => false,
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allows_any_time' => 'boolean',
            'is_trial' => 'boolean',
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

    public function classTypes(): BelongsToMany
    {
        return $this->belongsToMany(ClassType::class, 'class_pass_plan_class_type')
            ->withTimestamps();
    }

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'class_pass_plan_room')
            ->withTimestamps();
    }

    public function trainerTypes(): BelongsToMany
    {
        return $this->belongsToMany(TrainerType::class, 'class_pass_plan_trainer_type')
            ->withTimestamps();
    }

    public function customerClassPasses(): HasMany
    {
        return $this->hasMany(CustomerClassPass::class);
    }

    public function isAvailableFor(ScheduledClass $scheduledClass, bool $requireActivePlan = true): bool
    {
        if (($requireActivePlan && ! $this->is_active) || $scheduledClass->account_id !== $this->account_id) {
            return false;
        }

        $this->loadMissing(['classTypes:id', 'rooms:id', 'trainerTypes:id']);
        $scheduledClass->loadMissing(['classType', 'trainer', 'room']);
        $classTypeId = $scheduledClass->class_type_id;

        if (! $classTypeId || ! $this->classTypes->contains('id', $classTypeId)) {
            return false;
        }

        if ($this->rooms->isNotEmpty() && (! $scheduledClass->room_id || ! $this->rooms->contains('id', $scheduledClass->room_id))) {
            return false;
        }

        $trainerTypeId = $scheduledClass->trainer?->trainer_type_id;

        if ($this->trainerTypes->isNotEmpty() && (! $trainerTypeId || ! $this->trainerTypes->contains('id', $trainerTypeId))) {
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
