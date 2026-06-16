<?php

namespace App\Enums;

enum ScheduleSeriesStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
}
