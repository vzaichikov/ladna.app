<?php

namespace App\Support\Payments;

use App\Enums\IntegrationProvider;
use App\Models\IntegrationSetting;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface PaymentGateway
{
    public function provider(): IntegrationProvider;

    public function start(PaymentCheckoutRequest $checkout, IntegrationSetting $setting): PaymentCheckout;

    public function orderIdFromCallback(Request $request): ?string;

    public function handleCallback(Request $request, IntegrationSetting $setting): PaymentCallbackResult;

    public function callbackResponse(string $reference, IntegrationSetting $setting): Response;
}
