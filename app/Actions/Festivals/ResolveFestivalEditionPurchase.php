<?php

namespace App\Actions\Festivals;

use App\Models\FestivalEditionPurchase;
use App\Support\Payments\PaymentCallbackResult;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResolveFestivalEditionPurchase
{
    public function execute(string $provider, PaymentCallbackResult $callback): ?FestivalEditionPurchase
    {
        $references = array_values(array_filter([$callback->orderId ?: null, $callback->gatewayInvoiceId, $callback->gatewayPaymentId]));
        if ($references === []) {
            return null;
        }

        $purchase = FestivalEditionPurchase::query()
            ->with('account')
            ->where('provider', $provider)
            ->where(fn ($query) => $query->whereIn('order_id', $references)->orWhereIn('gateway_invoice_id', $references)->orWhereIn('gateway_payment_id', $references))
            ->first();

        if ($purchase?->account?->isReadOnlyDemo()) {
            throw new HttpException(Response::HTTP_LOCKED, __('app.demo_readonly_message'));
        }

        return $purchase;
    }
}
