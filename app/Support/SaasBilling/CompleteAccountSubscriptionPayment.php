<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountSignupStatus;
use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSignupRequest;
use App\Models\AccountSubscriptionPayment;
use App\Support\Fiscalization\FiscalReceiptService;
use App\Support\Mail\TransactionalMailDispatcher;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompleteAccountSubscriptionPayment
{
    public function __construct(
        private readonly TransactionalMailDispatcher $mailDispatcher,
        private readonly FiscalReceiptService $fiscalReceipts,
    ) {}

    public function execute(AccountSubscriptionPayment $payment, PaymentCallbackResult $callback): AccountSubscriptionPayment
    {
        $previousStatus = null;

        $completedPayment = DB::transaction(function () use ($payment, $callback, &$previousStatus): AccountSubscriptionPayment {
            $lockedPayment = AccountSubscriptionPayment::query()
                ->with(['account.subscription', 'subscription', 'plan', 'signupRequest.account', 'signupRequest.plan'])
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();
            $previousStatus = $lockedPayment->getRawOriginal('status');

            if ($lockedPayment->isPaid()) {
                return $lockedPayment;
            }

            $this->assertCallbackMatchesPayment($lockedPayment, $callback);

            if ($callback->status === PaymentCallbackStatus::Paid) {
                return $this->markPaid($lockedPayment, $callback);
            }

            $status = match ($callback->status) {
                PaymentCallbackStatus::Failed => AccountSubscriptionPaymentStatus::PaymentFailed,
                PaymentCallbackStatus::Cancelled => AccountSubscriptionPaymentStatus::PaymentCancelled,
                PaymentCallbackStatus::Expired => AccountSubscriptionPaymentStatus::PaymentExpired,
                default => AccountSubscriptionPaymentStatus::PaymentPending,
            };

            $lockedPayment->forceFill([
                'status' => $status,
                'gateway_invoice_id' => $callback->gatewayInvoiceId ?? $lockedPayment->gateway_invoice_id,
                'gateway_payment_id' => $callback->gatewayPaymentId ?? $lockedPayment->gateway_payment_id,
                'gateway_status' => $callback->gatewayStatus ?? $lockedPayment->gateway_status,
                'last_callback_payload' => $callback->payload,
                'failure_reason' => $callback->failureReason,
                'failed_at' => $status->isFinal() ? now() : $lockedPayment->failed_at,
            ])->save();

            if ($lockedPayment->signupRequest) {
                $lockedPayment->signupRequest->forceFill([
                    'status' => match ($status) {
                        AccountSubscriptionPaymentStatus::PaymentFailed => AccountSignupStatus::PaymentFailed,
                        AccountSubscriptionPaymentStatus::PaymentCancelled => AccountSignupStatus::PaymentCancelled,
                        AccountSubscriptionPaymentStatus::PaymentExpired => AccountSignupStatus::PaymentExpired,
                        default => $lockedPayment->signupRequest->status,
                    },
                    'gateway_invoice_id' => $callback->gatewayInvoiceId ?? $lockedPayment->signupRequest->gateway_invoice_id,
                    'gateway_status' => $callback->gatewayStatus ?? $lockedPayment->signupRequest->gateway_status,
                    'last_callback_payload' => $callback->payload,
                    'failure_reason' => $callback->failureReason,
                ])->save();
            }

            if (
                in_array($lockedPayment->payment_type, [
                    AccountSubscriptionPaymentType::FullSubscription,
                    AccountSubscriptionPaymentType::ManualRenewal,
                    AccountSubscriptionPaymentType::AutoRenewal,
                ], true)
                && $status->isFinal()
                && $lockedPayment->subscription
            ) {
                if ($lockedPayment->subscription->usesLocationBilling()) {
                    $this->markBillingV2Failure($lockedPayment, $callback);
                } else {
                    $lockedPayment->subscription->forceFill([
                        'status' => $lockedPayment->subscription->ends_at?->isFuture()
                            ? SubscriptionStatus::PastDue
                            : SubscriptionStatus::Expired,
                        'provider_status' => $callback->gatewayStatus ?? $lockedPayment->subscription->provider_status,
                        'auto_renew_enabled' => false,
                    ])->save();
                }
            }

            return $lockedPayment->refresh();
        });

        if ($completedPayment->status->isFinal() && $previousStatus !== $completedPayment->status->value) {
            $this->mailDispatcher->saasPaymentResolved($completedPayment);

            if ($completedPayment->status === AccountSubscriptionPaymentStatus::PaymentPaid) {
                try {
                    $this->fiscalReceipts->fiscalizeAccountSubscriptionPayment($completedPayment);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $completedPayment;
    }

    private function markPaid(AccountSubscriptionPayment $payment, PaymentCallbackResult $callback): AccountSubscriptionPayment
    {
        $paidAt = $callback->paidAt ?? now();

        if ($payment->subscription?->usesLocationBilling()) {
            $this->activateBillingV2($payment, $callback, $paidAt);
        } elseif ($payment->payment_type === AccountSubscriptionPaymentType::DemoInitial) {
            $account = $this->activateDemoSignup($payment->signupRequest, $paidAt);
            $subscription = $account->subscription()->firstOrFail();

            $payment->forceFill([
                'account_id' => $account->id,
                'account_subscription_id' => $subscription->id,
                'period_starts_at' => $subscription->started_at,
                'period_ends_at' => $subscription->ends_at,
            ]);
        } elseif ($payment->account && $payment->plan) {
            $periodStart = $payment->account->subscription?->ends_at?->isFuture()
                ? $payment->account->subscription->ends_at
                : $paidAt;
            $periodEnd = $periodStart->copy()->addDays($payment->plan->access_days ?? 30);
            $subscription = $payment->account->subscription()->updateOrCreate(
                ['account_id' => $payment->account->id],
                [
                    'subscription_plan_id' => $payment->plan->id,
                    'status' => SubscriptionStatus::Active,
                    'started_at' => $periodStart,
                    'ends_at' => $periodEnd,
                    'next_payment_at' => $periodEnd->copy()->subDays($payment->plan->renewal_lead_days ?? 2),
                    'payment_provider' => $payment->provider,
                    'provider_subscription_id' => $callback->payload['subscriptionId'] ?? $payment->gateway_subscription_id,
                    'provider_status' => $callback->gatewayStatus,
                    'auto_renew_enabled' => filled($callback->payload['subscriptionId'] ?? $payment->gateway_subscription_id),
                    'cancelled_at' => null,
                ],
            );

            $payment->forceFill([
                'account_subscription_id' => $subscription->id,
                'period_starts_at' => $periodStart,
                'period_ends_at' => $periodEnd,
                'gateway_subscription_id' => $callback->payload['subscriptionId'] ?? $payment->gateway_subscription_id,
            ]);
        }

        $payment->forceFill([
            'status' => AccountSubscriptionPaymentStatus::PaymentPaid,
            'gateway_invoice_id' => $callback->gatewayInvoiceId ?? $payment->gateway_invoice_id,
            'gateway_payment_id' => $callback->gatewayPaymentId ?? $payment->gateway_payment_id,
            'gateway_status' => $callback->gatewayStatus ?? $payment->gateway_status,
            'last_callback_payload' => $callback->payload,
            'paid_at' => $paidAt,
            'failure_reason' => null,
        ])->save();

        return $payment->refresh();
    }

    private function activateBillingV2(
        AccountSubscriptionPayment $payment,
        PaymentCallbackResult $callback,
        Carbon $paidAt,
    ): void {
        $subscription = $payment->subscription;

        if (! $subscription || ! $payment->period_ends_at) {
            throw new InvalidPaymentCallbackException('Billing-v2 subscription period is unavailable.');
        }

        if ($payment->payment_type === AccountSubscriptionPaymentType::LocationUpgrade) {
            $location = $payment->pendingLocation;

            if ($location && $location->account_id === $payment->account_id && $location->billing_activation_pending) {
                $location->forceFill([
                    'is_active' => true,
                    'billing_activation_pending' => false,
                ])->save();
            }

            $subscription->forceFill([
                'billable_location_count' => $payment->billable_location_count,
                'provider_status' => $callback->gatewayStatus,
            ])->save();
        } else {
            $appliesPendingTariff = $subscription->pending_subscription_price_version_id === $payment->subscription_price_version_id
                && $subscription->pending_tariff_change_at
                && $payment->period_starts_at?->greaterThanOrEqualTo($subscription->pending_tariff_change_at) === true;

            $subscription->forceFill([
                'subscription_plan_id' => $payment->subscription_plan_id,
                'subscription_price_version_id' => $payment->subscription_price_version_id,
                'pending_subscription_price_version_id' => $appliesPendingTariff
                    ? null
                    : $subscription->pending_subscription_price_version_id,
                'pending_tariff_change_at' => $appliesPendingTariff
                    ? null
                    : $subscription->pending_tariff_change_at,
                'status' => SubscriptionStatus::Active,
                'billing_interval_v2' => $payment->billing_interval_snapshot,
                'billable_location_count' => $payment->billable_location_count,
                'started_at' => $payment->period_starts_at ?? $paidAt,
                'ends_at' => $payment->period_ends_at,
                'next_payment_at' => $payment->period_ends_at,
                'billing_anchor_at' => $subscription->billing_anchor_at ?? $payment->period_starts_at ?? $paidAt,
                'payment_provider' => $payment->provider,
                'provider_status' => $callback->gatewayStatus,
                'auto_renew_enabled' => true,
                'grace_ends_at' => null,
                'cancel_at_period_end' => false,
                'cancellation_requested_at' => null,
                'renewal_attempts' => 0,
                'next_retry_at' => null,
                'cancelled_at' => null,
            ])->save();
        }

        $payment->forceFill([
            'period_starts_at' => $payment->period_starts_at ?? $paidAt,
            'period_ends_at' => $payment->period_ends_at,
        ]);
    }

    private function markBillingV2Failure(
        AccountSubscriptionPayment $payment,
        PaymentCallbackResult $callback,
    ): void {
        $subscription = $payment->subscription;

        if (! $subscription || $payment->payment_type === AccountSubscriptionPaymentType::LocationUpgrade) {
            return;
        }

        if ($payment->payment_type !== AccountSubscriptionPaymentType::AutoRenewal) {
            $trialIsCurrent = $subscription->trial_ends_at?->isFuture() === true;
            $subscription->forceFill([
                'status' => $trialIsCurrent ? SubscriptionStatus::Trialing : SubscriptionStatus::Expired,
                'provider_status' => $callback->gatewayStatus ?? $subscription->provider_status,
                'auto_renew_enabled' => false,
                'grace_ends_at' => null,
                'renewal_attempts' => 0,
                'next_payment_at' => null,
                'next_retry_at' => null,
            ])->save();

            return;
        }

        $attempt = max((int) $subscription->renewal_attempts, (int) $payment->renewal_attempt);
        $requiresOwnerInteraction = $this->requiresOwnerInteraction($callback);
        $nextRetryAt = match (true) {
            $requiresOwnerInteraction, $attempt >= 3 => null,
            $attempt <= 1 => now()->addDays(2),
            default => now()->addDays(3),
        };

        $subscription->forceFill([
            'status' => SubscriptionStatus::PastDue,
            'provider_status' => $requiresOwnerInteraction
                ? 'owner_interaction_required'
                : ($callback->gatewayStatus ?? $subscription->provider_status),
            'auto_renew_enabled' => true,
            'grace_ends_at' => $subscription->grace_ends_at ?? now()->addDays(7),
            'renewal_attempts' => $attempt,
            'next_retry_at' => $nextRetryAt,
        ])->save();
    }

    private function requiresOwnerInteraction(PaymentCallbackResult $callback): bool
    {
        $reason = strtolower(implode(' ', array_filter([
            $callback->failureReason,
            is_string($callback->payload['errCode'] ?? null) ? $callback->payload['errCode'] : null,
        ])));

        return str_contains($reason, '3ds') || str_contains($reason, '3-d secure');
    }

    private function activateDemoSignup(?AccountSignupRequest $signup, Carbon $paidAt): Account
    {
        if (! $signup || ! $signup->plan) {
            throw new InvalidPaymentCallbackException('Signup request is unavailable.');
        }

        if (! $signup->account) {
            throw new InvalidPaymentCallbackException('Signup account is unavailable.');
        }

        $account = $signup->account;
        $endsAt = $paidAt->copy()->addDays($signup->plan->access_days ?? 30);

        $account->subscription()->updateOrCreate(
            ['account_id' => $account->id],
            [
                'subscription_plan_id' => $signup->plan->id,
                'status' => SubscriptionStatus::Trialing,
                'started_at' => $paidAt,
                'ends_at' => $endsAt,
                'next_payment_at' => $endsAt->copy()->subDays($signup->plan->renewal_lead_days ?? 2),
                'payment_provider' => $signup->provider,
                'provider_status' => null,
                'auto_renew_enabled' => false,
                'cancelled_at' => null,
            ],
        );

        $signup->forceFill([
            'status' => AccountSignupStatus::AccountCreated,
            'paid_at' => $paidAt,
            'failure_reason' => null,
        ])->save();

        return $account->refresh();
    }

    private function assertCallbackMatchesPayment(AccountSubscriptionPayment $payment, PaymentCallbackResult $callback): void
    {
        $knownProviderReferences = array_filter([
            $payment->order_id,
            $payment->gateway_invoice_id,
            $payment->gateway_payment_id,
            $payment->gateway_subscription_id,
        ]);

        if ($callback->orderId !== '' && ! in_array($callback->orderId, $knownProviderReferences, true)) {
            throw new InvalidPaymentCallbackException('Callback order does not match SaaS payment.');
        }

        if ($callback->amountCents !== null && $callback->amountCents !== $payment->amount_cents) {
            throw new InvalidPaymentCallbackException('Callback amount does not match SaaS payment.');
        }

        if ($callback->currency !== null && strtoupper($callback->currency) !== strtoupper($payment->currency)) {
            throw new InvalidPaymentCallbackException('Callback currency does not match SaaS payment.');
        }
    }
}
