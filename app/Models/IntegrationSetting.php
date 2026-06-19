<?php

namespace App\Models;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use Database\Factories\IntegrationSettingFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scope_type', 'scope_id', 'account_id', 'provider', 'category', 'is_enabled', 'credentials'])]
#[Hidden(['credentials'])]
class IntegrationSetting extends Model
{
    /** @use HasFactory<IntegrationSettingFactory> */
    use HasFactory;

    protected $attributes = [
        'scope_type' => 'platform',
        'scope_id' => 0,
        'is_enabled' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => IntegrationScope::class,
            'provider' => IntegrationProvider::class,
            'category' => IntegrationCategory::class,
            'is_enabled' => 'boolean',
            'credentials' => 'encrypted:array',
        ];
    }

    public function scopePlatform(Builder $query): Builder
    {
        return $query
            ->where('scope_type', IntegrationScope::Platform->value)
            ->where('scope_id', 0);
    }

    public function scopeForAccount(Builder $query, Account $account): Builder
    {
        return $query
            ->where('scope_type', IntegrationScope::Account->value)
            ->where('scope_id', $account->id)
            ->whereBelongsTo($account);
    }

    /**
     * @return array<string, mixed>
     */
    public function readableCredentials(): array
    {
        try {
            $credentials = $this->credentials;
        } catch (DecryptException) {
            return [];
        }

        return is_array($credentials) ? $credentials : [];
    }

    public function hasUnreadableCredentials(): bool
    {
        try {
            $this->credentials;
        } catch (DecryptException) {
            return true;
        }

        return false;
    }

    public function originalIsEquivalent($key): bool
    {
        try {
            return parent::originalIsEquivalent($key);
        } catch (DecryptException) {
            return $key !== 'credentials';
        }
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
