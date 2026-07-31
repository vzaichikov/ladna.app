<?php

namespace App\Support\CustomerAuth;

interface SmsGatewayStatusProvider
{
    public function maxStatusBatchSize(): int;

    public function maxStatusRequestsPerSecond(): int;

    /**
     * @param  list<string>  $providerMessageIds
     */
    public function fetchDeliveryStatuses(array $providerMessageIds): SmsGatewayStatusBatchResult;
}
