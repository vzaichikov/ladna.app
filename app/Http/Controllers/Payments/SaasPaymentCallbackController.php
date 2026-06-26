<?php

namespace App\Http\Controllers\Payments;

use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\SaasBilling\CompleteAccountSubscriptionPayment;
use App\Support\SaasBilling\MonopaySaasBilling;
use App\Support\SaasBilling\ResolveAccountSubscriptionPayment;
use App\Support\SaasBilling\SaasPaymentCallbackLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SaasPaymentCallbackController extends Controller
{
    public function store(
        Request $request,
        string $provider,
        MonopaySaasBilling $billing,
        CompleteAccountSubscriptionPayment $completePayment,
        ResolveAccountSubscriptionPayment $resolvePayment,
        SaasPaymentCallbackLogger $logger,
    ): Response {
        if ($provider !== IntegrationProvider::Monopay->value) {
            return response('Unsupported provider.', Response::HTTP_NOT_FOUND);
        }

        $orderId = $billing->orderIdFromCallback($request);

        $logger->log(null, $provider, $orderId, $request, 'received');

        $setting = $billing->platformSetting();

        if (! $setting) {
            $logger->log(null, $provider, $orderId, $request, 'missing-platform-integration');

            return response('Payment integration is unavailable.', Response::HTTP_NOT_FOUND);
        }

        try {
            $callback = $billing->handleCallback($request, $setting);
            $payment = $resolvePayment->execute(IntegrationProvider::Monopay->value, $callback);

            if (! $payment) {
                $logger->log(null, $provider, $orderId, $request, 'unknown-payment');

                return response('Unknown payment.', Response::HTTP_NOT_FOUND);
            }

            $payment = $completePayment->execute($payment, $callback);
        } catch (InvalidPaymentCallbackException $exception) {
            $logger->log(null, $provider, $orderId, $request, 'invalid', [
                'message' => $exception->getMessage(),
            ]);

            return response('Invalid callback.', Response::HTTP_BAD_REQUEST);
        } catch (Throwable $exception) {
            $logger->log(null, $provider, $orderId, $request, 'error', [
                'message' => $exception->getMessage(),
            ]);

            return response('Callback failed.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $logger->log($payment, $provider, $orderId, $request, 'accepted', [
            'status' => $payment->status->value,
        ]);

        return response('OK');
    }
}
