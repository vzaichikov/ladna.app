<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\CompleteCustomerPurchase;
use App\Http\Controllers\Controller;
use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackLogger;
use App\Support\Payments\PaymentGatewayException;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CustomerPurchaseCallbackController extends Controller
{
    public function store(
        Request $request,
        string $provider,
        PaymentGatewayRegistry $gateways,
        CompleteCustomerPurchase $completeCustomerPurchase,
        PaymentCallbackLogger $logger,
    ): Response {
        try {
            $gateway = $gateways->get($provider);
        } catch (PaymentGatewayException) {
            return response('Unsupported provider.', Response::HTTP_NOT_FOUND);
        }

        $orderId = $gateway->orderIdFromCallback($request);
        $purchase = $orderId
            ? CustomerPurchase::query()
                ->with('account')
                ->where('provider', $gateway->provider()->value)
                ->where('order_id', $orderId)
                ->first()
            : null;

        $logger->log($purchase, $provider, $orderId, $request, 'received');

        if (! $purchase) {
            $logger->log(null, $provider, $orderId, $request, 'unknown-purchase');

            return response('Unknown purchase.', Response::HTTP_NOT_FOUND);
        }

        $setting = IntegrationSetting::forAccount($purchase->account)
            ->where('provider', $gateway->provider()->value)
            ->where('is_enabled', true)
            ->first();

        if (! $setting) {
            $logger->log($purchase, $provider, $orderId, $request, 'missing-integration');

            return response('Payment integration is unavailable.', Response::HTTP_NOT_FOUND);
        }

        try {
            $callback = $gateway->handleCallback($request, $setting);
            $purchase = $completeCustomerPurchase->execute($purchase, $callback);
        } catch (InvalidPaymentCallbackException $exception) {
            $logger->log($purchase, $provider, $orderId, $request, 'invalid', [
                'message' => $exception->getMessage(),
            ]);

            return response('Invalid callback.', Response::HTTP_BAD_REQUEST);
        } catch (Throwable $exception) {
            $logger->log($purchase, $provider, $orderId, $request, 'error', [
                'message' => $exception->getMessage(),
            ]);

            return response('Callback failed.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $logger->log($purchase, $provider, $orderId, $request, 'accepted', [
            'status' => $purchase->status->value,
        ]);

        return $gateway->callbackResponse($purchase, $setting);
    }
}
