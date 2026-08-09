<?php

namespace App\Models;

use App\Enums\FestivalCategoryWorkflow;
use Database\Factories\FestivalCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'code', 'name', 'workflow', 'min_members', 'max_members', 'min_age', 'max_age', 'min_duration_seconds', 'max_duration_seconds', 'registration_closes_at', 'rule_snapshot', 'version', 'is_active', 'locked_at'])]
class FestivalCategory extends Model
{
    /** @use HasFactory<FestivalCategoryFactory> */
    use HasFactory;

    protected $attributes = ['workflow' => 'review', 'min_members' => 1, 'max_members' => 1, 'version' => 1, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'workflow' => FestivalCategoryWorkflow::class,
            'min_members' => 'integer',
            'max_members' => 'integer',
            'min_age' => 'integer',
            'max_age' => 'integer',
            'min_duration_seconds' => 'integer',
            'max_duration_seconds' => 'integer',
            'registration_closes_at' => 'datetime',
            'rule_snapshot' => 'array',
            'version' => 'integer',
            'is_active' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function festivalEdition(): BelongsTo
    {
        return $this->edition();
    }

    public function options(): BelongsToMany
    {
        return $this->belongsToMany(FestivalClassificationOption::class, 'festival_category_option')->withPivot('account_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FestivalEntry::class);
    }
}
