<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\ClassBookingCorrection;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\MobileDeviceToken;
use App\Models\MobileOAuthLoginCode;
use App\Models\MobileSession;
use App\Models\WebsiteLead;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MergeCustomerIdentityByVerifiedPhone
{
    /**
     * @param  array{google_id?: string|null, email?: string|null, email_verified?: bool|null, name?: string|null, password?: string|null}  $identityData
     */
    public function execute(Account $account, string $phone, ?Customer $sourceCustomer = null, array $identityData = []): Customer
    {
        return DB::transaction(function () use ($account, $phone, $sourceCustomer, $identityData): Customer {
            $source = $sourceCustomer
                ? $account->customers()->whereKey($sourceCustomer->getKey())->lockForUpdate()->first()
                : null;

            $target = $account->customers()
                ->where('phone', $phone)
                ->lockForUpdate()
                ->first();

            if ($target && $source && ! $source->is($target) && ! $this->canDeleteSource($source)) {
                throw ValidationException::withMessages([
                    'phone' => __('app.customer_identity_merge_source_has_history'),
                ]);
            }

            if (! $target && $source) {
                $target = $source;
            }

            if (! $target) {
                $target = $account->customers()->create([
                    'phone' => $phone,
                    'phone_verified_at' => now(),
                    'default_language' => $account->default_language,
                ]);
            }

            $updates = $this->targetUpdates($account, $target, $source, $phone, $identityData);

            if ($source && ! $source->is($target)) {
                $this->moveAuthRecords($source, $target);
                $source->delete();
            }

            $target->forceFill($updates)->save();

            return $target->refresh();
        });
    }

    private function canDeleteSource(Customer $source): bool
    {
        if (filled($source->phone)) {
            return false;
        }

        if ($source->classBookings()->exists() || $source->customerClassPasses()->exists() || $source->purchases()->exists()) {
            return false;
        }

        if (
            WebsiteLead::whereBelongsTo($source)->exists()
            || CustomerNotification::whereBelongsTo($source)->exists()
            || ClassBookingCorrection::query()
                ->where('old_customer_id', $source->id)
                ->orWhere('new_customer_id', $source->id)
                ->exists()
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{google_id?: string|null, email?: string|null, email_verified?: bool|null, name?: string|null, password?: string|null}  $identityData
     * @return array<string, mixed>
     */
    private function targetUpdates(Account $account, Customer $target, ?Customer $source, string $phone, array $identityData): array
    {
        $updates = [
            'phone' => $target->phone ?: $phone,
            'phone_verified_at' => $target->phone_verified_at ?: now(),
        ];

        $googleId = $this->stringOrNull($identityData['google_id'] ?? null) ?: $this->stringOrNull($source?->google_id);

        if ($googleId && $target->google_id !== $googleId) {
            if (! $this->googleIdIsAvailable($account, $target, $source, $googleId)) {
                throw ValidationException::withMessages([
                    'google' => __('app.customer_google_failed'),
                ]);
            }

            $updates['google_id'] = $googleId;
        }

        $email = $this->stringOrNull($identityData['email'] ?? null) ?: $this->stringOrNull($source?->email);

        if ($email) {
            if (! $this->emailIsAvailable($account, $target, $source, $email)) {
                throw ValidationException::withMessages([
                    'email' => __('validation.unique', ['attribute' => __('app.email')]),
                ]);
            }

            $updates['email'] = $email;
        }

        $identityEmail = $this->stringOrNull($identityData['email'] ?? null);
        $sourceEmail = $this->stringOrNull($source?->email);
        $emailVerified = (
            (bool) ($identityData['email_verified'] ?? false)
            && $identityEmail === $email
        ) || (
            $source?->email_verified_at !== null
            && $sourceEmail === $email
        );

        if ($emailVerified && ($updates['email'] ?? $target->email) === $email) {
            $updates['email_verified_at'] = $target->email_verified_at ?: now();
        }

        $password = $this->stringOrNull($identityData['password'] ?? null) ?: $this->stringOrNull($source?->password);

        if ($password) {
            $updates['password'] = $password;
        }

        $name = $this->stringOrNull($identityData['name'] ?? null) ?: $this->stringOrNull($source?->name);

        if (blank($target->name) && $name) {
            $updates['name'] = $name;
        }

        return $updates;
    }

    private function emailIsAvailable(Account $account, Customer $target, ?Customer $source, string $email): bool
    {
        return ! $account->customers()
            ->where('email', $email)
            ->whereKeyNot($target->getKey())
            ->when($source, fn ($query) => $query->whereKeyNot($source->getKey()))
            ->exists();
    }

    private function googleIdIsAvailable(Account $account, Customer $target, ?Customer $source, string $googleId): bool
    {
        return ! $account->customers()
            ->where('google_id', $googleId)
            ->whereKeyNot($target->getKey())
            ->when($source, fn ($query) => $query->whereKeyNot($source->getKey()))
            ->exists();
    }

    private function moveAuthRecords(Customer $source, Customer $target): void
    {
        $source->rememberTokens()->update(['customer_id' => $target->id]);

        MobileSession::whereBelongsTo($source)->update(['customer_id' => $target->id]);
        MobileDeviceToken::whereBelongsTo($source)->update(['customer_id' => $target->id]);
        MobileOAuthLoginCode::whereBelongsTo($source)
            ->whereNull('consumed_at')
            ->update(['customer_id' => $target->id]);
    }

    private function stringOrNull(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
