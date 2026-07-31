<?php

namespace App\Support\Sms;

use App\Models\SmsTopUpPayment;
use App\Support\Payments\PaymentCallbackResult;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResolveSmsTopUpPayment
{
    public function execute(string $provider, PaymentCallbackResult $callback): ?SmsTopUpPayment
    {
        $references = array_values(array_filter([
            $callback->orderId !== '' ? $callback->orderId : null,
            $callback->gatewayInvoiceId,
            $callback->gatewayPaymentId,
        ]));

        if ($references === []) {
            return null;
        }

        $payment = SmsTopUpPayment::query()
            ->with('account')
            ->where('provider', $provider)
            ->where(function ($query) use ($references): void {
                $query
                    ->whereIn('order_id', $references)
                    ->orWhereIn('gateway_invoice_id', $references)
                    ->orWhereIn('gateway_payment_id', $references);
            })
            ->first();

        if ($payment?->account?->isReadOnlyDemo()) {
            throw new HttpException(Response::HTTP_LOCKED, __('app.demo_readonly_message'));
        }

        return $payment;
    }
}
