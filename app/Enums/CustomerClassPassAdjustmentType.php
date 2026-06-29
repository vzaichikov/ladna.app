<?php

namespace App\Enums;

enum CustomerClassPassAdjustmentType: string
{
    case Sessions = 'sessions';
    case ValidityDays = 'validity_days';
    case Freeze = 'freeze';
    case Unfreeze = 'unfreeze';
}
