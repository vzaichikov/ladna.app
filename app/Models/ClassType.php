<?php

namespace App\Models;

use App\Enums\ScheduleKind;
use Database\Factories\ClassTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'activity_direction_id', 'name', 'slug', 'description', 'color', 'schedule_kind', 'default_duration_minutes', 'booking_cutoff_minutes', 'cancellation_cutoff_minutes', 'default_capacity', 'is_active'])]
class ClassType extends Model
{
    /** @use HasFactory<ClassTypeFactory> */
    use HasFactory;

    protected $attributes = [
        'schedule_kind' => 'group_class',
        'default_duration_minutes' => 60,
        'cancellation_cutoff_minutes' => 1440,
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

    public function colorAccent(string $fallback = '#3B223F'): string
    {
        if (is_string($this->color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $this->color)) {
            return strtoupper($this->color);
        }

        return $this->activityDirection?->colorAccent($fallback) ?? $fallback;
    }

    public function colorText(string $fallback = '#3B223F'): string
    {
        $color = ltrim($this->colorAccent($fallback), '#');
        $red = hexdec(substr($color, 0, 2));
        $green = hexdec(substr($color, 2, 2));
        $blue = hexdec(substr($color, 4, 2));
        $luminance = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

        return $luminance > 150 ? '#1E293B' : '#FFFFFF';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function activityDirection(): BelongsTo
    {
        return $this->belongsTo(ActivityDirection::class);
    }

    public function classPassPlans(): BelongsToMany
    {
        return $this->belongsToMany(ClassPassPlan::class, 'class_pass_plan_class_type')
            ->withTimestamps();
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
