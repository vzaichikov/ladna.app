<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'code', 'name', 'kind', 'is_required', 'is_active', 'sort_order'])]
class FestivalClassificationAxis extends Model
{
    protected $attributes = ['kind' => 'custom', 'is_required' => true, 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
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

    public function options(): HasMany
    {
        return $this->hasMany(FestivalClassificationOption::class)->orderBy('sort_order')->orderBy('id');
    }

    public function festivalClassificationOptions(): HasMany
    {
        return $this->options();
    }
}
