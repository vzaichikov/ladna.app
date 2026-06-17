<?php

namespace App\Enums;

enum ClassBookingStatus: string
{
    case Booked = 'booked';
    case Attended = 'attended';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';
}
