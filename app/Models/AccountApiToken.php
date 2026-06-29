<?php

namespace App\Models;

use App\Enums\AccountApiTokenAbility;
use Database\Factories\AccountApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_id', 'name', 'token_hash', 'encrypted_token', 'last_four', 'abilities', 'is_active', 'last_used_at'])]
#[Hidden(['encrypted_token', 'token_hash'])]
class AccountApiToken extends Model
{
    /** @use HasFactory<AccountApiTokenFactory> */
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
        'abilities' => '["website_leads:create"]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'encrypted_token' => 'encrypted',
            'abilities' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function tokenValue(): string
    {
        return (string) $this->encrypted_token;
    }

    /**
     * @return array<int, string>
     */
    public function abilityValues(): array
    {
        $abilities = $this->abilities;

        if (! is_array($abilities) || $abilities === []) {
            return [AccountApiTokenAbility::WebsiteLeadsCreate->value];
        }

        return collect($abilities)
            ->filter(fn (mixed $ability): bool => is_string($ability) && $ability !== '')
            ->values()
            ->all();
    }

    public function hasAbility(AccountApiTokenAbility|string $ability): bool
    {
        $value = $ability instanceof AccountApiTokenAbility ? $ability->value : $ability;

        return in_array($value, $this->abilityValues(), true);
    }
}
