<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountRole;
use App\Enums\AccountSignupStatus;
use App\Enums\AccountStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\IntegrationProvider;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSignupRequest;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateDemoSignup
{
    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: AccountSignupRequest, 1: AccountSubscriptionPayment, 2: Account, 3: User}
     */
    public function execute(array $validated, SubscriptionPlan $plan): array
    {
        return DB::transaction(function () use ($validated, $plan): array {
            $slug = $this->uniqueSlug((string) $validated['studio_name']);
            $orderId = $this->orderId();
            $hashedPassword = Hash::make((string) $validated['owner_password']);

            $account = Account::create([
                'name' => $validated['studio_name'],
                'slug' => $slug,
                'status' => AccountStatus::Active,
                'default_language' => 'uk',
                'country_code' => 'UA',
                'default_currency' => $plan->currency,
                'timezone' => 'Europe/Kyiv',
            ]);
            $account->ensureDefaultTrainerType();

            $owner = User::create([
                'name' => $validated['owner_name'],
                'email' => $validated['owner_email'],
                'phone' => $validated['owner_phone'] ?? null,
                'password' => $validated['owner_password'],
                'email_verified_at' => now(),
            ]);

            $account->users()->attach($owner->id, [
                'role' => AccountRole::Owner->value,
                'permissions' => null,
            ]);

            $subscription = $account->subscription()->create([
                'subscription_plan_id' => $plan->id,
                'status' => SubscriptionStatus::PendingPayment,
                'started_at' => now(),
                'ends_at' => null,
                'next_payment_at' => null,
                'payment_provider' => IntegrationProvider::Monopay->value,
                'auto_renew_enabled' => false,
            ]);

            $signup = AccountSignupRequest::create([
                'subscription_plan_id' => $plan->id,
                'account_id' => $account->id,
                'status' => AccountSignupStatus::PaymentStarted,
                'provider' => IntegrationProvider::Monopay->value,
                'order_id' => $orderId,
                'studio_name' => $validated['studio_name'],
                'account_slug' => $slug,
                'owner_name' => $validated['owner_name'],
                'owner_email' => $validated['owner_email'],
                'owner_phone' => $validated['owner_phone'] ?? null,
                'owner_password' => $hashedPassword,
                'default_language' => 'uk',
                'timezone' => 'Europe/Kyiv',
                'amount_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'expires_at' => now()->addHour(),
            ]);

            $payment = $this->createPayment($signup, $subscription, $plan, $orderId);

            return [$signup, $payment, $account, $owner];
        });
    }

    public function createPayment(
        AccountSignupRequest $signup,
        ?AccountSubscription $subscription = null,
        ?SubscriptionPlan $plan = null,
        ?string $orderId = null,
    ): AccountSubscriptionPayment {
        $signup->loadMissing(['account.subscription', 'plan']);

        $account = $signup->account;
        $subscription ??= $account?->subscription;
        $plan ??= $signup->plan;

        if (! $account || ! $subscription || ! $plan) {
            throw new \RuntimeException('Demo signup is missing account, subscription, or plan.');
        }

        $expiresAt = now()->addHour();

        $signup->forceFill([
            'status' => AccountSignupStatus::PaymentStarted,
            'failure_reason' => null,
            'expires_at' => $expiresAt,
        ])->save();

        return AccountSubscriptionPayment::create([
            'account_id' => $account->id,
            'account_subscription_id' => $subscription->id,
            'subscription_plan_id' => $plan->id,
            'account_signup_request_id' => $signup->id,
            'provider' => IntegrationProvider::Monopay->value,
            'payment_type' => AccountSubscriptionPaymentType::DemoInitial,
            'order_id' => $orderId ?? $this->orderId(),
            'amount_cents' => $plan->price_cents,
            'currency' => $plan->currency,
            'started_at' => now(),
            'expires_at' => $expiresAt,
        ]);
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source);
        $slugBase = $base !== '' ? $base : 'studio';
        $candidate = $slugBase;
        $suffix = 1;

        while (AccountSignupRequest::where('account_slug', $candidate)->exists() || Account::where('slug', $candidate)->exists()) {
            $candidate = $slugBase.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function orderId(): string
    {
        return 'DEMO-'.now()->format('YmdHis').'-'.Str::upper(Str::random(10));
    }
}
