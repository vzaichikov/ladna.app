<?php

namespace App\Enums;

enum EventTicketStatus: string
{
    case Valid = 'valid';
    case Voided = 'voided';
    case Refunded = 'refunded';
}
