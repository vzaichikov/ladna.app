<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Festivals\CompleteFestivalEditionPurchase;
use App\Actions\Festivals\ResolveFestivalEditionPurchase;
use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\SaasBilling\CompleteAccountSubscriptionPayment;
use App\Support\SaasBilling\CompletePaymentMethodVerification;
use App\Support\SaasBilling\MonopaySaasBilling;
use App\Support\SaasBilling\ResolveAccountSubscriptionPayment;
use App\Support\SaasBilling\SaasPaymentCallbackLogger;
use App\Support\Sms\CompleteSmsTopUpPayment;
use App\Support\Sms\ResolveSmsTopUpPayment;
use App\Support\Sms\ResumeSmsPaymentAfterVerification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class SaasPaymentCallbackController extends Controller
{
    public function store(
        Request $request,
        string $provider,
        MonopaySaasBilling $billing,
        CompleteAccountSubscriptionPayment $completePayment,
        CompletePaymentMethodVerification $completeVerification,
        ResumeSmsPaymentAfterVerification $resumeAfterVerification,
        ResolveAccountSubscriptionPayment $resolvePayment,
        ResolveSmsTopUpPayment $resolveSmsTopUp,
        CompleteSmsTopUpPayment $completeSmsTopUp,
        ResolveFestivalEditionPurchase $resolveFestivalPurchase,
        CompleteFestivalEditionPurchase $completeFestivalPurchase,
        SaasPaymentCallbackLogger $logger,
    ): Response {
        if ($provider !== IntegrationProvider::Monopay->value) {
            return response('Unsupported provider.', Response::HTTP_NOT_FOUND);
        }

        $orderId = $billing->orderIdFromCallback($request);

        $setting = $billing->platformSetting();

        if (! $setting) {
            $logger->log(null, $provider, $orderId, $request, 'missing-platform-integration');

            return response('Payment integration is unavailable.', Response::HTTP_NOT_FOUND);
        }

        try {
            $callback = $billing->handleCallback($request, $setting);

            if ($completeVerification->execute($callback)) {
                $resumeAfterVerification->execute(
                    $callback,
                    $setting,
                );
                $logger->log(null, $provider, $orderId, $request, 'payment-method-verification-accepted');

                return response('OK');
            }

            $smsTopUp = $resolveSmsTopUp->execute(IntegrationProvider::Monopay->value, $callback);

            if ($smsTopUp) {
                $logger->log($smsTopUp, $provider, $orderId, $request, 'received');
                $smsTopUp = $completeSmsTopUp->execute($smsTopUp, $callback);
                $logger->log($smsTopUp, $provider, $orderId, $request, 'accepted', [
                    'status' => $smsTopUp->status->value,
                ]);

                return response('OK');
            }

            $festivalPurchase = $resolveFestivalPurchase->execute(IntegrationProvider::Monopay->value, $callback);

            if ($festivalPurchase) {
                $logger->log($festivalPurchase, $provider, $orderId, $request, 'received');
                $festivalPurchase = $completeFestivalPurchase->execute($festivalPurchase, $callback);
                $logger->log($festivalPurchase, $provider, $orderId, $request, 'accepted', [
                    'status' => $festivalPurchase->status->value,
                ]);

                return response('OK');
            }

            $payment = $resolvePayment->execute(IntegrationProvider::Monopay->value, $callback);

            $logger->log($payment, $provider, $orderId, $request, 'received');

            if (! $payment) {
                $logger->log(null, $provider, $orderId, $request, 'unknown-payment');

                return response('Unknown payment.', Response::HTTP_NOT_FOUND);
            }

            $payment = $completePayment->execute($payment, $callback);
        } catch (HttpException $exception) {
            return response($exception->getMessage(), $exception->getStatusCode());
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
