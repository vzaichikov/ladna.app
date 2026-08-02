<?php

namespace App\Support\CustomerAuth;

use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\TelegramChatAuthorization;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TelegramCustomerLoginTokenService
{
    private const TokenTtlMinutes = 5;

    public function issueUrl(
        Account $account,
        Customer $customer,
        TelegramChatAuthorization $authorization,
    ): string {
        if (
            (int) $customer->account_id !== (int) $account->id
            || (int) $authorization->account_id !== (int) $account->id
            || (int) $authorization->customer_id !== (int) $customer->id
            || $authorization->profile !== TelegramBotProfile::Customer
            || $authorization->status !== TelegramChatAuthorizationStatus::Authorized
        ) {
            throw new InvalidArgumentException('Telegram authorization does not match the selected customer account.');
        }

        $token = Str::random(64);
        $expiresAt = now()->addMinutes(self::TokenTtlMinutes);

        Cache::put($this->cacheKey($token), [
            'account_id' => (int) $account->id,
            'customer_id' => (int) $customer->id,
            'telegram_chat_authorization_id' => (int) $authorization->id,
        ], $expiresAt);

        return URL::temporarySignedRoute('customer.telegram-login.consume', $expiresAt, [
            'accountSlug' => $account->slug,
            'token' => $token,
        ]);
    }

    public function consume(Account $account, string $token): ?Customer
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/', $token) !== 1) {
            return null;
        }

        try {
            $payload = Cache::lock($this->lockKey($token), 10)->block(
                2,
                fn (): mixed => Cache::pull($this->cacheKey($token)),
            );
        } catch (LockTimeoutException) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $accountId = filter_var($payload['account_id'] ?? null, FILTER_VALIDATE_INT);
        $customerId = filter_var($payload['customer_id'] ?? null, FILTER_VALIDATE_INT);
        $authorizationId = filter_var($payload['telegram_chat_authorization_id'] ?? null, FILTER_VALIDATE_INT);

        if ($accountId !== (int) $account->id || ! is_int($customerId) || ! is_int($authorizationId)) {
            return null;
        }

        $authorizationExists = TelegramChatAuthorization::query()
            ->whereKey($authorizationId)
            ->whereBelongsTo($account)
            ->where('customer_id', $customerId)
            ->where('profile', TelegramBotProfile::Customer->value)
            ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
            ->whereHas('installation', fn (Builder $query): Builder => $query
                ->whereBelongsTo($account)
                ->where('profile', TelegramBotProfile::Customer->value)
                ->where('is_enabled', true))
            ->exists();

        if (! $authorizationExists) {
            return null;
        }

        return $account->customers()->whereKey($customerId)->first();
    }

    private function cacheKey(string $token): string
    {
        return 'customer-telegram-login:'.hash('sha256', $token);
    }

    private function lockKey(string $token): string
    {
        return 'customer-telegram-login-lock:'.hash('sha256', $token);
    }
}
