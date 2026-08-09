<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalTicketOrder;
use App\Models\IntegrationSetting;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentGatewayException;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class FestivalPaymentCallbackController extends Controller
{
    public function store(Request $request, string $provider, PaymentGatewayRegistry $gateways, FestivalPaymentService $payments): Response
    {
        try {
            $gateway = $gateways->get($provider);
        } catch (PaymentGatewayException) {
            return response('Unsupported provider.', Response::HTTP_NOT_FOUND);
        }

        $orderId = $gateway->orderIdFromCallback($request);
        $attempt = $orderId ? FestivalPaymentAttempt::query()->with('account')->where('provider', $gateway->provider()->value)->where('order_id', $orderId)->first() : null;
        $ticketOrder = ! $attempt && $orderId ? FestivalTicketOrder::query()->with('account')->where('provider', $gateway->provider()->value)->where('order_id', $orderId)->first() : null;
        $account = $attempt?->account ?? $ticketOrder?->account;

        Log::info('festival_payment_callback_received', ['provider' => $provider, 'order_id_hash' => $orderId ? hash('sha256', $orderId) : null, 'resolved' => (bool) $account]);

        if (! $account instanceof Account || ! $account->enable_festivals || $account->isReadOnlyDemo()) {
            return response('Unknown Festival payment.', Response::HTTP_NOT_FOUND);
        }

        $setting = IntegrationSetting::forAccount($account)->where('provider', $gateway->provider()->value)->where('is_enabled', true)->first();
        if (! $setting) {
            return response('Payment integration is unavailable.', Response::HTTP_NOT_FOUND);
        }

        try {
            $callback = $gateway->handleCallback($request, $setting);
            if ($attempt) {
                $payments->completeAttempt($attempt, $callback);
            } elseif ($ticketOrder) {
                $payments->completeOrder($ticketOrder, $callback);
            }
        } catch (InvalidPaymentCallbackException) {
            return response('Invalid callback.', Response::HTTP_BAD_REQUEST);
        } catch (Throwable $exception) {
            report($exception);

            return response('Callback failed.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $gateway->callbackResponse((string) $orderId, $setting);
    }
}
