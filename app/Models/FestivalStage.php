<?php

namespace App\Models;

use Database\Factories\FestivalStageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['account_id', 'festival_edition_id', 'name', 'description', 'is_active', 'sort_order'])]
class FestivalStage extends Model
{
    /** @use HasFactory<FestivalStageFactory> */
    use HasFactory;

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
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

    public function slots(): HasMany
    {
        return $this->hasMany(FestivalScheduleSlot::class)->orderBy('sort_order')->orderBy('id');
    }

    public function timeline(): HasOne
    {
        return $this->hasOne(FestivalTimeline::class, 'festival_stage_id');
    }

    public function festivalTimelineItems(): HasManyThrough
    {
        return $this->hasManyThrough(
            FestivalTimelineItem::class,
            FestivalTimeline::class,
            'festival_stage_id',
            'festival_timeline_id',
        );
    }
}
