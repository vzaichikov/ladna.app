<?php

namespace App\Support\Payments;

class PaymentAmounts
{
    public static function centsToDecimalString(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function decimalToCents(mixed $amount): ?int
    {
        if (! is_numeric($amount)) {
            return null;
        }

        return (int) round(((float) $amount) * 100);
    }

    public static function iso4217NumericCode(string $currency): int
    {
        return match (strtoupper($currency)) {
            'USD' => 840,
            'EUR' => 978,
            default => 980,
        };
    }

    public static function currencyFromIso4217(mixed $code): string
    {
        return match ((int) $code) {
            840 => 'USD',
            978 => 'EUR',
            default => 'UAH',
        };
    }
}
