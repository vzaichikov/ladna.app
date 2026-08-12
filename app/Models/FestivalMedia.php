<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['account_id', 'festival_edition_id', 'kind', 'disk', 'path', 'external_url', 'alt_text', 'caption', 'is_cover', 'is_mobile_cover', 'is_active', 'sort_order'])]
class FestivalMedia extends Model
{
    protected $attributes = ['is_cover' => false, 'is_mobile_cover' => false, 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_cover' => 'boolean', 'is_mobile_cover' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function url(): ?string
    {
        return $this->external_url ?: ($this->path ? Storage::disk($this->disk ?: 'public')->url($this->path) : null);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }
}
