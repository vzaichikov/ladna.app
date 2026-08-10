<?php

namespace App\Enums;

enum FestivalFieldScope: string
{
    case Registrant = 'registrant';
    case Participant = 'participant';
    case Entry = 'entry';
}
