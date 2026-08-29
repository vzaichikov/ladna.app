<?php

namespace App\Models;

use Database\Factories\FestivalNominationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['account_id', 'festival_edition_id', 'name', 'description', 'presented_by', 'prize', 'is_active', 'show_in_mini_app', 'sort_order'])]
class FestivalNomination extends Model
{
    /** @use HasFactory<FestivalNominationFactory> */
    use HasFactory;

    protected $attributes = ['is_active' => true, 'show_in_mini_app' => false, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'show_in_mini_app' => 'boolean', 'sort_order' => 'integer'];
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

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(FestivalParticipant::class, 'festival_nomination_participant')
            ->withPivot('account_id')
            ->withTimestamps();
    }
}
