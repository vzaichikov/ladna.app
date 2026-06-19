<?php

namespace App\Models;

use App\Enums\AccountRole;
use App\Enums\AccountStatus;
use App\Enums\StudioPermission;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'slug', 'status', 'default_language', 'default_currency', 'logo_path', 'brand_color', 'timezone'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'default_language' => 'uk',
        'default_currency' => 'UAH',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
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

    public function classTypes(): HasMany
    {
        return $this->hasMany(ClassType::class);
    }

    public function classPassPlans(): HasMany
    {
        return $this->hasMany(ClassPassPlan::class);
    }

    public function activityDirections(): HasMany
    {
        return $this->hasMany(ActivityDirection::class);
    }

    public function trainers(): HasMany
    {
        return $this->hasMany(Trainer::class);
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
