<?php

namespace App\Models;

use App\Enums\FestivalRequirementType;
use Database\Factories\FestivalRequirementDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_edition_id', 'festival_category_id', 'type', 'name', 'instructions', 'stage', 'due_at', 'allowed_extensions', 'allowed_mime_types', 'max_size_kb', 'min_duration_seconds', 'max_duration_seconds', 'is_required', 'sort_order', 'version', 'locked_at'])]
class FestivalRequirementDefinition extends Model
{
    /** @use HasFactory<FestivalRequirementDefinitionFactory> */
    use HasFactory;

    protected $attributes = ['stage' => 'final', 'max_size_kb' => 20480, 'is_required' => true, 'sort_order' => 0, 'version' => 1];

    protected function casts(): array
    {
        return [
            'type' => FestivalRequirementType::class,
            'due_at' => 'datetime',
            'allowed_extensions' => 'array',
            'allowed_mime_types' => 'array',
            'max_size_kb' => 'integer',
            'min_duration_seconds' => 'integer',
            'max_duration_seconds' => 'integer',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'version' => 'integer',
            'locked_at' => 'datetime',
        ];
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
}
