<?php

namespace App\Support;

use App\Models\Account;
use App\Models\AccountApiToken;
use Illuminate\Support\Str;

class AccountApiTokenIssuer
{
    public function issue(Account $account, string $name): AccountApiToken
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
}
