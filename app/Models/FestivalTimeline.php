<?php

namespace App\Models;

use Database\Factories\FestivalTimelineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'festival_stage_id', 'active_item_id', 'last_finished_item_id', 'started_at', 'paused_at', 'completed_at', 'next_transition_at', 'created_by', 'updated_by'])]
class FestivalTimeline extends Model
{
    /** @use HasFactory<FestivalTimelineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'next_transition_at' => 'datetime',
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

    public function stage(): BelongsTo
    {
        return $this->belongsTo(FestivalStage::class, 'festival_stage_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FestivalTimelineItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activeItem(): BelongsTo
    {
        return $this->belongsTo(FestivalTimelineItem::class, 'active_item_id');
    }

    public function lastFinishedItem(): BelongsTo
    {
        return $this->belongsTo(FestivalTimelineItem::class, 'last_finished_item_id');
    }
}
