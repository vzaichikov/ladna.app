<?php

namespace App\Models;

use App\Enums\ScheduleKind;
use Database\Factories\ClassPassSegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'schedule_kind', 'name', 'slug', 'sort_order', 'is_active'])]
class ClassPassSegment extends Model
{
    /** @use HasFactory<ClassPassSegmentFactory> */
    use HasFactory;

    protected $attributes = [
        'schedule_kind' => 'group_class',
        'sort_order' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schedule_kind' => ScheduleKind::class,
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function activityDirections(): BelongsToMany
    {
        return $this->belongsToMany(ActivityDirection::class, 'activity_direction_class_pass_segment')
            ->withTimestamps();
    }

    public function classPassPlans(): HasMany
    {
        return $this->hasMany(ClassPassPlan::class);
    }
}
