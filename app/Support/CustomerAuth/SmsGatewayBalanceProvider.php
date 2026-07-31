<?php

namespace App\Support\CustomerAuth;

interface SmsGatewayBalanceProvider
{
    public function fetchBalance(): SmsGatewayBalanceResult;
}
