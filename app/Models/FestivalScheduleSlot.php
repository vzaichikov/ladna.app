<?php

namespace App\Models;

use App\Enums\FestivalScheduleSlotType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'festival_stage_id', 'festival_entry_id', 'festival_category_id', 'parent_id', 'type', 'name', 'starts_at', 'ends_at', 'sort_order', 'notes', 'published_at', 'created_by', 'updated_by', 'reschedule_reason'])]
class FestivalScheduleSlot extends Model
{
    protected $attributes = ['type' => 'performance', 'sort_order' => 0];

    protected function casts(): array
    {
        return ['type' => FestivalScheduleSlotType::class, 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'sort_order' => 'integer', 'published_at' => 'datetime'];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(FestivalStage::class, 'festival_stage_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'festival_entry_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FestivalCategory::class, 'festival_category_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function displayName(): string
    {
        return match ($this->type) {
            FestivalScheduleSlotType::CategoryHeader => $this->category?->name ?? '',
            FestivalScheduleSlotType::FreeHeader, FestivalScheduleSlotType::Custom => (string) $this->name,
            FestivalScheduleSlotType::Performance, FestivalScheduleSlotType::Rehearsal => $this->entry?->entry_name ?? '',
        };
    }
}
