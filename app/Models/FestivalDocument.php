<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'festival_edition_id', 'title', 'kind', 'visibility', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'is_active', 'sort_order'])]
class FestivalDocument extends Model
{
    protected $attributes = ['kind' => 'document', 'visibility' => 'public', 'disk' => 'local', 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer'];
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
