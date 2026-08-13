<?php

namespace App\Models;

use App\Enums\FestivalScheduleSlotType;
use Database\Factories\FestivalTimelineItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_edition_id', 'festival_timeline_id', 'festival_schedule_slot_id', 'festival_entry_id', 'entry_reference', 'label', 'type', 'notes', 'duration_seconds', 'planned_starts_at', 'planned_ends_at', 'sort_order', 'is_enabled'])]
class FestivalTimelineItem extends Model
{
    /** @use HasFactory<FestivalTimelineItemFactory> */
    use HasFactory;

    protected $attributes = ['is_enabled' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'type' => FestivalScheduleSlotType::class,
            'duration_seconds' => 'integer',
            'planned_starts_at' => 'datetime',
            'planned_ends_at' => 'datetime',
            'sort_order' => 'integer',
            'is_enabled' => 'boolean',
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

    public function timeline(): BelongsTo
    {
        return $this->belongsTo(FestivalTimeline::class, 'festival_timeline_id');
    }

    public function scheduleSlot(): BelongsTo
    {
        return $this->belongsTo(FestivalScheduleSlot::class, 'festival_schedule_slot_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(FestivalEntry::class, 'festival_entry_id');
    }
}
