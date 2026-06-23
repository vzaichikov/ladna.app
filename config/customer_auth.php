<?php

return [
    'remember_days' => 90,

    'otp' => [
        'code_digits' => 6,
        'ttl_minutes' => 10,
        'resend_seconds' => 60,
        'max_attempts' => 5,
        'max_sends' => 3,
        'testing_code' => env('CUSTOMER_AUTH_TESTING_OTP_CODE', '123456'),
    ],
];
