<?php

namespace App\Enums;

enum FestivalRegistrantType: string
{
    case Coach = 'coach';
    case Guardian = 'guardian';
    case AdultAthlete = 'adult_athlete';
}
