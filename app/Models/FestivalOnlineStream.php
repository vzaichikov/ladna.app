<?php

namespace App\Models;

use App\Enums\FestivalStreamOverride;
use Database\Factories\FestivalOnlineStreamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['account_id', 'festival_edition_id', 'is_enabled', 'path', 'publisher_token_encrypted', 'publisher_token_hash', 'opens_at', 'closes_at', 'playback_override'])]
#[Hidden(['publisher_token_encrypted', 'publisher_token_hash'])]
class FestivalOnlineStream extends Model
{
    /** @use HasFactory<FestivalOnlineStreamFactory> */
    use HasFactory;

    protected $attributes = ['is_enabled' => false, 'playback_override' => 'automatic'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
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
            FestivalStreamOverride::Closed => false,
            FestivalStreamOverride::Automatic => (! $this->opens_at || $this->opens_at->lessThanOrEqualTo($at))
                && (! $this->closes_at || $this->closes_at->greaterThanOrEqualTo($at)),
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
