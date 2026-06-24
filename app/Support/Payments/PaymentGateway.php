<?php

namespace App\Support\Payments;

use App\Enums\IntegrationProvider;
use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface PaymentGateway
{
    public function provider(): IntegrationProvider;

    public function start(CustomerPurchase $purchase, IntegrationSetting $setting): PaymentCheckout;

    public function orderIdFromCallback(Request $request): ?string;

    public function handleCallback(Request $request, IntegrationSetting $setting): PaymentCallbackResult;

    public function callbackResponse(CustomerPurchase $purchase, IntegrationSetting $setting): Response;
}
