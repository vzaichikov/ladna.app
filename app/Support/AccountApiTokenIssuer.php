<?php

namespace App\Support;

use App\Enums\AccountApiTokenAbility;
use App\Models\Account;
use App\Models\AccountApiToken;
use Illuminate\Support\Str;

class AccountApiTokenIssuer
{
    /**
     * @param  array<int, AccountApiTokenAbility|string>  $abilities
     */
    public function issue(Account $account, string $name, array $abilities = [AccountApiTokenAbility::WebsiteLeadsCreate]): AccountApiToken
    {
        do {
            $token = 'ladna_'.Str::random(48);
            $tokenHash = $this->hash($token);
        } while (AccountApiToken::where('token_hash', $tokenHash)->exists());

        return $account->apiTokens()->create([
            'name' => $name,
            'token_hash' => $tokenHash,
            'encrypted_token' => $token,
            'last_four' => substr($token, -4),
            'abilities' => $this->normalizeAbilities($abilities),
            'is_active' => true,
        ]);
    }

    public function regenerate(AccountApiToken $accountApiToken): AccountApiToken
    {
        do {
            $token = 'ladna_'.Str::random(48);
            $tokenHash = $this->hash($token);
        } while (AccountApiToken::where('token_hash', $tokenHash)->whereKeyNot($accountApiToken)->exists());

        $accountApiToken->update([
            'token_hash' => $tokenHash,
            'encrypted_token' => $token,
            'last_four' => substr($token, -4),
            'is_active' => true,
            'last_used_at' => null,
        ]);

        return $accountApiToken->refresh();
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param  array<int, AccountApiTokenAbility|string>  $abilities
     * @return array<int, string>
     */
    private function normalizeAbilities(array $abilities): array
    {
        $values = collect($abilities)
            ->map(fn (AccountApiTokenAbility|string $ability): string => $ability instanceof AccountApiTokenAbility ? $ability->value : $ability)
            ->filter(fn (string $ability): bool => in_array($ability, array_column(AccountApiTokenAbility::cases(), 'value'), true))
            ->unique()
            ->values()
            ->all();

        return $values ?: [AccountApiTokenAbility::WebsiteLeadsCreate->value];
    }
}
