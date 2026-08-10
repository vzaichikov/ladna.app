<?php

namespace App\Support;

use Closure;

final class FestivalCodeGenerator
{
    private const MAX_BASE_LENGTH = 80;

    /** @param list<string> $reserved */
    public static function unique(?string $label, string $fallback, Closure $isTaken, array $reserved = []): string
    {
        $source = str(SlugGenerator::base($label, $fallback))
            ->substr(0, self::MAX_BASE_LENGTH)
            ->toString();

        return SlugGenerator::unique($source, $fallback, $isTaken, $reserved);
    }
}
