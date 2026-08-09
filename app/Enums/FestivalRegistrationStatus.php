<?php

namespace App\Enums;

enum FestivalRegistrationStatus: string
{
    case Closed = 'closed';
    case Open = 'open';
    case Paused = 'paused';
}
