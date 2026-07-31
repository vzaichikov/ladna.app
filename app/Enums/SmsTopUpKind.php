<?php

namespace App\Enums;

enum SmsTopUpKind: string
{
    case Manual = 'manual';
    case Automatic = 'automatic';
}
