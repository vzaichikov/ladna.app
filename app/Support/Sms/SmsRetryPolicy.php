<?php

namespace App\Support\Sms;

class SmsRetryPolicy
{
    public const int MaxAttempts = 20;

    public const string TransientFailure = 'sms_provider_temporarily_unavailable';

    public static function backoffMinutes(int $attempts): int
    {
        return match ($attempts) {
            1 => 1,
            2 => 5,
            3 => 15,
            default => 30,
        };
    }
}
