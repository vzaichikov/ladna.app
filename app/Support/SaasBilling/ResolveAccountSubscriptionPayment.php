<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPayment;
use App\Support\Payments\PaymentCallbackResult;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResolveAccountSubscriptionPayment
{
    public function execute(string $provider, PaymentCallbackResult $callback): ?AccountSubscriptionPayment
    {
        $payment = $this->findDirectPayment($provider, $callback);

        if ($payment) {
            $this->ensureAccountIsWritable($payment);

            return $payment;
        }

        $subscriptionId = $this->subscriptionId($callback);

        if ($subscriptionId === null) {
            return null;
        }

        $pendingPayment = AccountSubscriptionPayment::query()
            ->with('account')
            ->where('provider', $provider)
            ->where('gateway_subscription_id', $subscriptionId)
            ->whereIn('status', [
                AccountSubscriptionPaymentStatus::PaymentStarted->value,
                AccountSubscriptionPaymentStatus::PaymentPending->value,
            ])
            ->latest('id')
            ->first();

        if ($pendingPayment) {
            $this->ensureAccountIsWritable($pendingPayment);

            return $pendingPayment;
        }

        $subscription = AccountSubscription::query()
            ->with(['account.subscription', 'plan'])
            ->where('payment_provider', $provider)
            ->where('provider_subscription_id', $subscriptionId)
            ->first();

        if (! $subscription || ! $subscription->account || ! $subscription->plan) {
            return null;
        }

        if ($subscription->account->isReadOnlyDemo()) {
            throw new HttpException(Response::HTTP_LOCKED, __('app.demo_readonly_message'));
        }

        $periodStart = $subscription->ends_at?->isFuture()
            ? $subscription->ends_at
            : ($callback->paidAt ?? now());
        $periodEnd = $periodStart->copy()->addDays($subscription->plan->access_days ?? 30);

        return AccountSubscriptionPayment::create([
            'account_id' => $subscription->account_id,
            'account_subscription_id' => $subscription->id,
            'subscription_plan_id' => $subscription->subscription_plan_id,
            'provider' => $provider,
            'payment_type' => AccountSubscriptionPaymentType::AutoRenewal,
            'order_id' => $this->orderId(),
            'gateway_invoice_id' => $callback->gatewayInvoiceId,
            'gateway_payment_id' => $callback->gatewayPaymentId,
            'gateway_subscription_id' => $subscriptionId,
            'gateway_status' => $callback->gatewayStatus,
            'amount_cents' => $callback->amountCents ?? $subscription->plan->price_cents,
            'currency' => $callback->currency ?? $subscription->plan->currency,
            'period_starts_at' => $periodStart,
            'period_ends_at' => $periodEnd,
            'started_at' => $callback->paidAt ?? now(),
            'expires_at' => now()->addHour(),
        ]);
    }

    private function findDirectPayment(string $provider, PaymentCallbackResult $callback): ?AccountSubscriptionPayment
    {
        $references = array_values(array_filter([
            $callback->orderId !== '' ? $callback->orderId : null,
            $callback->gatewayInvoiceId,
            $callback->gatewayPaymentId,
        ]));

        if ($references === []) {
            return null;
        }

        return AccountSubscriptionPayment::query()
            ->with('account')
            ->where('provider', $provider)
            ->where(function ($query) use ($references): void {
                $query
                    ->whereIn('order_id', $references)
                    ->orWhereIn('gateway_invoice_id', $references)
                    ->orWhereIn('gateway_payment_id', $references);
            })
            ->latest('id')
            ->first();
    }

    private function ensureAccountIsWritable(AccountSubscriptionPayment $payment): void
    {
        if ($payment->account?->isReadOnlyDemo()) {
            throw new HttpException(Response::HTTP_LOCKED, __('app.demo_readonly_message'));
        }
    }

    private function subscriptionId(PaymentCallbackResult $callback): ?string
    {
        $subscriptionId = $callback->payload['subscriptionId'] ?? null;

        return is_string($subscriptionId) && $subscriptionId !== ''
            ? $subscriptionId
            : null;
    }

    private function orderId(): string
    {
        return 'SAAS-AUTO-'.now()->format('YmdHis').'-'.Str::upper(Str::random(10));
    }
}
