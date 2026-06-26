<?php

namespace App\Support;

class MoneyFormatter
{
    public static function format(?int $amountCents, ?string $currency = 'UAH', ?int $fallbackCents = null): string
    {
        $cents = $amountCents ?? $fallbackCents ?? 0;
        $currencyCode = strtoupper($currency ?: 'UAH');
        $precision = $cents % 100 === 0 ? 0 : 2;

        return number_format($cents / 100, $precision, '.', ' ').' '.self::symbol($currencyCode);
    }

    public static function symbol(string $currency): string
    {
        $currencyCode = strtoupper($currency);
        $symbol = config('ladna.currency_symbols.'.$currencyCode);

        return is_string($symbol) && $symbol !== '' ? $symbol : $currencyCode;
    }
}
