<?php

namespace App\Models;

use App\Enums\FestivalRegistrantType;
use Database\Factories\FestivalPortalUserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['account_id', 'registrant_type', 'first_name', 'last_name', 'patronymic', 'email', 'email_normalized', 'phone', 'city', 'studio_name', 'instagram_url', 'telegram_user_id', 'avatar_path', 'locale', 'email_verified_at', 'last_login_at'])]
#[Hidden(['remember_token', 'telegram_user_id'])]
class FestivalPortalUser extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<FestivalPortalUserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['locale' => 'uk'];

    protected function casts(): array
    {
        return [
            'registrant_type' => FestivalRegistrantType::class,
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    public function preferredLocale(): string
    {
        return $this->locale;
    }

    public function displayName(): string
    {
        return collect([$this->first_name, $this->last_name])->filter()->join(' ') ?: $this->email;
    }

    public function profileIsComplete(): bool
    {
        return $this->registrant_type !== null
            && filled($this->first_name)
            && filled($this->last_name);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(FestivalParticipant::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FestivalEntry::class);
    }

    public function judgeAssignments(): HasMany
    {
        return $this->hasMany(FestivalJudgeAssignment::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(FestivalNotificationPreference::class);
    }
}
