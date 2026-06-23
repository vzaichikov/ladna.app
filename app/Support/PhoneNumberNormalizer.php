<?php

namespace App\Support;

class PhoneNumberNormalizer
{
    public function normalize(?string $phone, string $countryCode = 'UA'): ?string
    {
        $value = trim((string) $phone);

        if ($value === '') {
            return null;
        }

        $hasInternationalPrefix = str_starts_with($value, '+');
        $digits = preg_replace('/\D+/', '', $value) ?: '';

        if ($digits === '') {
            return null;
        }

        if ($hasInternationalPrefix) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        if (strtoupper($countryCode) === 'UA') {
            if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
                return '+38'.$digits;
            }

            if (strlen($digits) === 12 && str_starts_with($digits, '380')) {
                return '+'.$digits;
            }
        }

        return '+'.$digits;
    }

    public function isValid(?string $phone, string $countryCode = 'UA'): bool
    {
        $normalized = $this->normalize($phone, $countryCode);

        if (! $normalized) {
            return false;
        }

        $digits = preg_replace('/\D+/', '', $normalized) ?: '';

        if (strtoupper($countryCode) === 'UA') {
            return strlen($digits) === 12 && str_starts_with($digits, '380');
        }

        return strlen($digits) >= 8 && strlen($digits) <= 15;
    }
}
