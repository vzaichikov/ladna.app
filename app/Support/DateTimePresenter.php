<?php

namespace App\Support;

use App\Models\Account;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;

class DateTimePresenter
{
    public static function accountTimezone(?Account $account): string
    {
        return self::safeTimezone($account?->timezone);
    }

    public static function safeTimezone(?string $timezone): string
    {
        $fallback = (string) config('app.timezone', 'UTC');

        foreach ([$timezone, $fallback, 'UTC'] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            try {
                new DateTimeZone($candidate);

                return $candidate;
            } catch (Throwable) {
                continue;
            }
        }

        return 'UTC';
    }

    public static function format(?CarbonInterface $date, ?Account $account, string $format = 'Y-m-d H:i'): ?string
    {
        return self::formatInTimezone($date, self::accountTimezone($account), $format);
    }

    public static function formatInTimezone(?CarbonInterface $date, string $timezone, string $format = 'Y-m-d H:i'): ?string
    {
        return $date?->copy()->timezone(self::safeTimezone($timezone))->format($format);
    }

    public static function date(?CarbonInterface $date, ?Account $account): ?string
    {
        return self::format($date, $account, 'Y-m-d');
    }

    public static function dateTimeLocal(?CarbonInterface $date, ?Account $account): ?string
    {
        return self::format($date, $account, 'Y-m-d\TH:i');
    }

    public static function parseAccountDateTime(?string $value, ?Account $account): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value, self::accountTimezone($account))
            ->timezone((string) config('app.timezone', 'UTC'));
    }
}
