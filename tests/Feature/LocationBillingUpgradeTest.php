<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\SubscriptionBillingInterval;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceVersion;
use App\Models\User;
use App\Support\SaasBilling\CreateBillingV2Payment;
use App\Support\SaasBilling\StartAccountTrial;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LocationBillingUpgradeTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_paid_location_stays_inactive_until_owner_approves_proration_and_payment_succeeds(): void
    {
        config()->set('ladna.saas_billing_v2_enabled', true);
        Mail::fake();
        $setting = $this->monopaySetting();
        [$account, $owner, $subscription] = $this->paidAccount();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.locations.store', $account), [
                'name' => 'Second studio',
                'slug' => 'second-studio',
                'timezone' => 'Europe/Kyiv',
                'is_active' => 1,
            ])
            ->assertRedirect(route('dashboard.accounts.locations.index', $account));

        $location = $account->locations()->where('slug', 'second-studio')->firstOrFail();
        $this->assertFalse($location->is_active);
        $this->assertTrue($location->billing_activation_pending);
        $this->assertSame(1, $subscription->refresh()->billable_location_count);

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://api.monobank.ua/api/merchant/wallet/payment') {
                return Http::response([
                    'invoiceId' => 'upgrade-invoice-1',
                    'paymentId' => 'upgrade-payment-1',
                    'status' => 'success',
                    'finalAmount' => $request['amount'],
                    'ccy' => 980,
                    'modifiedDate' => now()->toIso8601String(),
                ]);
            }

            return Http::response([], 404);
        });

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.tariff-payments.locations.approve', [$account, $location]))
            ->assertRedirect(route('dashboard.accounts.tariff-payments.show', $account));

        $location->refresh();
        $payment = $account->subscriptionPayments()->latest()->firstOrFail();
        $this->assertTrue($location->is_active);
        $this->assertFalse($location->billing_activation_pending);
        $this->assertSame(AccountSubscriptionPaymentStatus::PaymentPaid, $payment->status);
        $this->assertSame(2, $payment->billable_location_count);
        $this->assertSame(2, $subscription->refresh()->billable_location_count);
        $this->assertGreaterThan(0, $payment->amount_cents);
        $this->assertLessThan(80_000, $payment->amount_cents);
    }

    public function test_staff_cannot_authorize_charge_and_failed_upgrade_keeps_location_inactive(): void
    {
        config()->set('ladna.saas_billing_v2_enabled', true);
        Mail::fake();
        $this->monopaySetting();
        [$account, $owner] = $this->paidAccount();
        $manager = User::factory()->create();
        $account->users()->attach($manager, ['role' => AccountRole::Manager->value]);
        $location = Location::factory()->for($account)->create([
            'is_active' => false,
            'billing_activation_pending' => true,
        ]);

        $this->actingAs($manager)
            ->post(route('dashboard.accounts.tariff-payments.locations.approve', [$account, $location]))
            ->assertForbidden();

        Http::fake(fn (Request $request) => Http::response([
            'invoiceId' => 'upgrade-invoice-failed',
            'status' => 'failure',
            'finalAmount' => $request['amount'],
            'ccy' => 980,
            'failureReason' => 'declined',
        ]));

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.tariff-payments.locations.approve', [$account, $location]))
            ->assertRedirect(route('dashboard.accounts.tariff-payments.show', $account));

        $location->refresh();
        $payment = $account->subscriptionPayments()->latest()->firstOrFail();
        $this->assertFalse($location->is_active);
        $this->assertTrue($location->billing_activation_pending);
        $this->assertSame(AccountSubscriptionPaymentStatus::PaymentFailed, $payment->status);
    }

    public function test_deactivation_is_immediate_without_refund_and_reduces_only_the_next_renewal_quantity(): void
    {
        [$account, $owner, $subscription] = $this->paidAccount();
        $secondLocation = Location::factory()->for($account)->create(['is_active' => true]);
        $subscription->forceFill(['billable_location_count' => 2])->save();

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.locations.update', [$account, $secondLocation]), [
                'name' => $secondLocation->name,
                'slug' => $secondLocation->slug,
                'timezone' => $secondLocation->timezone,
                'is_active' => 0,
            ])
            ->assertRedirect(route('dashboard.accounts.locations.index', $account));

        $this->assertFalse($secondLocation->refresh()->is_active);
        $this->assertSame(2, $subscription->refresh()->billable_location_count);
        $this->assertDatabaseCount('account_subscription_payments', 0);

        $renewal = app(CreateBillingV2Payment::class)->execute(
            $subscription,
            AccountSubscriptionPaymentType::AutoRenewal,
            renewalAttempt: 1,
        );

        $this->assertSame(1, $renewal->billable_location_count);
        $this->assertSame(90_000, $renewal->amount_cents);
    }

    public function test_repeated_upgrade_approval_reuses_the_in_flight_payment_and_calls_mono_once(): void
    {
        config()->set('ladna.saas_billing_v2_enabled', true);
        Carbon::setTestNow('2026-07-21 11:00:00');
        Mail::fake();
        $this->monopaySetting();
        [$account, $owner] = $this->paidAccount();
        $location = Location::factory()->for($account)->create([
            'is_active' => false,
            'billing_activation_pending' => true,
        ]);
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => Http::response([
                'invoiceId' => 'upgrade-processing-invoice',
                'status' => 'processing',
                'pageUrl' => 'https://pay.example/upgrade-processing-invoice',
            ]),
        ]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.tariff-payments.locations.approve', [$account, $location]))
            ->assertRedirect('https://pay.example/upgrade-processing-invoice');

        Carbon::setTestNow(now()->addSeconds(2));
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.tariff-payments.locations.approve', [$account, $location]))
            ->assertRedirect('https://pay.example/upgrade-processing-invoice');

        $this->assertFalse($location->refresh()->is_active);
        $this->assertTrue($location->billing_activation_pending);
        $this->assertDatabaseCount('account_subscription_payments', 1);
        Http::assertSentCount(1);
    }

    /**
     * @return array{Account, User, AccountSubscription}
     */
    private function paidAccount(): array
    {
        $plan = SubscriptionPlan::factory()->create(['name' => 'Ladna']);
        $priceVersion = SubscriptionPriceVersion::factory()->for($plan, 'plan')->published()->create(['version' => 1]);
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        Location::factory()->for($account)->create(['is_active' => true]);
        $subscription = app(StartAccountTrial::class)->execute($account, $priceVersion);
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'billing_interval_v2' => SubscriptionBillingInterval::Monthly,
            'billable_location_count' => 1,
            'started_at' => now()->subDays(15),
            'ends_at' => now()->addDays(15),
            'next_payment_at' => now()->addDays(15),
            'auto_renew_enabled' => true,
        ])->save();
        $subscription->paymentMethod()->create([
            'account_id' => $account->id,
            'provider' => 'monopay',
            'provider_wallet_id' => 'wallet-upgrade',
            'provider_card_token' => 'token-upgrade',
            'masked_pan' => '444403******1902',
            'status' => SubscriptionPaymentMethodStatus::Active,
            'verification_reference' => 'verify-upgrade-'.str()->random(8),
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
