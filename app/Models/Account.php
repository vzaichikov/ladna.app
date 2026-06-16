<?php

namespace App\Models;

use App\Enums\AccountRole;
use App\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'slug', 'status', 'default_language', 'default_currency', 'logo_path', 'brand_color', 'timezone'])]
class Account extends Model
{
    /** @use HasFactory<\Database\Factories\AccountFactory> */
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
            ->withPivot('role')
            ->withTimestamps();
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_account')->withTimestamps();
    }

    public function classTypes(): HasMany
    {
        return $this->hasMany(ClassType::class);
    }

    public function activityDirections(): HasMany
    {
        return $this->hasMany(ActivityDirection::class);
    }

    public function instructors(): HasMany
    {
        return $this->hasMany(Instructor::class);
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
        return $this->users()->whereKey($user->getKey())->exists();
    }

    public function addOwner(User $user): void
    {
        $this->users()->syncWithoutDetaching([
            $user->getKey() => ['role' => AccountRole::Owner->value],
        ]);
    }
}
