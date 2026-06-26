<?php

namespace App\Console\Commands;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\IntegrationProvider;
use App\Enums\SubscriptionStatus;
use App\Models\AccountSubscription;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\SaasBilling\CreateAccountSubscriptionPayment;
use App\Support\SaasBilling\MonopaySaasBilling;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:reconcile')]
#[Description('Reconcile SaaS subscriptions, expirations, and auto-renew provider status.')]
class ReconcileSaasBilling extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MonopaySaasBilling $billing, CreateAccountSubscriptionPayment $createPayment, TransactionalMailDispatcher $mailDispatcher): int
    {
        $expired = 0;
        AccountSubscription::query()
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
                ->each(function (AccountSubscription $subscription) use ($billing, $setting, $createPayment, $mailDispatcher, &$checked, &$pastDue): void {
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
                                $failedPayment = $createPayment->execute(
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

        $this->components->info("Expired subscriptions: {$expired}");
        $this->components->info("Checked auto-renew subscriptions: {$checked}");
        $this->components->info("Marked past due: {$pastDue}");

        return self::SUCCESS;
    }
}
