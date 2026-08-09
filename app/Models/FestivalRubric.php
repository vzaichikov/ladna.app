<?php

namespace App\Models;

use Database\Factories\FestivalRubricFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'festival_category_id', 'name', 'version', 'is_active', 'locked_at'])]
class FestivalRubric extends Model
{
    /** @use HasFactory<FestivalRubricFactory> */
    use HasFactory;

    protected $attributes = ['version' => 1, 'is_active' => true];

    protected function casts(): array
    {
        return ['version' => 'integer', 'is_active' => 'boolean', 'locked_at' => 'datetime'];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function festivalEdition(): BelongsTo
    {
        return $this->edition();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FestivalCategory::class, 'festival_category_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(FestivalRubricSection::class)->orderBy('sort_order')->orderBy('id');
    }
}
