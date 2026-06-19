<?php

namespace App\Models;

use Database\Factories\TrainerTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'name', 'icon', 'color', 'is_default', 'sort_order'])]
class TrainerType extends Model
{
    /** @use HasFactory<TrainerTypeFactory> */
    use HasFactory;

    protected $attributes = [
        'icon' => 'user-round',
        'color' => '#3B223F',
        'is_default' => false,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function trainers(): HasMany
    {
        return $this->hasMany(Trainer::class);
    }

    public function classPassPlans(): BelongsToMany
    {
        return $this->belongsToMany(ClassPassPlan::class, 'class_pass_plan_trainer_type')
            ->withTimestamps();
    }
}
