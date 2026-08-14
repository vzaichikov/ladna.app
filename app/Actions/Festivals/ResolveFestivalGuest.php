<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\FestivalPortalUser;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ResolveFestivalGuest
{
    public function __construct(private readonly PhoneNumberNormalizer $phones) {}

    public function execute(
        Account $account,
        ?string $email,
        string $firstName,
        string $lastName,
        ?string $phone = null,
        ?string $locale = null,
    ): ?FestivalPortalUser {
        $normalizedEmail = FestivalPortalUser::normalizeEmail((string) $email);
        if ($normalizedEmail === '' || filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return DB::transaction(function () use ($account, $normalizedEmail, $firstName, $lastName, $phone, $locale): ?FestivalPortalUser {
            $guest = FestivalPortalUser::query()
                ->where('account_id', $account->id)
                ->where('role', FestivalPortalRole::Guest->value)
                ->where('email_normalized', $normalizedEmail)
                ->lockForUpdate()
                ->first();
            if ($guest) {
                return $guest->is_active ? $guest : null;
            }

            $normalizedPhone = $this->phones->normalize($phone, $account->country_code);
            if (filled($normalizedPhone)) {
                $phoneConflict = FestivalPortalUser::query()
                    ->where('account_id', $account->id)
                    ->where('role', FestivalPortalRole::Guest->value)
                    ->where('phone_normalized', $normalizedPhone)
                    ->lockForUpdate()
                    ->exists();
                if ($phoneConflict) {
                    return null;
                }
            }

            try {
                return FestivalPortalUser::query()->create([
                    'account_id' => $account->id,
                    'role' => FestivalPortalRole::Guest,
                    'is_active' => true,
                    'first_name' => trim($firstName) !== '' ? trim($firstName) : trim($lastName),
                    'last_name' => trim($lastName),
                    'email' => $normalizedEmail,
                    'email_normalized' => $normalizedEmail,
                    'phone' => $normalizedPhone,
                    'phone_normalized' => $normalizedPhone,
                    'locale' => in_array($locale, ['en', 'uk'], true) ? $locale : $account->default_language,
                    'password' => null,
                ]);
            } catch (QueryException) {
                return FestivalPortalUser::query()
                    ->where('account_id', $account->id)
                    ->where('role', FestivalPortalRole::Guest->value)
                    ->where('email_normalized', $normalizedEmail)
                    ->where('is_active', true)
                    ->first();
            }
        }, 3);
    }
}
