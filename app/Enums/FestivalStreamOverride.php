<?php

namespace App\Enums;

enum FestivalStreamOverride: string
{
    case Automatic = 'automatic';
    case Open = 'open';
    case Closed = 'closed';
}
