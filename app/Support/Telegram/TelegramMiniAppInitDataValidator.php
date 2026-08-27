<?php

namespace App\Support\Telegram;

use App\Models\TelegramBotInstallation;
use Illuminate\Validation\ValidationException;

class TelegramMiniAppInitDataValidator
{
    private const MaximumAgeSeconds = 300;

    /**
     * @return array{auth_date: int, query_id: string|null, user: array{id: string, first_name: string, last_name: string, username: string, language_code: string}, chat_type: string|null}
     */
    public function validate(string $initData, TelegramBotInstallation $installation): array
    {
        $token = $installation->tokenValue();

        if (! $token || $initData === '' || strlen($initData) > 10000) {
            throw $this->invalid();
        }

        parse_str($initData, $parameters);
        $receivedHash = is_string($parameters['hash'] ?? null) ? strtolower((string) $parameters['hash']) : '';
        unset($parameters['hash']);

        if (preg_match('/^[a-f0-9]{64}$/', $receivedHash) !== 1) {
            throw $this->invalid();
        }

        ksort($parameters, SORT_STRING);
        $checkString = collect($parameters)
            ->map(fn (mixed $value, string $key): string => $key.'='.(is_string($value) ? $value : ''))
            ->implode("\n");
        $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $checkString, $secretKey);

        if (! hash_equals($calculatedHash, $receivedHash)) {
            throw $this->invalid();
        }

        $authDate = filter_var($parameters['auth_date'] ?? null, FILTER_VALIDATE_INT);

        if (! is_int($authDate)
            || $authDate < now()->timestamp - self::MaximumAgeSeconds
            || $authDate > now()->timestamp + 30) {
            throw ValidationException::withMessages(['init_data' => __('app.festival_telegram_init_data_expired')]);
        }

        $user = json_decode((string) ($parameters['user'] ?? ''), true);
        $userId = is_array($user) ? (string) ($user['id'] ?? '') : '';

        if (preg_match('/^\d+$/', $userId) !== 1) {
            throw $this->invalid();
        }

        $chatType = is_string($parameters['chat_type'] ?? null) ? (string) $parameters['chat_type'] : null;

        if ($chatType !== null && $chatType !== 'private') {
            throw ValidationException::withMessages(['init_data' => __('app.festival_telegram_private_chat_required')]);
        }

        return [
            'auth_date' => $authDate,
            'query_id' => is_string($parameters['query_id'] ?? null) ? $parameters['query_id'] : null,
            'user' => [
                'id' => $userId,
                'first_name' => trim((string) ($user['first_name'] ?? '')),
                'last_name' => trim((string) ($user['last_name'] ?? '')),
                'username' => trim((string) ($user['username'] ?? '')),
                'language_code' => trim((string) ($user['language_code'] ?? '')),
            ],
            'chat_type' => $chatType,
        ];
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['init_data' => __('app.festival_telegram_init_data_invalid')]);
    }
}
