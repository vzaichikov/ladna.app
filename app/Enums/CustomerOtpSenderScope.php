<?php

namespace App\Enums;

enum CustomerOtpSenderScope: string
{
    case Platform = 'platform';
    case Account = 'account';
}
