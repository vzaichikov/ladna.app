<?php

namespace App\Support\Promotions;

use App\Models\Account;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Str;

class PromotionIdentity
{
    public function __construct(private readonly PhoneNumberNormalizer $phones) {}

    public function emailHash(Account $account, ?string $email): ?string
    {
        $normalized = Str::of($email ?? '')->trim()->lower()->toString();

        return $normalized === '' ? null : $this->fingerprint($account, 'email', $normalized);
    }

    public function phoneHash(Account $account, ?string $phone): ?string
    {
        $normalized = $this->phones->normalize($phone, $account->country_code ?? 'UA');

        return blank($normalized) ? null : $this->fingerprint($account, 'phone', (string) $normalized);
    }

    private function fingerprint(Account $account, string $type, string $value): string
    {
        return hash_hmac('sha256', $type.':'.$account->id.':'.$value, (string) config('app.key'));
    }
}
