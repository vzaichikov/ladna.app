<?php

namespace App\Enums;

enum FestivalTicketStatus: string
{
    case Valid = 'valid';
    case Voided = 'voided';
    case Refunded = 'refunded';
}
