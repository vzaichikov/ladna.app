<?php

namespace Tests\Feature;

use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\SubscriptionBillingInterval;
use App\Enums\SubscriptionBillingMode;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceVersion;
use App\Support\SaasBilling\AccountSubscriptionAccess;
use App\Support\SaasBilling\BillingPeriodCalculator;
use App\Support\SaasBilling\CreateBillingV2Payment;
use App\Support\SaasBilling\StartAccountTrial;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

class BillingV2LifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_explicit_trial_starts_for_exactly_thirty_days_without_payment_or_card(): void
    {
        config()->set('ladna.saas_billing_v2_enabled', true);
        Carbon::setTestNow('2026-07-20 10:15:00');
        [$plan, $priceVersion] = $this->publishedPricing();
        $account = Account::factory()->create();
        Location::factory()->for($account)->create(['is_active' => true]);

        $subscription = app(StartAccountTrial::class)->execute($account, $priceVersion);

        $this->assertSame(SubscriptionBillingMode::LocationV2, $subscription->billing_mode);
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertTrue($subscription->trial_started_at->equalTo(now()));
        $this->assertTrue($subscription->trial_ends_at->equalTo(now()->addDays(30)));
        $this->assertTrue($subscription->ends_at->equalTo(now()->addDays(30)));
        $this->assertFalse($subscription->auto_renew_enabled);
        $this->assertNull($subscription->next_payment_at);
        $this->assertSame($plan->id, $subscription->subscription_plan_id);
        $this->assertDatabaseCount('account_subscription_payments', 0);
        $this->assertDatabaseCount('account_subscription_payment_methods', 0);
    }

    public function test_trial_is_unique_and_protected_demo_cannot_be_enrolled(): void
    {
        [, $priceVersion] = $this->publishedPricing();
        $account = Account::factory()->create();
        app(StartAccountTrial::class)->execute($account, $priceVersion);

        try {
            app(StartAccountTrial::class)->execute($account->refresh(), $priceVersion);
            $this->fail('A second trial should be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('already used', $exception->getMessage());
        }

        $demo = Account::factory()->demoReadonly()->create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('protected demo');
        app(StartAccountTrial::class)->execute($demo, $priceVersion);
    }

    public function test_calendar_month_and_year_periods_do_not_use_fixed_day_counts(): void
    {
        $periods = app(BillingPeriodCalculator::class);

        $this->assertSame(
            '2027-02-28 12:00:00',
            $periods->periodEnd(Carbon::parse('2027-01-31 12:00:00'), SubscriptionBillingInterval::Monthly)->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2025-02-28 12:00:00',
            $periods->periodEnd(Carbon::parse('2024-02-29 12:00:00'), SubscriptionBillingInterval::Annual)->format('Y-m-d H:i:s'),
        );
    }

    public function test_payment_snapshots_are_complete_for_one_two_and_three_locations(): void
    {
        [, $priceVersion] = $this->publishedPricing();

        foreach ([1 => 90_000, 2 => 170_000, 3 => 250_000] as $quantity => $amount) {
            $account = Account::factory()->create();
            Location::factory()->count($quantity)->for($account)->create(['is_active' => true]);
            $subscription = app(StartAccountTrial::class)->execute($account, $priceVersion);
            $subscription->forceFill([
                'status' => SubscriptionStatus::Expired,
                'billing_interval_v2' => SubscriptionBillingInterval::Monthly,
                'ends_at' => now()->subDay(),
            ])->save();

            $payment = app(CreateBillingV2Payment::class)->execute(
                $subscription->refresh(),
                AccountSubscriptionPaymentType::FullSubscription,
                renewalAttempt: 1,
            );

            $this->assertSame($quantity, $payment->billable_location_count);
            $this->assertSame($amount, $payment->amount_cents);
            $this->assertSame($amount, $payment->subtotal_cents);
            $this->assertSame(0, $payment->discount_cents);
            $this->assertSame($priceVersion->id, $payment->subscription_price_version_id);
            $this->assertSame('monthly', $payment->billing_interval_snapshot);
            $this->assertNotEmpty($payment->tier_breakdown_snapshot['tiers']);
            $this->assertNotNull($payment->plan_name_snapshot);
            $this->assertTrue($payment->period_ends_at->equalTo($payment->period_starts_at->copy()->addMonthNoOverflow()));
        }
    }

    public function test_existing_subscription_keeps_current_price_until_thirty_days_after_publication(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $plan = SubscriptionPlan::factory()->create(['name' => 'Ladna']);
        $currentPrice = SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->published(now()->subDays(60))
            ->create(['version' => 1]);
        $account = Account::factory()->create();
        Location::factory()->for($account)->create(['is_active' => true]);
        $subscription = app(StartAccountTrial::class)->execute($account, $currentPrice);
        $replacement = SubscriptionPriceVersion::factory()->for($plan, 'plan')->create(['version' => 2]);
        $replacement->tiers()->create([
            'starts_at_location' => 1,
            'ends_at_location' => null,
            'unit_price_cents' => 100_000,
        ]);
        $replacement->publish(now());
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'billing_interval_v2' => SubscriptionBillingInterval::Monthly,
            'ends_at' => now()->addDays(60),
        ])->save();

        $beforeNotice = app(CreateBillingV2Payment::class)->execute(
            $subscription->refresh(),
            AccountSubscriptionPaymentType::AutoRenewal,
            chargedAt: now(),
            renewalAttempt: 1,
        );

        $this->assertSame($currentPrice->id, $beforeNotice->subscription_price_version_id);
        $this->assertSame(90_000, $beforeNotice->amount_cents);

        Carbon::setTestNow(now()->addDays(31));
        $midPeriodUpgrade = app(CreateBillingV2Payment::class)->execute(
            $subscription->refresh(),
            AccountSubscriptionPaymentType::LocationUpgrade,
            targetLocationCount: 2,
            chargedAt: now(),
        );
        $afterNotice = app(CreateBillingV2Payment::class)->execute(
            $subscription->refresh(),
            AccountSubscriptionPaymentType::AutoRenewal,
            chargedAt: now(),
            renewalAttempt: 2,
        );

        $this->assertSame($currentPrice->id, $midPeriodUpgrade->subscription_price_version_id);
        $this->assertSame($replacement->id, $afterNotice->subscription_price_version_id);
        $this->assertSame(100_000, $afterNotice->amount_cents);
    }

    public function test_failed_renewals_retry_on_days_two_and_five_then_expire_after_seven_day_grace(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-08-01 09:00:00');
        $setting = IntegrationSetting::factory()->create([
            'is_enabled' => true,
            'credentials' => ['api_token' => 'test-token'],
        ]);
        [, $priceVersion] = $this->publishedPricing();
        $account = Account::factory()->create();
        Location::factory()->for($account)->create(['is_active' => true]);
        $subscription = app(StartAccountTrial::class)->execute($account, $priceVersion);
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'billing_interval_v2' => SubscriptionBillingInterval::Monthly,
            'billable_location_count' => 1,
            'started_at' => now()->subMonth(),
            'ends_at' => now(),
            'next_payment_at' => now(),
            'auto_renew_enabled' => true,
        ])->save();
        $subscription->paymentMethod()->create([
            'account_id' => $account->id,
            'provider' => 'monopay',
            'provider_wallet_id' => 'retry-wallet',
            'provider_card_token' => 'retry-token',
            'status' => SubscriptionPaymentMethodStatus::Active,
            'verification_reference' => 'retry-verification',
            'verified_at' => now(),
        ]);
        Http::fake(fn (Request $request) => Http::response([
            'invoiceId' => 'retry-'.$request['merchantPaymInfo']['reference'],
            'status' => 'failure',
            'finalAmount' => $request['amount'],
            'ccy' => 980,
            'failureReason' => 'insufficient_funds',
        ]));

        $this->artisan('billing:reconcile')->assertSuccessful();
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame(1, $subscription->renewal_attempts);
        $this->assertTrue($subscription->next_retry_at->equalTo(now()->addDays(2)));
        $this->assertTrue($subscription->grace_ends_at->equalTo(now()->addDays(7)));
        $this->assertTrue(app(AccountSubscriptionAccess::class)->canEditStudio($account->refresh()));

        Carbon::setTestNow(now()->addDays(2));
        $this->artisan('billing:reconcile')->assertSuccessful();
        $subscription->refresh();
        $this->assertSame(2, $subscription->renewal_attempts);
        $this->assertTrue($subscription->next_retry_at->equalTo(now()->addDays(3)));

        Carbon::setTestNow(now()->addDays(3));
        $this->artisan('billing:reconcile')->assertSuccessful();
        $subscription->refresh();
        $this->assertSame(3, $subscription->renewal_attempts);
        $this->assertNull($subscription->next_retry_at);

        Carbon::setTestNow($subscription->grace_ends_at->copy()->addSecond());
        $this->artisan('billing:reconcile')->assertSuccessful();
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Expired, $subscription->status);
        $this->assertFalse(app(AccountSubscriptionAccess::class)->canEditStudio($account->refresh()));
        $this->assertSame(3, $account->subscriptionPayments()->count());
        $this->assertTrue($setting->exists);
    }

    private function publishedPricing(): array
    {
        $plan = SubscriptionPlan::factory()->create([
            'name' => 'Ladna',
            'slug' => 'ladna-v2-'.str()->random(8),
            'currency' => 'UAH',
            'is_active' => true,
        ]);
        $priceVersion = SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->published(now()->subMinute())
            ->create(['version' => 1]);

        return [$plan, $priceVersion];
    }
}
