<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\FestivalPortalUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveFestivalEntranceGuest
{
    public function execute(Account $account, string $name, ?string $email, string $locale): FestivalPortalUser
    {
        $normalizedEmail = FestivalPortalUser::normalizeEmail((string) $email);
        [$firstName, $lastName] = $this->splitName($name);

        return DB::transaction(function () use ($account, $normalizedEmail, $firstName, $lastName, $locale): FestivalPortalUser {
            if ($normalizedEmail !== '') {
                $existing = FestivalPortalUser::query()
                    ->where('account_id', $account->id)
                    ->where('role', FestivalPortalRole::Guest->value)
                    ->where('email_normalized', $normalizedEmail)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if (! $existing->is_active) {
                        throw ValidationException::withMessages(['guest_email' => __('app.entrance_guest_inactive')]);
                    }

                    return $existing;
                }
            }

            try {
                return FestivalPortalUser::query()->create([
                    'account_id' => $account->id,
                    'role' => FestivalPortalRole::Guest,
                    'is_active' => true,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $normalizedEmail !== '' ? $normalizedEmail : null,
                    'email_normalized' => $normalizedEmail !== '' ? $normalizedEmail : null,
                    'phone' => null,
                    'phone_normalized' => null,
                    'locale' => in_array($locale, ['en', 'uk'], true) ? $locale : $account->default_language,
                    'password' => null,
                ]);
            } catch (QueryException $exception) {
                if ($normalizedEmail === '') {
                    throw $exception;
                }

                $guest = FestivalPortalUser::query()
                    ->where('account_id', $account->id)
                    ->where('role', FestivalPortalRole::Guest->value)
                    ->where('email_normalized', $normalizedEmail)
                    ->where('is_active', true)
                    ->first();

                if (! $guest) {
                    throw $exception;
                }

                return $guest;
            }
        }, 3);
    }

    /** @return array{string, string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [];

        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }
}
