<?php

namespace App\Models;

use App\Enums\FestivalPortalRole;
use App\Enums\FestivalRegistrantType;
use Database\Factories\FestivalPortalUserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['account_id', 'role', 'is_active', 'registrant_type', 'first_name', 'last_name', 'patronymic', 'stage_name', 'email', 'email_normalized', 'password', 'google_id', 'phone', 'phone_normalized', 'city', 'studio_name', 'instagram_url', 'telegram_contact', 'telegram_user_id', 'avatar_path', 'locale', 'email_verified_at', 'phone_verified_at', 'last_login_at'])]
#[Hidden(['password', 'remember_token', 'telegram_user_id', 'google_id'])]
class FestivalPortalUser extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<FestivalPortalUserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = [
        'is_active' => true,
        'locale' => 'uk',
    ];

    protected function casts(): array
    {
        return [
            'role' => FestivalPortalRole::class,
            'is_active' => 'boolean',
            'registrant_type' => FestivalRegistrantType::class,
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
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
        return collect([$this->first_name, $this->last_name])->filter()->join(' ') ?: ($this->email ?: $this->phone ?: __('app.festival_user'));
    }

    public function suggestedEntryName(): string
    {
        return filled($this->stage_name)
            ? $this->stage_name
            : collect([$this->first_name, $this->last_name])->filter()->join(' ');
    }

    public function profileIsComplete(bool $requiresVerifiedPhone = true): bool
    {
        if (in_array($this->role, [FestivalPortalRole::Judge, FestivalPortalRole::Guest], true)) {
            return filled($this->first_name)
                && filled($this->last_name)
                && filled($this->email);
        }

        return $this->registrant_type !== null
            && filled($this->first_name)
            && filled($this->last_name)
            && filled($this->email)
            && filled($this->phone)
            && (! $requiresVerifiedPhone || $this->phone_verified_at !== null)
            && filled($this->city)
            && filled($this->studio_name)
            && ($this->registrant_type !== FestivalRegistrantType::AdultAthlete || $this->profileParticipant()->whereNull('archived_at')->exists());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForRole(Builder $query, FestivalPortalRole $role): Builder
    {
        return $query->where('role', $role->value);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(FestivalParticipant::class);
    }

    public function festivalParticipants(): HasMany
    {
        return $this->participants();
    }

    public function profileParticipant(): HasOne
    {
        return $this->hasOne(FestivalParticipant::class)->where('is_profile_owner', true);
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

    public function festivalNotifications(): HasMany
    {
        return $this->hasMany(FestivalNotification::class);
    }

    public function ticketOrders(): HasMany
    {
        return $this->hasMany(FestivalTicketOrder::class);
    }

    public function tickets(): HasManyThrough
    {
        return $this->hasManyThrough(
            FestivalTicket::class,
            FestivalTicketOrder::class,
            'festival_portal_user_id',
            'festival_ticket_order_id',
        );
    }

    public function streamEntitlements(): HasMany
    {
        return $this->hasMany(FestivalStreamEntitlement::class);
    }
}
