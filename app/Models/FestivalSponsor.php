<?php

namespace App\Models;

use Database\Factories\FestivalSponsorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['account_id', 'festival_edition_id', 'name', 'url', 'logo_path', 'short_description', 'giveaway_description', 'show_on_all_pages'])]
class FestivalSponsor extends Model
{
    /** @use HasFactory<FestivalSponsorFactory> */
    use HasFactory;

    protected $attributes = ['show_on_all_pages' => false];

    protected function casts(): array
    {
        return ['show_on_all_pages' => 'boolean'];
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

    public function logoUrl(): ?string
    {
        return filled($this->logo_path) ? Storage::disk('public')->url($this->logo_path) : null;
    }
}
