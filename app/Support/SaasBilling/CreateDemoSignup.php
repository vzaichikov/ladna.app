<?php

namespace App\Support\SaasBilling;

use App\Enums\AccountSignupStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\IntegrationProvider;
use App\Models\Account;
use App\Models\AccountSignupRequest;
use App\Models\AccountSubscriptionPayment;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateDemoSignup
{
    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: AccountSignupRequest, 1: AccountSubscriptionPayment}
     */
    public function execute(array $validated, SubscriptionPlan $plan): array
    {
        $slug = $this->uniqueSlug((string) ($validated['account_slug'] ?: $validated['studio_name']));
        $orderId = $this->orderId();

        $signup = AccountSignupRequest::create([
            'subscription_plan_id' => $plan->id,
            'status' => AccountSignupStatus::PaymentStarted,
            'provider' => IntegrationProvider::Monopay->value,
            'order_id' => $orderId,
            'studio_name' => $validated['studio_name'],
            'account_slug' => $slug,
            'owner_name' => $validated['owner_name'],
            'owner_email' => $validated['owner_email'],
            'owner_phone' => $validated['owner_phone'] ?? null,
            'owner_password' => Hash::make($validated['owner_password']),
            'default_language' => 'uk',
            'timezone' => 'Europe/Kyiv',
            'amount_cents' => $plan->price_cents,
            'currency' => $plan->currency,
            'expires_at' => now()->addHour(),
        ]);

        $payment = AccountSubscriptionPayment::create([
            'subscription_plan_id' => $plan->id,
            'account_signup_request_id' => $signup->id,
            'provider' => IntegrationProvider::Monopay->value,
            'payment_type' => AccountSubscriptionPaymentType::DemoInitial,
            'order_id' => $orderId,
            'amount_cents' => $plan->price_cents,
            'currency' => $plan->currency,
            'started_at' => now(),
            'expires_at' => $signup->expires_at,
        ]);

        return [$signup, $payment];
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source);
        $slugBase = $base !== '' ? $base : 'studio';
        $candidate = $slugBase;
        $suffix = 2;

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
