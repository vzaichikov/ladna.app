<?php

namespace App\Support\CustomerAuth;

interface SmsGateway
{
    public function sendOtp(string $phone, string $message): SmsGatewayResult;
}
