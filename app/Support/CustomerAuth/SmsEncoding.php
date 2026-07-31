<?php

namespace App\Support\CustomerAuth;

enum SmsEncoding: string
{
    case Gsm7 = 'gsm7';
    case Ucs2 = 'ucs2';
}
