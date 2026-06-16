<?php

namespace App\Enums;

enum ScheduledClassStatus: string
{
    case Scheduled = 'scheduled';
    case Draft = 'draft';
    case Cancelled = 'cancelled';
}
