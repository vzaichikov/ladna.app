<?php

namespace App\Models;

use App\Enums\FestivalEntrancePassStatus;
use Database\Factories\FestivalEntrancePassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['account_id', 'festival_edition_id', 'festival_participant_id', 'code', 'token_encrypted', 'token_hash', 'status', 'is_checked_in', 'checked_in_at', 'disabled_at', 'disabled_reason', 'credentials_rotated_at'])]
#[Hidden(['token_encrypted', 'token_hash'])]
class FestivalEntrancePass extends Model
{
    /** @use HasFactory<FestivalEntrancePassFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'valid', 'is_checked_in' => false];

    protected function casts(): array
    {
        return [
            'token_encrypted' => 'encrypted',
            'status' => FestivalEntrancePassStatus::class,
            'is_checked_in' => 'boolean',
            'checked_in_at' => 'datetime',
            'disabled_at' => 'datetime',
            'credentials_rotated_at' => 'datetime',
        ];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(FestivalParticipant::class, 'festival_participant_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(FestivalEntrancePassScan::class);
    }
}
