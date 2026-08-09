<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'code', 'name', 'kind', 'is_required', 'sort_order'])]
class FestivalClassificationAxis extends Model
{
    protected $attributes = ['kind' => 'custom', 'is_required' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'sort_order' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(FestivalClassificationOption::class)->orderBy('sort_order')->orderBy('id');
    }
}
