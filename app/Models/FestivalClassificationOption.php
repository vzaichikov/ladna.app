<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['account_id', 'festival_edition_id', 'festival_classification_axis_id', 'code', 'label', 'metadata', 'is_active', 'sort_order'])]
class FestivalClassificationOption extends Model
{
    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function axis(): BelongsTo
    {
        return $this->belongsTo(FestivalClassificationAxis::class, 'festival_classification_axis_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(FestivalCategory::class, 'festival_category_option')->withPivot('account_id');
    }
}
