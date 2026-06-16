<?php

namespace App\Models;

use App\Enums\ScheduleKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'activity_direction_id', 'name', 'slug', 'description', 'color', 'schedule_kind', 'default_duration_minutes', 'booking_cutoff_minutes', 'default_capacity', 'is_active'])]
class ClassType extends Model
{
    /** @use HasFactory<\Database\Factories\ClassTypeFactory> */
    use HasFactory;

    protected $attributes = [
        'schedule_kind' => 'group_class',
        'default_duration_minutes' => 60,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'schedule_kind' => ScheduleKind::class,
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublicScheduleKind(Builder $query): Builder
    {
        return $query->where('schedule_kind', ScheduleKind::GroupClass->value);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function activityDirection(): BelongsTo
    {
        return $this->belongsTo(ActivityDirection::class);
    }

    public function scheduleSeries(): HasMany
    {
        return $this->hasMany(ScheduleSeries::class);
    }

    public function scheduledClasses(): HasMany
    {
        return $this->hasMany(ScheduledClass::class);
    }
}
