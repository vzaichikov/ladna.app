<?php

namespace App\Models;

use App\Enums\FestivalCompetitionFormat;
use Database\Factories\FestivalCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'festival_workflow_id', 'festival_direction_id', 'code', 'name', 'min_members', 'max_members', 'min_age', 'max_age', 'min_duration_seconds', 'max_duration_seconds', 'registration_closes_at', 'requirements_html', 'competition_format', 'minimum_entries_to_run', 'is_active', 'sort_order'])]
class FestivalCategory extends Model
{
    /** @use HasFactory<FestivalCategoryFactory> */
    use HasFactory;

    protected $attributes = ['min_members' => 1, 'max_members' => 1, 'competition_format' => 'scored', 'minimum_entries_to_run' => 1, 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'min_members' => 'integer',
            'max_members' => 'integer',
            'min_age' => 'integer',
            'max_age' => 'integer',
            'min_duration_seconds' => 'integer',
            'max_duration_seconds' => 'integer',
            'registration_closes_at' => 'datetime',
            'competition_format' => FestivalCompetitionFormat::class,
            'minimum_entries_to_run' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
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

    public function registrationWorkflow(): BelongsTo
    {
        return $this->belongsTo(FestivalWorkflow::class, 'festival_workflow_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(FestivalDirection::class, 'festival_direction_id');
    }

    public function festivalDirection(): BelongsTo
    {
        return $this->direction();
    }

    public function festivalEdition(): BelongsTo
    {
        return $this->edition();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FestivalEntry::class);
    }
}
