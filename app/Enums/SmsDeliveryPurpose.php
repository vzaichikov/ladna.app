<?php

namespace App\Enums;

enum SmsDeliveryPurpose: string
{
    case CustomerOtp = 'customer_otp';
    case FestivalOtp = 'festival_otp';
    case UserOtp = 'user_otp';
    case CustomerNotification = 'customer_notification';

    public function isAuthenticationOtp(): bool
    {
        return in_array($this, [self::CustomerOtp, self::FestivalOtp, self::UserOtp], true);
    }
}
