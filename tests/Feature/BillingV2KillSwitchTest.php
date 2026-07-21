<?php

namespace Tests\Feature;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\SubscriptionBillingInterval;
use App\Enums\SubscriptionBillingMode;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Enums\SubscriptionPriceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceVersion;
use App\Models\User;
use App\Support\SaasBilling\ApproveLocationUpgrade;
use App\Support\SaasBilling\ChargeAccountSubscription;
use App\Support\SaasBilling\CreateBillingV2Payment;
use App\Support\SaasBilling\StartAccountTrial;
use App\Support\SaasBilling\StartPaymentMethodVerification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

class BillingV2KillSwitchTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ladna.saas_billing_v2_enabled', false);
        Http::preventStrayRequests();
        Mail::fake();
    }

    public function test_disabled_flag_blocks_verification_and_direct_token_charging(): void
    {
        $setting = $this->monopaySetting();
        [, , $subscription] = $this->paidSubscription();

        try {
            app(StartPaymentMethodVerification::class)->execute(
                $subscription,
                SubscriptionBillingInterval::Monthly,
                $setting,
                'https://ladna.local/return',
            );
            $this->fail('Card verification must be blocked while billing v2 is disabled.');
        } catch (LogicException $exception) {
            $this->assertSame('Ladna billing v2 is disabled.', $exception->getMessage());
        }

        $this->assertDatabaseCount('account_subscription_payment_methods', 1);
        $this->assertDatabaseCount('account_subscription_payments', 0);

        $subscription->forceFill([
            'status' => SubscriptionStatus::Expired,
            'ends_at' => now()->subDay(),
        ])->save();
        $payment = app(CreateBillingV2Payment::class)->execute(
            $subscription->refresh(),
            AccountSubscriptionPaymentType::FullSubscription,
            renewalAttempt: 1,
        );

        try {
            app(ChargeAccountSubscription::class)->execute(
                $payment,
                $setting,
                'https://ladna.local/return',
                true,
            );
            $this->fail('Token charging must be blocked while billing v2 is disabled.');
        } catch (LogicException $exception) {
            $this->assertSame('Ladna billing v2 is disabled.', $exception->getMessage());
        }

        $this->assertSame(AccountSubscriptionPaymentStatus::PaymentStarted, $payment->refresh()->status);
        $this->assertNull($payment->gateway_invoice_id);
        Http::assertNothingSent();
    }

    public function test_disabled_reconcile_skips_v2_charges_reminders_and_scheduled_publication_but_keeps_legacy_reconciliation(): void
    {
        $this->monopaySetting();
        [, , $subscription] = $this->paidSubscription();
        $plan = $subscription->plan;
        $dueAt = now()->startOfSecond();
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'ends_at' => $dueAt,
            'next_payment_at' => $dueAt,
            'auto_renew_enabled' => true,
        ])->save();
        $scheduledPrice = SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->create(['version' => 2]);
        $scheduledPrice->tiers()->create([
            'starts_at_location' => 1,
            'ends_at_location' => null,
            'unit_price_cents' => 100_000,
        ]);
        $scheduledPrice->schedule(now()->subMinute());

        $legacyAccount = Account::factory()->create();
        $legacyPlan = SubscriptionPlan::factory()->create();
        $legacySubscription = AccountSubscription::factory()
            ->for($legacyAccount)
            ->for($legacyPlan, 'plan')
            ->create([
                'billing_mode' => SubscriptionBillingMode::Legacy,
                'status' => SubscriptionStatus::Active,
                'ends_at' => now()->subMinute(),
                'auto_renew_enabled' => false,
            ]);

        $this->artisan('billing:reconcile')
            ->assertSuccessful()
            ->expectsOutputToContain('Billing v2 charged: 0')
            ->expectsOutputToContain('Legacy expired subscriptions: 1');

        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertTrue($subscription->next_payment_at->equalTo($dueAt));
        $this->assertSame(SubscriptionPriceStatus::Scheduled, $scheduledPrice->refresh()->status);
        $this->assertSame(SubscriptionStatus::Expired, $legacySubscription->refresh()->status);
        $this->assertDatabaseCount('account_subscription_payments', 0);
        $this->assertDatabaseCount('account_subscription_notifications', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_location_upgrade_keeps_location_pending_without_creating_payment(): void
    {
        $this->monopaySetting();
        [$account] = $this->paidSubscription();
        $location = Location::factory()->for($account)->create([
            'is_active' => false,
            'billing_activation_pending' => true,
        ]);

        try {
            app(ApproveLocationUpgrade::class)->execute(
                $account,
                $location,
                IntegrationSetting::query()->firstOrFail(),
                'https://ladna.local/return',
            );
            $this->fail('Location upgrades must be blocked while billing v2 is disabled.');
        } catch (LogicException $exception) {
            $this->assertSame('Ladna billing v2 is disabled.', $exception->getMessage());
        }

        $this->assertFalse($location->refresh()->is_active);
        $this->assertTrue($location->billing_activation_pending);
        $this->assertDatabaseCount('account_subscription_payments', 0);
        Http::assertNothingSent();
    }

    /**
     * @return array{Account, User, AccountSubscription}
     */
    private function paidSubscription(): array
    {
        $plan = SubscriptionPlan::factory()->create(['name' => 'Ladna']);
        $priceVersion = SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->published()
            ->create(['version' => 1]);
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        Location::factory()->for($account)->create(['is_active' => true]);
        $subscription = app(StartAccountTrial::class)->execute($account, $priceVersion);
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'billing_interval_v2' => SubscriptionBillingInterval::Monthly,
            'billable_location_count' => 1,
            'started_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
            'next_payment_at' => now()->addMonth(),
            'auto_renew_enabled' => true,
        ])->save();
        $subscription->paymentMethod()->create([
            'account_id' => $account->id,
            'provider' => 'monopay',
            'provider_wallet_id' => 'kill-switch-wallet-'.str()->random(8),
            'provider_card_token' => 'kill-switch-token',
            'status' => SubscriptionPaymentMethodStatus::Active,
            'verification_reference' => 'kill-switch-verification-'.str()->random(8),
            'verified_at' => now(),
        ]);

        return [$account, $owner, $subscription];
    }

    private function monopaySetting(): IntegrationSetting
    {
        return IntegrationSetting::factory()->create([
            'is_enabled' => true,
            'credentials' => ['api_token' => 'test-token'],
        ]);
    }
}
