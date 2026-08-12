<?php

namespace App\Enums;

enum FestivalBattleMatchStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Completed = 'completed';
}
