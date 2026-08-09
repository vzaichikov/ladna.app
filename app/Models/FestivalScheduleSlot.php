<?php

namespace App\Models;

use App\Enums\FestivalScheduleSlotType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_edition_id', 'festival_stage_id', 'festival_entry_id', 'type', 'starts_at', 'ends_at', 'notes', 'published_at', 'created_by', 'updated_by', 'reschedule_reason'])]
class FestivalScheduleSlot extends Model
{
    protected $attributes = ['type' => 'performance'];

    protected function casts(): array
    {
        return ['type' => FestivalScheduleSlotType::class, 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'published_at' => 'datetime'];
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
}
