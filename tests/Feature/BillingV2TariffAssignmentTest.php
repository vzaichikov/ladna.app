<?php

namespace Tests\Feature;

use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\SubscriptionBillingInterval;
use App\Enums\SubscriptionBillingMode;
use App\Enums\SubscriptionPlanType;
use App\Enums\SubscriptionStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountSubscriptionPayment;
use App\Models\Location;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceVersion;
use App\Models\User;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\SaasBilling\AssignAccountPromoTariff;
use App\Support\SaasBilling\AssignAccountSubscriptionTariff;
use App\Support\SaasBilling\CompleteAccountSubscriptionPayment;
use App\Support\SaasBilling\CreateBillingV2Payment;
use App\Support\SaasBilling\StartAccountTrial;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BillingV2TariffAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ladna.saas_billing_v2_enabled', true);
        Carbon::setTestNow('2026-07-21 10:00:00');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_platform_admin_can_select_an_exact_private_tariff_during_enrollment(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);
        $ordinaryUser = User::factory()->create();
        [, $publicPrice] = $this->publishedTariff('Ladna Public', 90_000, true);
        [$privatePlan, $privatePrice] = $this->publishedTariff('Ladna Founders', 60_000, false);
        [$inactivePlan] = $this->publishedTariff('Inactive private tariff', 50_000, false);
        $inactivePlan->forceFill(['is_active' => false])->save();
        $account = Account::factory()->create();
        Location::factory()->for($account)->create(['is_active' => true]);

        $this->actingAs($admin)
            ->get(route('platform.accounts.show', $account))
            ->assertOk()
            ->assertSee('Ladna Public')
            ->assertSee('Ladna Founders')
            ->assertSee(__('app.public_tariff'))
            ->assertSee(__('app.private_tariff'))
            ->assertDontSee('Inactive private tariff')
            ->assertSee('value="'.$publicPrice->id.'"', false)
            ->assertSee('value="'.$privatePrice->id.'"', false);

        $this->actingAs($ordinaryUser)
            ->post(route('platform.accounts.billing.enroll', $account), [
                'subscription_price_version_id' => $privatePrice->id,
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('platform.accounts.billing.enroll', $account), [
                'subscription_price_version_id' => $inactivePlan->priceVersions()->firstOrFail()->id,
            ])
            ->assertSessionHasErrors('billing');

        $this->actingAs($admin)
            ->post(route('platform.accounts.billing.enroll', $account), [
                'subscription_price_version_id' => $privatePrice->id,
            ])
            ->assertRedirect(route('platform.accounts.show', $account));

        $subscription = $account->subscription()->firstOrFail();
        $this->assertSame($privatePlan->id, $subscription->subscription_plan_id);
        $this->assertSame($privatePrice->id, $subscription->subscription_price_version_id);
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertDatabaseCount('account_subscription_payments', 0);
    }

    public function test_trial_tariff_change_is_immediate_and_preserves_every_trial_and_payment_setting(): void
    {
        [, $publicPrice] = $this->publishedTariff('Ladna Public', 90_000, true);
        [$privatePlan, $privatePrice] = $this->publishedTariff('Ladna Founders', 60_000, false);
        $account = Account::factory()->create();
        Location::factory()->for($account)->create(['is_active' => true]);
        $subscription = app(StartAccountTrial::class)->execute($account, $publicPrice);
        $subscription->forceFill([
            'billing_interval_v2' => SubscriptionBillingInterval::Annual,
            'auto_renew_enabled' => true,
            'next_payment_at' => $subscription->trial_ends_at,
        ])->save();
        $trialStartedAt = $subscription->trial_started_at->copy();
        $trialEndsAt = $subscription->trial_ends_at->copy();
        $billingAnchorAt = $subscription->billing_anchor_at->copy();

        $changed = app(AssignAccountSubscriptionTariff::class)->execute($account, $privatePrice);

        $this->assertSame($privatePlan->id, $changed->subscription_plan_id);
        $this->assertSame($privatePrice->id, $changed->subscription_price_version_id);
        $this->assertNull($changed->pending_subscription_price_version_id);
        $this->assertNull($changed->pending_tariff_change_at);
        $this->assertTrue($changed->trial_started_at->equalTo($trialStartedAt));
        $this->assertTrue($changed->trial_ends_at->equalTo($trialEndsAt));
        $this->assertTrue($changed->billing_anchor_at->equalTo($billingAnchorAt));
        $this->assertSame(SubscriptionBillingInterval::Annual, $changed->billing_interval_v2);
        $this->assertTrue($changed->auto_renew_enabled);
        $this->assertTrue($changed->next_payment_at->equalTo($trialEndsAt));
        $this->assertDatabaseCount('account_subscription_payments', 0);
    }

    public function test_platform_admin_can_grant_an_active_private_promo_tariff_to_a_billing_v2_studio(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);
        $ordinaryUser = User::factory()->create();
        [, $publicPrice] = $this->publishedTariff('Ladna Studio', 90_000, true);
        $promoPlan = SubscriptionPlan::factory()->create([
            'name' => 'Ladna Promo',
            'plan_type' => SubscriptionPlanType::Promo,
            'public_signup_enabled' => false,
            'requires_recurring_payment' => false,
            'is_active' => true,
        ]);
        SubscriptionPlan::factory()->create([
            'name' => 'Public promo',
            'plan_type' => SubscriptionPlanType::Promo,
            'public_signup_enabled' => true,
            'requires_recurring_payment' => false,
            'is_active' => true,
        ]);
        SubscriptionPlan::factory()->create([
            'name' => 'Inactive promo',
            'plan_type' => SubscriptionPlanType::Promo,
            'public_signup_enabled' => false,
            'requires_recurring_payment' => false,
            'is_active' => false,
        ]);
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        Location::factory()->for($account)->create(['is_active' => true]);
        $subscription = app(StartAccountTrial::class)->execute($account, $publicPrice);
        $subscription->forceFill([
            'billing_interval_v2' => SubscriptionBillingInterval::Annual,
            'auto_renew_enabled' => true,
            'next_payment_at' => $subscription->trial_ends_at,
        ])->save();
        $trialStartedAt = $subscription->trial_started_at->copy();
        $trialEndsAt = $subscription->trial_ends_at->copy();

        $this->actingAs($admin)
            ->get(route('platform.accounts.show', $account))
            ->assertOk()
            ->assertSee('Ladna Promo')
            ->assertSee('value="'.$promoPlan->id.'"', false)
            ->assertDontSee('Public promo')
            ->assertDontSee('Inactive promo');

        $this->actingAs($ordinaryUser)
            ->patch(route('platform.accounts.billing.promo-tariff.update', $account), [
                'subscription_plan_id' => $promoPlan->id,
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->patch(route('platform.accounts.billing.promo-tariff.update', $account), [
                'subscription_plan_id' => $promoPlan->id,
            ])
            ->assertRedirect(route('platform.accounts.show', $account))
            ->assertSessionHas('status', __('app.promo_tariff_granted'));

        $granted = $subscription->refresh();
        $this->assertSame($promoPlan->id, $granted->subscription_plan_id);
        $this->assertSame(SubscriptionStatus::Active, $granted->status);
        $this->assertSame(SubscriptionBillingMode::Legacy, $granted->billing_mode);
        $this->assertNull($granted->subscription_price_version_id);
        $this->assertNull($granted->pending_subscription_price_version_id);
        $this->assertNull($granted->pending_tariff_change_at);
        $this->assertNull($granted->billing_interval_v2);
        $this->assertNull($granted->ends_at);
        $this->assertNull($granted->next_payment_at);
        $this->assertFalse($granted->auto_renew_enabled);
        $this->assertTrue($granted->trial_started_at->equalTo($trialStartedAt));
        $this->assertTrue($granted->trial_ends_at->equalTo($trialEndsAt));
        $this->assertDatabaseCount('account_subscription_payments', 0);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertOk()
            ->assertSee(__('app.subscription_plan_type_promo'))
            ->assertSee(__('app.subscription_promo_copy'));
    }

    public function test_promo_tariff_can_be_granted_to_a_studio_without_a_subscription(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);
        $account = Account::factory()->create();
        $promoPlan = SubscriptionPlan::factory()->create([
            'plan_type' => SubscriptionPlanType::Promo,
            'public_signup_enabled' => false,
            'requires_recurring_payment' => false,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->patch(route('platform.accounts.billing.promo-tariff.update', $account), [
                'subscription_plan_id' => $promoPlan->id,
            ])
            ->assertRedirect(route('platform.accounts.show', $account));

        $subscription = $account->subscription()->firstOrFail();
        $this->assertSame($promoPlan->id, $subscription->subscription_plan_id);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(SubscriptionBillingMode::Legacy, $subscription->billing_mode);
        $this->assertNull($subscription->ends_at);
        $this->assertFalse($subscription->auto_renew_enabled);
    }

    public function test_promo_tariff_grant_rejects_invalid_targets_and_in_flight_payments(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);
        [$standardPlan, $standardPrice] = $this->publishedTariff('Ladna Studio', 90_000, true);
        $promoPlan = SubscriptionPlan::factory()->create([
            'plan_type' => SubscriptionPlanType::Promo,
            'public_signup_enabled' => false,
            'requires_recurring_payment' => false,
            'is_active' => true,
        ]);
        $publicPromoPlan = SubscriptionPlan::factory()->create([
            'plan_type' => SubscriptionPlanType::Promo,
            'public_signup_enabled' => true,
            'requires_recurring_payment' => false,
            'is_active' => true,
        ]);
        $account = Account::factory()->create();
        Location::factory()->for($account)->create(['is_active' => true]);
        $subscription = app(StartAccountTrial::class)->execute($account, $standardPrice);

        $this->actingAs($admin)
            ->patch(route('platform.accounts.billing.promo-tariff.update', $account), [
                'subscription_plan_id' => $standardPlan->id,
            ])
            ->assertSessionHasErrors('subscription_plan_id');

        $this->actingAs($admin)
            ->patch(route('platform.accounts.billing.promo-tariff.update', $account), [
                'subscription_plan_id' => $publicPromoPlan->id,
            ])
            ->assertSessionHasErrors('subscription_plan_id');

        AccountSubscriptionPayment::factory()
            ->for($account)
            ->for($subscription, 'subscription')
            ->for($standardPlan, 'plan')
            ->create();

        $this->actingAs($admin)
            ->patch(route('platform.accounts.billing.promo-tariff.update', $account), [
                'subscription_plan_id' => $promoPlan->id,
            ])
            ->assertSessionHasErrors('promo_tariff');

        $unchanged = $subscription->refresh();
        $this->assertSame($standardPlan->id, $unchanged->subscription_plan_id);
        $this->assertSame(SubscriptionBillingMode::LocationV2, $unchanged->billing_mode);
    }

    public function test_protected_demo_cannot_receive_a_promo_tariff(): void
    {
        $promoPlan = SubscriptionPlan::factory()->create([
            'plan_type' => SubscriptionPlanType::Promo,
            'public_signup_enabled' => false,
            'requires_recurring_payment' => false,
            'is_active' => true,
        ]);
        $demoAccount = Account::factory()->demoReadonly()->create();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The protected demo account cannot change tariff.');

        app(AssignAccountPromoTariff::class)->execute($demoAccount, $promoPlan);
    }

    public function test_owner_trial_label_uses_the_assigned_price_version_duration_in_both_locales(): void
    {
        [, $priceVersion] = $this->publishedTariff(
            name: 'Ladna Billing Verification',
            firstLocationPriceCents: 100,
            public: false,
            trialDays: 1,
        );
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        Location::factory()->for($account)->create(['is_active' => true]);
        app(StartAccountTrial::class)->execute($account, $priceVersion);

        $this->actingAs($owner)
            ->withSession(['locale' => 'uk'])
            ->get(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertOk()
            ->assertSeeText('1 день безкоштовно')
            ->assertDontSeeText('30 днів безкоштовно');

        $this->actingAs($owner)
            ->withSession(['locale' => 'en'])
            ->get(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertOk()
            ->assertSeeText('1-day free trial')
            ->assertDontSeeText('30-day free trial');
    }

    public function test_paid_tariff_change_waits_for_notice_boundary_and_successful_matching_renewal(): void
    {
        [$publicPlan, $publicPrice] = $this->publishedTariff('Ladna Public', 90_000, true, 80_000);
        [$privatePlan, $privatePrice] = $this->publishedTariff('Ladna Founders', 60_000, false);
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        Location::factory()->count(2)->for($account)->create(['is_active' => true]);
        $subscription = app(StartAccountTrial::class)->execute($account, $publicPrice);
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'billing_interval_v2' => SubscriptionBillingInterval::Monthly,
            'billable_location_count' => 1,
            'started_at' => now()->subMonth(),
            'ends_at' => Carbon::parse('2026-08-05 10:00:00'),
            'next_payment_at' => Carbon::parse('2026-08-05 10:00:00'),
            'auto_renew_enabled' => true,
        ])->save();

        $scheduled = app(AssignAccountSubscriptionTariff::class)->execute($account, $privatePrice);

        $this->assertSame($publicPlan->id, $scheduled->subscription_plan_id);
        $this->assertSame($publicPrice->id, $scheduled->subscription_price_version_id);
        $this->assertSame($privatePrice->id, $scheduled->pending_subscription_price_version_id);
        $this->assertSame('2026-09-05 10:00:00', $scheduled->pending_tariff_change_at->format('Y-m-d H:i:s'));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.tariff-payments.show', $account))
            ->assertOk()
            ->assertSee(__('app.scheduled_tariff_change'))
            ->assertSee('Ladna Founders')
            ->assertSee('05.09.2026')
            ->assertSee('1 200 ₴');

        $locationUpgrade = app(CreateBillingV2Payment::class)->execute(
            $scheduled,
            AccountSubscriptionPaymentType::LocationUpgrade,
            targetLocationCount: 2,
            chargedAt: now(),
        );
        $this->assertSame($publicPrice->id, $locationUpgrade->subscription_price_version_id);

        $firstRenewal = app(CreateBillingV2Payment::class)->execute(
            $scheduled,
            AccountSubscriptionPaymentType::AutoRenewal,
            chargedAt: Carbon::parse('2026-08-05 10:00:00'),
            renewalAttempt: 1,
        );
        $this->assertSame($publicPrice->id, $firstRenewal->subscription_price_version_id);
        $this->assertSame(170_000, $firstRenewal->amount_cents);
        $this->complete($firstRenewal, PaymentCallbackStatus::Paid);

        $afterFirstRenewal = $subscription->refresh();
        $this->assertSame($publicPlan->id, $afterFirstRenewal->subscription_plan_id);
        $this->assertSame($privatePrice->id, $afterFirstRenewal->pending_subscription_price_version_id);
        $this->assertSame('2026-09-05 10:00:00', $afterFirstRenewal->ends_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-09-05 10:00:00');
        $privateRenewal = app(CreateBillingV2Payment::class)->execute(
            $afterFirstRenewal,
            AccountSubscriptionPaymentType::AutoRenewal,
            renewalAttempt: 1,
        );

        $this->assertSame($privatePlan->id, $privateRenewal->subscription_plan_id);
        $this->assertSame($privatePrice->id, $privateRenewal->subscription_price_version_id);
        $this->assertSame('Ladna Founders', $privateRenewal->plan_name_snapshot);
        $this->assertSame(120_000, $privateRenewal->amount_cents);
        $this->complete($privateRenewal, PaymentCallbackStatus::Paid);

        $activated = $subscription->refresh();
        $this->assertSame($privatePlan->id, $activated->subscription_plan_id);
        $this->assertSame($privatePrice->id, $activated->subscription_price_version_id);
        $this->assertNull($activated->pending_subscription_price_version_id);
        $this->assertNull($activated->pending_tariff_change_at);
    }

    public function test_failed_target_renewal_keeps_current_and_pending_tariffs_for_retry(): void
    {
        [$publicPlan, $publicPrice] = $this->publishedTariff('Ladna Public', 90_000, true);
        [, $privatePrice] = $this->publishedTariff('Ladna Founders', 60_000, false);
        $account = Account::factory()->create();
        Location::factory()->for($account)->create(['is_active' => true]);
        $subscription = app(StartAccountTrial::class)->execute($account, $publicPrice);
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'billing_interval_v2' => SubscriptionBillingInterval::Monthly,
            'billable_location_count' => 1,
            'started_at' => now()->subMonth(),
            'ends_at' => now()->addDays(31),
            'next_payment_at' => now()->addDays(31),
            'auto_renew_enabled' => true,
        ])->save();
        app(AssignAccountSubscriptionTariff::class)->execute($account, $privatePrice);
        Carbon::setTestNow($subscription->refresh()->ends_at);

        $payment = app(CreateBillingV2Payment::class)->execute(
            $subscription->refresh(),
            AccountSubscriptionPaymentType::AutoRenewal,
            renewalAttempt: 1,
        );
        $this->complete($payment, PaymentCallbackStatus::Failed);

        $failed = $subscription->refresh();
        $this->assertSame(SubscriptionStatus::PastDue, $failed->status);
        $this->assertSame($publicPlan->id, $failed->subscription_plan_id);
        $this->assertSame($publicPrice->id, $failed->subscription_price_version_id);
        $this->assertSame($privatePrice->id, $failed->pending_subscription_price_version_id);
        $this->assertNotNull($failed->pending_tariff_change_at);
    }

    /**
     * @return array{SubscriptionPlan, SubscriptionPriceVersion}
     */
    private function publishedTariff(
        string $name,
        int $firstLocationPriceCents,
        bool $public,
        ?int $laterLocationPriceCents = null,
        int $trialDays = 30,
    ): array {
        $plan = SubscriptionPlan::factory()->create([
            'name' => $name,
            'public_signup_enabled' => $public,
            'requires_recurring_payment' => true,
            'is_active' => true,
        ]);
        $priceVersion = SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->create([
                'version' => 1,
                'trial_days' => $trialDays,
            ]);
        $priceVersion->tiers()->createMany([
            [
                'starts_at_location' => 1,
                'ends_at_location' => 1,
                'unit_price_cents' => $firstLocationPriceCents,
            ],
            [
                'starts_at_location' => 2,
                'ends_at_location' => null,
                'unit_price_cents' => $laterLocationPriceCents ?? $firstLocationPriceCents,
            ],
        ]);
        $priceVersion->publish(now()->subDay());

        return [$plan, $priceVersion->refresh()];
    }

    private function complete(AccountSubscriptionPayment $payment, PaymentCallbackStatus $status): void
    {
        app(CompleteAccountSubscriptionPayment::class)->execute($payment, new PaymentCallbackResult(
            orderId: $payment->order_id,
            status: $status,
            gatewayStatus: $status->value,
            amountCents: $payment->amount_cents,
            currency: $payment->currency,
            failureReason: $status === PaymentCallbackStatus::Failed ? 'insufficient_funds' : null,
            paidAt: $status === PaymentCallbackStatus::Paid ? now() : null,
            payload: ['source' => 'tariff-assignment-test'],
        ));
    }
}
