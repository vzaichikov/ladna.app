<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_rubric_section_id', 'name', 'max_score', 'weight', 'sort_order'])]
class FestivalRubricCriterion extends Model
{
    protected $attributes = ['weight' => 1, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['max_score' => 'decimal:2', 'weight' => 'decimal:4', 'sort_order' => 'integer'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(FestivalRubricSection::class, 'festival_rubric_section_id');
    }
}
