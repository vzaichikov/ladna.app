<?php

namespace App\Http\Controllers\Payments;

use App\Actions\CompleteEventOrder;
use App\Http\Controllers\Controller;
use App\Models\EventOrder;
use App\Models\IntegrationSetting;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackLogger;
use App\Support\Payments\PaymentGatewayException;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EventOrderCallbackController extends Controller
{
    public function store(
        Request $request,
        string $provider,
        PaymentGatewayRegistry $gateways,
        CompleteEventOrder $complete,
        PaymentCallbackLogger $logger,
    ): Response {
        try {
            $gateway = $gateways->get($provider);
        } catch (PaymentGatewayException) {
            return response('Unsupported provider.', Response::HTTP_NOT_FOUND);
        }

        $orderId = $gateway->orderIdFromCallback($request);
        $order = $orderId ? EventOrder::query()
            ->with('account')
            ->where('provider', $gateway->provider()->value)
            ->where('order_id', $orderId)
            ->first() : null;

        $logger->log($order, $provider, $orderId, $request, 'received');

        if (! $order) {
            $logger->log(null, $provider, $orderId, $request, 'unknown-event-order');

            return response('Unknown event order.', Response::HTTP_NOT_FOUND);
        }

        if ($order->account->isReadOnlyDemo()) {
            return response(__('app.demo_readonly_message'), Response::HTTP_LOCKED);
        }

        $setting = IntegrationSetting::forAccount($order->account)
            ->where('provider', $gateway->provider()->value)
            ->where('is_enabled', true)
            ->first();

        if (! $setting) {
            $logger->log($order, $provider, $orderId, $request, 'missing-integration');

            return response('Payment integration is unavailable.', Response::HTTP_NOT_FOUND);
        }

        try {
            $callback = $gateway->handleCallback($request, $setting);
            $order = $complete->execute($order, $callback);
        } catch (InvalidPaymentCallbackException $exception) {
            $logger->log($order, $provider, $orderId, $request, 'invalid', [
                'message' => $exception->getMessage(),
            ]);

            return response('Invalid callback.', Response::HTTP_BAD_REQUEST);
        } catch (Throwable $exception) {
            $logger->log($order, $provider, $orderId, $request, 'error', [
                'message' => $exception->getMessage(),
            ]);
            report($exception);

            return response('Callback failed.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $logger->log($order, $provider, $orderId, $request, 'accepted', [
            'status' => $order->status->value,
        ]);

        return $gateway->callbackResponse($order->order_id, $setting);
    }
}
