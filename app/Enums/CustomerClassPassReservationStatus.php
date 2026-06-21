<?php

namespace App\Enums;

enum CustomerClassPassReservationStatus: string
{
    case Reserved = 'reserved';
    case Used = 'used';
    case Released = 'released';
}
