<?php

namespace App\Enums;

enum FestivalRegistrantType: string
{
    case Coach = 'coach';
    case Guardian = 'guardian';
    case AdultAthlete = 'adult_athlete';

    /** @return list<self> */
    public static function selectableCases(?self $current = null, bool $locked = false): array
    {
        if ($locked && $current !== null) {
            return [$current];
        }

        $cases = [self::AdultAthlete, self::Coach];

        if ($current === self::Guardian) {
            $cases[] = self::Guardian;
        }

        return $cases;
    }
}
