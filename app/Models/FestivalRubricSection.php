<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_rubric_id', 'name', 'weight', 'sort_order'])]
class FestivalRubricSection extends Model
{
    protected $attributes = ['weight' => 1, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['weight' => 'decimal:4', 'sort_order' => 'integer'];
    }

    public function rubric(): BelongsTo
    {
        return $this->belongsTo(FestivalRubric::class, 'festival_rubric_id');
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(FestivalRubricCriterion::class)->orderBy('sort_order')->orderBy('id');
    }
}
