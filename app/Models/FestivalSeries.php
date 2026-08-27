<?php

namespace App\Models;

use App\Enums\TelegramBotProfile;
use Database\Factories\FestivalSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['account_id', 'name', 'slug', 'summary', 'organizer_name', 'organizer_email', 'organizer_phone', 'organizer_telegram_url', 'organizer_instagram_url', 'logo_path', 'brand_color', 'defaults', 'is_active'])]
class FestivalSeries extends Model
{
    /** @use HasFactory<FestivalSeriesFactory> */
    use HasFactory;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['defaults' => 'array', 'is_active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function editions(): HasMany
    {
        return $this->hasMany(FestivalEdition::class)->latest('starts_at');
    }

    public function telegramBotInstallation(): HasOne
    {
        return $this->hasOne(TelegramBotInstallation::class, 'scope_id')
            ->where('scope_type', 'festival_series')
            ->where('profile', TelegramBotProfile::Festival->value);
    }
}
