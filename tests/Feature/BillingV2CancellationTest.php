<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\SubscriptionBillingInterval;
use App\Enums\SubscriptionPaymentMethodStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceVersion;
use App\Models\User;
use App\Support\SaasBilling\StartAccountTrial;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BillingV2CancellationTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_owner_can_cancel_resume_and_finalize_without_deleting_studio_data(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-07-20 10:00:00');
        IntegrationSetting::factory()->create([
            'is_enabled' => true,
            'credentials' => ['api_token' => 'test-token'],
        ]);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Ladna']);
        $priceVersion = SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->published()
            ->create(['version' => 1]);
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $account->addOwner($owner);
        $account->users()->attach($manager, ['role' => AccountRole::Manager->value]);
        Location::factory()->count(2)->for($account)->create(['is_active' => true]);
        $subscription = app(StartAccountTrial::class)->execute($account, $priceVersion);
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'billing_interval_v2' => SubscriptionBillingInterval::Monthly,
            'billable_location_count' => 2,
            'started_at' => now()->subMonth(),
            'ends_at' => now()->addDays(10),
            'next_payment_at' => now()->addDays(10),
            'auto_renew_enabled' => true,
            'pending_subscription_price_version_id' => $priceVersion->id,
            'pending_tariff_change_at' => now()->addDays(10),
        ])->save();
        $paymentMethod = $subscription->paymentMethod()->create([
            'account_id' => $account->id,
            'provider' => 'monopay',
            'provider_wallet_id' => 'cancel-wallet',
            'provider_card_token' => 'cancel-token',
            'masked_pan' => '444403******1902',
            'status' => SubscriptionPaymentMethodStatus::Active,
            'verification_reference' => 'cancel-verification',
            'verified_at' => now(),
        ]);

        $this->actingAs($manager)
            ->delete(route('dashboard.accounts.tariff-payments.cancel', $account))
            ->assertForbidden();

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.tariff-payments.cancel', $account))
            ->assertRedirect(route('dashboard.accounts.tariff-payments.show', $account));
        $this->assertTrue($subscription->refresh()->cancel_at_period_end);
        $this->assertFalse($subscription->auto_renew_enabled);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.tariff-payments.resume', $account))
            ->assertRedirect(route('dashboard.accounts.tariff-payments.show', $account));
        $this->assertFalse($subscription->refresh()->cancel_at_period_end);
        $this->assertTrue($subscription->auto_renew_enabled);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.tariff-payments.cancel', $account))
            ->assertRedirect(route('dashboard.accounts.tariff-payments.show', $account));
        $paymentCount = $account->subscriptionPayments()->count();
        Http::fake(['https://api.monobank.ua/api/merchant/wallet/card*' => Http::response([], 204)]);
        Carbon::setTestNow($subscription->ends_at->copy()->addSecond());

        $this->artisan('billing:reconcile')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->refresh()->status);
        $this->assertNull($subscription->pending_subscription_price_version_id);
        $this->assertNull($subscription->pending_tariff_change_at);
        $this->assertSame(SubscriptionPaymentMethodStatus::Revoked, $paymentMethod->refresh()->status);
        $this->assertNull($paymentMethod->provider_card_token);
        $this->assertSame('', $paymentMethod->provider_wallet_id);
        $this->assertSame(2, $account->locations()->count());
        $this->assertSame($paymentCount, $account->subscriptionPayments()->count());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_starts_with($request->url(), 'https://api.monobank.ua/api/merchant/wallet/card'));

    }
}
