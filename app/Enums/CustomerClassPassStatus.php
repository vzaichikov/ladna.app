<?php

namespace App\Enums;

enum CustomerClassPassStatus: string
{
    case Active = 'active';
    case UsedUp = 'used_up';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
