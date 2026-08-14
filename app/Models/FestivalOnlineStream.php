<?php

namespace App\Models;

use App\Enums\FestivalStreamOverride;
use App\Enums\FestivalStreamProvider;
use Database\Factories\FestivalOnlineStreamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['account_id', 'festival_edition_id', 'is_enabled', 'provider', 'path', 'publisher_token_encrypted', 'publisher_token_hash', 'youtube_video_id', 'opens_at', 'closes_at', 'playback_override'])]
#[Hidden(['publisher_token_encrypted', 'publisher_token_hash'])]
class FestivalOnlineStream extends Model
{
    /** @use HasFactory<FestivalOnlineStreamFactory> */
    use HasFactory;

    protected $attributes = ['is_enabled' => false, 'provider' => 'mediamtx', 'playback_override' => 'automatic'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'provider' => FestivalStreamProvider::class,
            'publisher_token_encrypted' => 'encrypted',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'playback_override' => FestivalStreamOverride::class,
        ];
    }

    public function isOpen(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->is_enabled && match ($this->playback_override) {
            FestivalStreamOverride::Open => true,
            FestivalStreamOverride::Closed, FestivalStreamOverride::Automatic => false,
        };
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(FestivalEdition::class, 'festival_edition_id');
    }

    public function admissionTypes(): HasMany
    {
        return $this->hasMany(FestivalAdmissionType::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(FestivalStreamEntitlement::class);
    }
}
