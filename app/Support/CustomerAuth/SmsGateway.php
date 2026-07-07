<?php

namespace App\Support\CustomerAuth;

interface SmsGateway
{
    public function sendOtp(string $phone, string $message): SmsGatewayResult;

    public function sendSms(string $phone, string $message): SmsGatewayResult;
}
