<?php

namespace App\Models;

use App\Enums\AccountRole;
use App\Enums\AccountStatus;
use App\Enums\ScheduleKind;
use App\Enums\StudioPermission;
use App\Support\ScheduleKindRegistry;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'slug', 'status', 'default_language', 'country_code', 'default_currency', 'logo_path', 'brand_color', 'studio_slogan', 'timezone', 'legal_entity_name', 'tax_id', 'support_instagram_url', 'support_telegram_url', 'support_viber_url', 'support_whatsapp_url', 'enabled_schedule_kinds', 'schedule_kind_colors', 'opening_hours', 'studio_rules_html', 'class_pass_cancellation_rules'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    private const DEFAULT_OPENING_TIME = '08:00';

    private const DEFAULT_CLOSING_TIME = '22:00';

    protected $attributes = [
        'status' => 'active',
        'default_language' => 'uk',
        'country_code' => 'UA',
        'default_currency' => 'UAH',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'enabled_schedule_kinds' => 'array',
            'schedule_kind_colors' => 'array',
            'opening_hours' => 'array',
            'class_pass_cancellation_rules' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AccountStatus::Active->value);
    }

    public function logoUrl(): string
    {
        if ($this->logo_path) {
            if (str_starts_with($this->logo_path, 'brand/')) {
                return asset($this->logo_path);
            }

            return Storage::disk('public')->url($this->logo_path);
        }

        if ($this->slug === 'charmpole') {
            return asset('brand/charmpole-icon.svg');
        }

        return asset('brand/ladna-mark.svg');
    }

    /**
     * @return array<int, array{key: string, label_key: string, url: string, icon_path: string}>
     */
    public function publicSupportLinks(): array
    {
        $links = [
            [
                'key' => 'instagram',
                'label_key' => 'app.support_channel_instagram',
                'url' => $this->support_instagram_url,
                'icon_path' => 'assets/social/instagram.svg',
            ],
            [
                'key' => 'telegram',
                'label_key' => 'app.support_channel_telegram',
                'url' => $this->support_telegram_url,
                'icon_path' => 'assets/social/telegram.svg',
            ],
            [
                'key' => 'viber',
                'label_key' => 'app.support_channel_viber',
                'url' => $this->support_viber_url,
                'icon_path' => 'assets/social/viber.svg',
            ],
            [
                'key' => 'whatsapp',
                'label_key' => 'app.support_channel_whatsapp',
                'url' => $this->support_whatsapp_url,
                'icon_path' => 'assets/social/whatsapp.svg',
            ],
        ];

        return collect($links)
            ->filter(fn (array $link): bool => filled($link['url']))
            ->map(fn (array $link): array => [
                ...$link,
                'url' => (string) $link['url'],
            ])
            ->values()
            ->all();
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(AccountMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_memberships')
            ->using(AccountMembership::class)
            ->withPivot(['role', 'permissions'])
            ->withTimestamps();
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function customerAuthSetting(): HasOne
    {
        return $this->hasOne(CustomerAuthSetting::class);
    }

    public function websiteLeads(): HasMany
    {
        return $this->hasMany(WebsiteLead::class);
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(AccountApiToken::class);
    }

    public function aiSetting(): HasOne
    {
        return $this->hasOne(AccountAiSetting::class);
    }

    public function aiProviderCredentials(): HasMany
    {
        return $this->hasMany(AccountAiProviderCredential::class);
    }

    public function telegramBotInstallations(): HasMany
    {
        return $this->hasMany(TelegramBotInstallation::class);
    }

    public function telegramBotProfiles(): HasMany
    {
        return $this->hasMany(TelegramBotProfileSetting::class);
    }

    public function telegramChatAuthorizations(): HasMany
    {
        return $this->hasMany(TelegramChatAuthorization::class);
    }

    public function aiConversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    public function classTypes(): HasMany
    {
        return $this->hasMany(ClassType::class);
    }

    /**
     * @return array<int, string>
     */
    public function enabledScheduleKindValues(): array
    {
        $enabledScheduleKinds = $this->enabled_schedule_kinds;

        if (! is_array($enabledScheduleKinds) || $enabledScheduleKinds === []) {
            return ScheduleKindRegistry::defaultEnabledValues();
        }

        return ScheduleKindRegistry::validValues($enabledScheduleKinds)
            ?: ScheduleKindRegistry::defaultEnabledValues();
    }

    public function hasScheduleKindEnabled(ScheduleKind|string $scheduleKind): bool
    {
        $value = $scheduleKind instanceof ScheduleKind ? $scheduleKind->value : $scheduleKind;

        return in_array($value, $this->enabledScheduleKindValues(), true);
    }

    /**
     * @return array<string, string>
     */
    public function scheduleKindColors(): array
    {
        $colors = ScheduleKindRegistry::defaultColors();

        if (is_array($this->schedule_kind_colors)) {
            foreach ($this->schedule_kind_colors as $value => $color) {
                if (array_key_exists($value, $colors) && is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                    $colors[$value] = strtoupper($color);
                }
            }
        }

        return $colors;
    }

    public function scheduleKindColor(ScheduleKind|string|null $scheduleKind, string $fallback = '#3B223F'): string
    {
        $value = $scheduleKind instanceof ScheduleKind ? $scheduleKind->value : $scheduleKind;

        if (! is_string($value)) {
            return $fallback;
        }

        return $this->scheduleKindColors()[$value] ?? $fallback;
    }

    public function scheduleKindTextColor(ScheduleKind|string|null $scheduleKind, string $fallback = '#3B223F'): string
    {
        $color = ltrim($this->scheduleKindColor($scheduleKind, $fallback), '#');
        $red = hexdec(substr($color, 0, 2));
        $green = hexdec(substr($color, 2, 2));
        $blue = hexdec(substr($color, 4, 2));
        $luminance = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

        return $luminance > 150 ? '#1E293B' : '#FFFFFF';
    }

    /**
     * @return array<int, array{enabled: bool, opens_at: string, closes_at: string}>
     */
    public static function defaultOpeningHours(): array
    {
        return collect(range(1, 7))
            ->mapWithKeys(fn (int $weekday): array => [
                $weekday => [
                    'enabled' => true,
                    'opens_at' => self::DEFAULT_OPENING_TIME,
                    'closes_at' => self::DEFAULT_CLOSING_TIME,
                ],
            ])
            ->all();
    }

    /**
     * @return array{return_sessions_enabled: bool, return_sessions_count: int, extend_days_enabled: bool, extend_days_count: int}
     */
    public static function defaultClassPassCancellationRules(): array
    {
        return [
            'return_sessions_enabled' => false,
            'return_sessions_count' => 1,
            'extend_days_enabled' => false,
            'extend_days_count' => 1,
        ];
    }

    /**
     * @return array{return_sessions_enabled: bool, return_sessions_count: int, extend_days_enabled: bool, extend_days_count: int}
     */
    public function classPassCancellationRules(): array
    {
        $rules = is_array($this->class_pass_cancellation_rules)
            ? $this->class_pass_cancellation_rules
            : [];
        $defaults = self::defaultClassPassCancellationRules();

        return [
            'return_sessions_enabled' => filter_var($rules['return_sessions_enabled'] ?? $defaults['return_sessions_enabled'], FILTER_VALIDATE_BOOLEAN),
            'return_sessions_count' => self::positiveInteger($rules['return_sessions_count'] ?? $defaults['return_sessions_count'], $defaults['return_sessions_count']),
            'extend_days_enabled' => filter_var($rules['extend_days_enabled'] ?? $defaults['extend_days_enabled'], FILTER_VALIDATE_BOOLEAN),
            'extend_days_count' => self::positiveInteger($rules['extend_days_count'] ?? $defaults['extend_days_count'], $defaults['extend_days_count']),
        ];
    }

    /**
     * @return array<int, array{enabled: bool, opens_at: string, closes_at: string}>
     */
    public function openingHours(): array
    {
        $defaults = self::defaultOpeningHours();
        $openingHours = is_array($this->opening_hours) ? $this->opening_hours : [];

        foreach (range(1, 7) as $weekday) {
            $dayHours = $openingHours[$weekday] ?? $openingHours[(string) $weekday] ?? [];

            if (! is_array($dayHours)) {
                $dayHours = [];
            }

            $defaults[$weekday] = [
                'enabled' => filter_var($dayHours['enabled'] ?? $defaults[$weekday]['enabled'], FILTER_VALIDATE_BOOLEAN),
                'opens_at' => self::normalizeOpeningTime($dayHours['opens_at'] ?? null, $defaults[$weekday]['opens_at']),
                'closes_at' => self::normalizeOpeningTime($dayHours['closes_at'] ?? null, $defaults[$weekday]['closes_at']),
            ];
        }

        return $defaults;
    }

    /**
     * @return array{enabled: bool, opens_at: string, closes_at: string}|null
     */
    public function openingHoursForIsoWeekday(int $weekday): ?array
    {
        $openingHours = $this->openingHours()[$weekday] ?? null;

        if (! $openingHours || ! $openingHours['enabled']) {
            return null;
        }

        return $openingHours;
    }

    private static function normalizeOpeningTime(mixed $time, string $default): string
    {
        if (is_string($time) && preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            return $time;
        }

        return $default;
    }

    private static function positiveInteger(mixed $value, int $default): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : $default;
    }

    public function classPassPlans(): HasMany
    {
        return $this->hasMany(ClassPassPlan::class);
    }

    public function classPassSegments(): HasMany
    {
        return $this->hasMany(ClassPassSegment::class);
    }

    public function customerClassPasses(): HasMany
    {
        return $this->hasMany(CustomerClassPass::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(AccountActivityLog::class);
    }

    public function customerPurchases(): HasMany
    {
        return $this->hasMany(CustomerPurchase::class);
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(AccountSubscriptionPayment::class);
    }

    public function fiscalReceipts(): HasMany
    {
        return $this->hasMany(FiscalReceipt::class);
    }

    public function signupRequests(): HasMany
    {
        return $this->hasMany(AccountSignupRequest::class);
    }

    public function customerClassPassReservations(): HasMany
    {
        return $this->hasMany(CustomerClassPassReservation::class);
    }

    public function activityDirections(): HasMany
    {
        return $this->hasMany(ActivityDirection::class);
    }

    public function trainers(): HasMany
    {
        return $this->hasMany(Trainer::class);
    }

    public function trainerSubstitutions(): HasMany
    {
        return $this->hasMany(TrainerSubstitution::class);
    }

    public function trainerTypes(): HasMany
    {
        return $this->hasMany(TrainerType::class);
    }

    public function defaultTrainerType(): ?TrainerType
    {
        return $this->trainerTypes()
            ->where('is_default', true)
            ->ordered()
            ->first();
    }

    public function ensureDefaultTrainerType(): TrainerType
    {
        $defaultTrainerType = $this->defaultTrainerType();

        if ($defaultTrainerType) {
            return $defaultTrainerType;
        }

        $firstTrainerType = $this->trainerTypes()->ordered()->first();

        if ($firstTrainerType) {
            $firstTrainerType->forceFill(['is_default' => true])->save();

            return $firstTrainerType;
        }

        return $this->trainerTypes()->create([
            'name' => 'Trainer',
            'icon' => 'user-round',
            'color' => '#3B223F',
            'is_default' => true,
            'sort_order' => 10,
        ]);
    }

    public function classBookings(): HasMany
    {
        return $this->hasMany(ClassBooking::class);
    }

    public function scheduleSeries(): HasMany
    {
        return $this->hasMany(ScheduleSeries::class);
    }

    public function scheduledClasses(): HasMany
    {
        return $this->hasMany(ScheduledClass::class);
    }

    public function scheduledClassCancellations(): HasMany
    {
        return $this->hasMany(ScheduledClassCancellation::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(AccountSubscription::class);
    }

    public function isAccessibleBy(User $user): bool
    {
        return $user->isPlatformAdmin() || $this->users()->whereKey($user->getKey())->exists();
    }

    public function addOwner(User $user): void
    {
        $this->users()->syncWithoutDetaching([
            $user->getKey() => ['role' => AccountRole::Owner->value],
        ]);
    }

    public function membershipFor(User $user): ?AccountMembership
    {
        return $this->memberships()
            ->whereBelongsTo($user)
            ->first();
    }

    public function isOwnedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->memberships()
            ->whereBelongsTo($user)
            ->where('role', AccountRole::Owner->value)
            ->exists();
    }

    public function userCan(User $user, StudioPermission|string $permission): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return $this->membershipFor($user)?->allows($permission) ?? false;
    }
}
