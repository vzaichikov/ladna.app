<?php

namespace App\Enums;

enum FestivalRegistrantType: string
{
    case Coach = 'coach';
    case Guardian = 'guardian';
    case AdultAthlete = 'adult_athlete';

    /** @return list<self> */
    public static function selectableCases(?self $current = null): array
    {
        $cases = $current === self::AdultAthlete
            ? [self::AdultAthlete]
            : [self::AdultAthlete, self::Coach];

        if ($current === self::Guardian) {
            $cases[] = self::Guardian;
        }

        return $cases;
    }
}
