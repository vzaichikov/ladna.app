<?php

namespace App\Models;

use Database\Factories\FestivalWorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'name', 'is_active', 'sort_order'])]
class FestivalWorkflow extends Model
{
    /** @use HasFactory<FestivalWorkflowFactory> */
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

    public function steps(): HasMany
    {
        return $this->hasMany(FestivalWorkflowStep::class)->orderBy('sort_order')->orderBy('id');
    }

    public function festivalWorkflowSteps(): HasMany
    {
        return $this->steps();
    }

    public function categories(): HasMany
    {
        return $this->hasMany(FestivalCategory::class);
    }
}
