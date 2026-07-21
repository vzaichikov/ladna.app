<?php

namespace App\Console\Commands;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\IntegrationProvider;
use App\Enums\SubscriptionBillingInterval;
use App\Enums\SubscriptionBillingMode;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Enums\SubscriptionPriceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AccountSubscription;
use App\Models\SubscriptionPriceVersion;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\MoneyFormatter;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\SaasBilling\CancelAccountSubscription;
use App\Support\SaasBilling\ChargeAccountSubscription;
use App\Support\SaasBilling\CompleteAccountSubscriptionPayment;
use App\Support\SaasBilling\CreateAccountSubscriptionPayment;
use App\Support\SaasBilling\CreateBillingV2Payment;
use App\Support\SaasBilling\MonopaySaasBilling;
use App\Support\SaasBilling\SendBillingLifecycleNotification;
use App\Support\SaasBilling\SubscriptionPricingCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('billing:reconcile')]
#[Description('Reconcile SaaS trials, tokenized renewals, grace periods, cancellations, and legacy provider status.')]
class ReconcileSaasBilling extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        MonopaySaasBilling $billing,
        CreateAccountSubscriptionPayment $createLegacyPayment,
        TransactionalMailDispatcher $mailDispatcher,
        CreateBillingV2Payment $createBillingV2Payment,
        ChargeAccountSubscription $chargeSubscription,
        CompleteAccountSubscriptionPayment $completePayment,
        CancelAccountSubscription $cancelSubscription,
        SendBillingLifecycleNotification $sendNotification,
        SubscriptionPricingCalculator $pricing,
    ): int {
        $billingV2Enabled = (bool) config('ladna.saas_billing_v2_enabled');

        if ($billingV2Enabled) {
            SubscriptionPriceVersion::query()
                ->where('status', SubscriptionPriceStatus::Scheduled->value)
                ->whereNotNull('effective_at')
                ->where('effective_at', '<=', now())
                ->lazyById()
                ->each(fn (SubscriptionPriceVersion $priceVersion) => $priceVersion->publish());

            $this->sendBillingV2Reminders($sendNotification, $pricing);
        }

        $v2 = $this->reconcileBillingV2(
            $billing,
            $mailDispatcher,
            $createBillingV2Payment,
            $chargeSubscription,
            $completePayment,
            $cancelSubscription,
            $billingV2Enabled,
        );

        $expired = 0;
        AccountSubscription::query()
            ->where('billing_mode', SubscriptionBillingMode::Legacy->value)
            ->whereHas('account', fn ($query) => $query->operational())
            ->whereIn('status', [
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->with(['account.users', 'plan'])
            ->lazyById()
            ->each(function (AccountSubscription $subscription) use (&$expired, $mailDispatcher): void {
                $subscription->forceFill([
                    'status' => SubscriptionStatus::Expired,
                    'auto_renew_enabled' => false,
                ])->save();

                $mailDispatcher->saasSubscriptionExpired($subscription->refresh());
                $expired++;
            });

        $checked = 0;
        $pastDue = 0;
        $setting = $billing->platformSetting();

        if ($setting) {
            AccountSubscription::query()
                ->where('billing_mode', SubscriptionBillingMode::Legacy->value)
                ->whereHas('account', fn ($query) => $query->operational())
                ->where('payment_provider', IntegrationProvider::Monopay->value)
                ->where('auto_renew_enabled', true)
                ->whereNotNull('provider_subscription_id')
                ->whereNotNull('next_payment_at')
                ->where('next_payment_at', '<=', now())
                ->whereIn('status', [
                    SubscriptionStatus::Trialing->value,
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::PastDue->value,
                ])
                ->with(['account.subscription', 'plan'])
                ->lazyById()
                ->each(function (AccountSubscription $subscription) use ($billing, $setting, $createLegacyPayment, $mailDispatcher, &$checked, &$pastDue): void {
                    $checked++;
                    $payload = $billing->subscriptionStatus((string) $subscription->provider_subscription_id, $setting);
                    $providerStatus = is_string($payload['status'] ?? null) ? $payload['status'] : null;

                    $subscription->forceFill([
                        'provider_status' => $providerStatus,
                    ]);

                    if (! $payload || in_array($providerStatus, ['cancelled', 'canceled', 'failure', 'failed', 'expired'], true)) {
                        if ($subscription->account && $subscription->plan) {
                            $alreadyTracked = $subscription->payments()
                                ->where('gateway_subscription_id', $subscription->provider_subscription_id)
                                ->where('period_starts_at', $subscription->ends_at)
                                ->whereIn('status', [
                                    AccountSubscriptionPaymentStatus::PaymentFailed->value,
                                    AccountSubscriptionPaymentStatus::PaymentCancelled->value,
                                    AccountSubscriptionPaymentStatus::PaymentExpired->value,
                                ])
                                ->exists();

                            if (! $alreadyTracked) {
                                $failedPayment = $createLegacyPayment->execute(
                                    $subscription->account,
                                    $subscription->plan,
                                    AccountSubscriptionPaymentType::AutoRenewal,
                                )->forceFill([
                                    'account_subscription_id' => $subscription->id,
                                    'gateway_subscription_id' => $subscription->provider_subscription_id,
                                    'gateway_status' => $providerStatus,
                                    'status' => AccountSubscriptionPaymentStatus::PaymentFailed,
                                    'last_callback_payload' => $payload ?? [],
                                    'failure_reason' => $providerStatus ?: 'subscription_status_unavailable',
                                    'failed_at' => now(),
                                ]);
                                $failedPayment->save();
                                $mailDispatcher->saasPaymentResolved($failedPayment->refresh());
                            }
                        }

                        $subscription->forceFill([
                            'status' => SubscriptionStatus::PastDue,
                            'auto_renew_enabled' => false,
                        ]);
                        $pastDue++;
                    }

                    $subscription->save();
                });
        }

        $this->components->info("Billing v2 charged: {$v2['charged']}");
        $this->components->info("Billing v2 expired: {$v2['expired']}");
        $this->components->info("Billing v2 cancelled: {$v2['cancelled']}");
        $this->components->info("Legacy expired subscriptions: {$expired}");
        $this->components->info("Checked legacy auto-renew subscriptions: {$checked}");
        $this->components->info("Marked legacy past due: {$pastDue}");

        return self::SUCCESS;
    }

    /**
     * @return array{charged: int, expired: int, cancelled: int}
     */
    private function reconcileBillingV2(
        MonopaySaasBilling $billing,
        TransactionalMailDispatcher $mailDispatcher,
        CreateBillingV2Payment $createPayment,
        ChargeAccountSubscription $chargeSubscription,
        CompleteAccountSubscriptionPayment $completePayment,
        CancelAccountSubscription $cancelSubscription,
        bool $chargingEnabled,
    ): array {
        $cancelled = 0;
        AccountSubscription::query()
            ->where('billing_mode', SubscriptionBillingMode::LocationV2->value)
            ->where('cancel_at_period_end', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->with('paymentMethod')
            ->lazyById()
            ->each(function (AccountSubscription $subscription) use ($cancelSubscription, &$cancelled): void {
                $cancelSubscription->finalize($subscription);
                $cancelled++;
            });

        $expired = 0;
        $charged = 0;

        if (! $chargingEnabled) {
            return compact('charged', 'expired', 'cancelled');
        }

        AccountSubscription::query()
            ->where('billing_mode', SubscriptionBillingMode::LocationV2->value)
            ->where(function ($query): void {
                $query
                    ->where(function ($trial): void {
                        $trial
                            ->where('status', SubscriptionStatus::Trialing->value)
                            ->whereNotNull('trial_ends_at')
                            ->where('trial_ends_at', '<=', now())
                            ->where(function ($unsubscribed): void {
                                $unsubscribed
                                    ->where('auto_renew_enabled', false)
                                    ->orWhereDoesntHave('paymentMethod', fn ($method) => $method
                                        ->where('status', SubscriptionPaymentMethodStatus::Active->value));
                            });
                    })
                    ->orWhere(function ($grace): void {
                        $grace
                            ->where('status', SubscriptionStatus::PastDue->value)
                            ->whereNotNull('grace_ends_at')
                            ->where('grace_ends_at', '<=', now());
                    });
            })
            ->with(['account.users', 'plan'])
            ->lazyById()
            ->each(function (AccountSubscription $subscription) use ($mailDispatcher, &$expired): void {
                $subscription->forceFill([
                    'status' => SubscriptionStatus::Expired,
                    'auto_renew_enabled' => false,
                    'next_payment_at' => null,
                    'next_retry_at' => null,
                ])->save();
                $mailDispatcher->saasSubscriptionExpired($subscription->refresh());
                $expired++;
            });

        $setting = $billing->platformSetting();

        if (! $setting) {
            return compact('charged', 'expired', 'cancelled');
        }

        AccountSubscription::query()
            ->where('billing_mode', SubscriptionBillingMode::LocationV2->value)
            ->where('auto_renew_enabled', true)
            ->where('cancel_at_period_end', false)
            ->whereNotNull('next_payment_at')
            ->where('next_payment_at', '<=', now())
            ->whereIn('status', [
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->where(function ($query): void {
                $query
                    ->where('status', '!=', SubscriptionStatus::PastDue->value)
                    ->orWhere(function ($retry): void {
                        $retry
                            ->whereNotNull('next_retry_at')
                            ->where('next_retry_at', '<=', now())
                            ->where('renewal_attempts', '<', 3)
                            ->where('grace_ends_at', '>', now());
                    });
            })
            ->whereHas('paymentMethod', fn ($query) => $query
                ->where('status', SubscriptionPaymentMethodStatus::Active->value)
                ->whereNull('revoked_at'))
            ->with(['account.locations', 'plan', 'priceVersion', 'paymentMethod'])
            ->lazyById()
            ->each(function (AccountSubscription $subscription) use ($setting, $createPayment, $chargeSubscription, $completePayment, &$charged): void {
                $attempt = $subscription->status === SubscriptionStatus::PastDue
                    ? ((int) $subscription->renewal_attempts + 1)
                    : 1;
                $payment = null;

                try {
                    $paymentType = $subscription->status === SubscriptionStatus::Trialing
                        ? AccountSubscriptionPaymentType::FullSubscription
                        : AccountSubscriptionPaymentType::AutoRenewal;
                    $payment = $createPayment->execute(
                        $subscription,
                        $paymentType,
                        renewalAttempt: $attempt,
                    );
                    $chargeSubscription->execute(
                        $payment,
                        $setting,
                        route('dashboard.accounts.tariff-payments.show', $subscription->account),
                    );
                    $charged++;
                } catch (Throwable $exception) {
                    report($exception);

                    if ($payment && ! $payment->status->isFinal()) {
                        $completePayment->execute($payment, new PaymentCallbackResult(
                            orderId: $payment->order_id,
                            status: PaymentCallbackStatus::Failed,
                            gatewayStatus: 'gateway_request_failed',
                            amountCents: $payment->amount_cents,
                            currency: $payment->currency,
                            failureReason: $exception->getMessage(),
                            payload: ['source' => 'billing_reconcile'],
                        ));
                    }
                }
            });

        return compact('charged', 'expired', 'cancelled');
    }

    private function sendBillingV2Reminders(
        SendBillingLifecycleNotification $sendNotification,
        SubscriptionPricingCalculator $pricing,
    ): void {
        foreach ([7, 3, 1] as $days) {
            AccountSubscription::query()
                ->where('billing_mode', SubscriptionBillingMode::LocationV2->value)
                ->where('status', SubscriptionStatus::Trialing->value)
                ->whereDate('trial_ends_at', now()->addDays($days)->toDateString())
                ->with('account')
                ->lazyById()
                ->each(function (AccountSubscription $subscription) use ($sendNotification, $days): void {
                    if (! $subscription->trial_ends_at) {
                        return;
                    }

                    $sendNotification->execute(
                        $subscription,
                        'trial_ending_'.$days,
                        $subscription->trial_ends_at,
                        ['date' => $subscription->trial_ends_at->timezone($subscription->account?->timezone ?? config('app.timezone'))->format('d.m.Y H:i')],
                    );
                });
        }

        AccountSubscription::query()
            ->where('billing_mode', SubscriptionBillingMode::LocationV2->value)
            ->where('status', SubscriptionStatus::Active->value)
            ->where('billing_interval_v2', SubscriptionBillingInterval::Annual->value)
            ->whereDate('ends_at', now()->addDays(7)->toDateString())
            ->with(['account.locations', 'priceVersion'])
            ->lazyById()
            ->each(function (AccountSubscription $subscription) use ($sendNotification, $pricing): void {
                if (! $subscription->ends_at || ! $subscription->priceVersion || ! $subscription->account) {
                    return;
                }

                $quote = $pricing->calculate(
                    $subscription->priceVersion,
                    max(1, $subscription->account->locations->where('is_active', true)->count()),
                    SubscriptionBillingInterval::Annual,
                );
                $sendNotification->execute(
                    $subscription,
                    'annual_renewal',
                    $subscription->ends_at,
                    [
                        'date' => $subscription->ends_at->timezone($subscription->account->timezone)->format('d.m.Y'),
                        'amount' => MoneyFormatter::format($quote->finalAmountCents, $quote->currency),
                        'locations' => $quote->quantity,
                    ],
                );
            });

        AccountSubscription::query()
            ->where('billing_mode', SubscriptionBillingMode::LocationV2->value)
            ->where('status', SubscriptionStatus::PastDue->value)
            ->whereNotNull('grace_ends_at')
            ->with('account')
            ->lazyById()
            ->each(function (AccountSubscription $subscription) use ($sendNotification): void {
                $sendNotification->execute(
                    $subscription,
                    'grace_expiry',
                    $subscription->grace_ends_at,
                    ['date' => $subscription->grace_ends_at?->timezone($subscription->account?->timezone ?? config('app.timezone'))->format('d.m.Y H:i')],
                );
            });

        SubscriptionPriceVersion::query()
            ->whereIn('status', [
                SubscriptionPriceStatus::Scheduled->value,
                SubscriptionPriceStatus::Published->value,
            ])
            ->whereNotNull('published_at')
            ->with(['plan.subscriptions.account'])
            ->lazyById()
            ->each(function (SubscriptionPriceVersion $priceVersion) use ($sendNotification): void {
                $priceVersion->plan?->subscriptions
                    ->filter(fn (AccountSubscription $subscription): bool => $subscription->usesLocationBilling()
                        && $subscription->subscription_price_version_id !== $priceVersion->id)
                    ->each(function (AccountSubscription $subscription) use ($sendNotification, $priceVersion): void {
                        $sendNotification->execute(
                            $subscription,
                            'price_change',
                            $priceVersion->published_at,
                            ['date' => $priceVersion->effective_at?->timezone($subscription->account?->timezone ?? config('app.timezone'))->format('d.m.Y')],
                        );
                    });
            });
    }
}
