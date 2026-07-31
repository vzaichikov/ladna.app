<?php

namespace App\Support\CustomerAuth;

use InvalidArgumentException;

class SmsSegmentCalculator
{
    private const array Gsm7BasicCharacters = [
        '@', '£', '$', '¥', 'è', 'é', 'ù', 'ì', 'ò', 'Ç', "\n", 'Ø', 'ø', "\r", 'Å', 'å',
        'Δ', '_', 'Φ', 'Γ', 'Λ', 'Ω', 'Π', 'Ψ', 'Σ', 'Θ', 'Ξ', 'Æ', 'æ', 'ß', 'É', ' ',
        '!', '"', '#', '¤', '%', '&', "'", '(', ')', '*', '+', ',', '-', '.', '/',
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', ':', ';', '<', '=', '>', '?',
        '¡', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O',
        'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'Ä', 'Ö', 'Ñ', 'Ü', '§',
        '¿', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o',
        'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z', 'ä', 'ö', 'ñ', 'ü', 'à',
    ];

    private const array Gsm7ExtensionCharacters = [
        "\f", '^', '{', '}', '\\', '[', '~', ']', '|', '€',
    ];

    public function estimate(string $message): SmsSegmentEstimate
    {
        if (! mb_check_encoding($message, 'UTF-8')) {
            throw new InvalidArgumentException('SMS message must be valid UTF-8.');
        }

        if ($message === '') {
            return new SmsSegmentEstimate(SmsEncoding::Gsm7, 0, 0, 160, 153);
        }

        $gsm7Units = $this->gsm7Units($message);

        if ($gsm7Units !== null) {
            return new SmsSegmentEstimate(
                encoding: SmsEncoding::Gsm7,
                units: $gsm7Units,
                segments: $this->segments($gsm7Units, 160, 153),
                singleSegmentLimit: 160,
                concatenatedSegmentLimit: 153,
            );
        }

        $ucs2Units = (int) (strlen(mb_convert_encoding($message, 'UTF-16BE', 'UTF-8')) / 2);

        return new SmsSegmentEstimate(
            encoding: SmsEncoding::Ucs2,
            units: $ucs2Units,
            segments: $this->segments($ucs2Units, 70, 67),
            singleSegmentLimit: 70,
            concatenatedSegmentLimit: 67,
        );
    }

    private function gsm7Units(string $message): ?int
    {
        $units = 0;

        foreach (mb_str_split($message) as $character) {
            if (in_array($character, self::Gsm7BasicCharacters, true)) {
                $units++;

                continue;
            }

            if (in_array($character, self::Gsm7ExtensionCharacters, true)) {
                $units += 2;

                continue;
            }

            return null;
        }

        return $units;
    }

    private function segments(int $units, int $singleSegmentLimit, int $concatenatedSegmentLimit): int
    {
        if ($units <= $singleSegmentLimit) {
            return 1;
        }

        return (int) ceil($units / $concatenatedSegmentLimit);
    }
}
