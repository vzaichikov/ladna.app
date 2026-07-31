<?php

namespace App\Enums;

enum SmsDeliveryPurpose: string
{
    case CustomerOtp = 'customer_otp';
    case UserOtp = 'user_otp';
    case CustomerNotification = 'customer_notification';
}
