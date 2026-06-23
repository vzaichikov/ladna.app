<?php

namespace App\Support\CustomerAuth;

use Illuminate\Support\Facades\Log;

class LogSmsGateway implements SmsGateway
{
    public function sendOtp(string $phone, string $message): SmsGatewayResult
    {
        Log::info('Customer OTP SMS requested.', [
            'phone' => $phone,
            'message' => $message,
        ]);

        return SmsGatewayResult::sent();
    }
}
