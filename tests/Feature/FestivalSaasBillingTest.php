<?php

namespace Tests\Feature;

use App\Actions\Festivals\CompleteFestivalEditionPurchase;
use App\Actions\Festivals\InitializeFestivalEntryWorkflow;
use App\Actions\Festivals\StartFestivalEditionPurchasePayment;
use App\Actions\Festivals\SubmitFestivalEntryStep;
use App\Enums\AccountRole;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPaymentMethod;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\FestivalTariffPackage;
use App\Models\IntegrationSetting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Festivals\FestivalSaasAccess;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FestivalSaasBillingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_new_tariffs_receive_the_default_festival_matrix(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);

        $this->actingAs($admin)->post(route('platform.subscription-plans.store'), [
            'name' => 'Festival-ready tariff',
            'slug' => 'festival-ready-tariff',
            'price_uah' => '900.00',
            'currency' => 'UAH',
            'billing_interval' => 'monthly',
            'plan_type' => 'standard',
            'access_days' => 30,
            'renewal_lead_days' => 2,
            'sort_order' => 90,
            'is_active' => 1,
        ])->assertRedirect(route('platform.subscription-plans.index'));

        $plan = SubscriptionPlan::query()->where('slug', 'festival-ready-tariff')->firstOrFail();
        $this->assertSame(
            [
                ['S', 150000, 100, 300],
                ['M', 300000, 250, 700],
                ['L', 500000, 500, 1500],
            ],
            $plan->festivalTariffPackages()->get()->map(fn (FestivalTariffPackage $package): array => [
                $package->name,
                $package->price_cents,
                $package->max_participants,
                $package->max_tickets,
            ])->all(),
        );
    }

    public function test_platform_controls_the_festival_flag_outside_customer_auth_settings(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);
        $account = Account::factory()->create(['enable_festivals' => false]);

        $this->actingAs($admin)->patch(route('platform.accounts.festival-capability.update', $account), [
            'enable_festivals' => 1,
        ])->assertRedirect(route('platform.accounts.show', $account));
        $this->assertTrue($account->refresh()->enable_festivals);

        $this->actingAs($admin)->put(route('platform.accounts.studio-possibilities.update', $account), [
            'allow_otp' => 1,
            'allow_rtsp_cameras' => 0,
            'enable_people_counter' => 0,
            'enable_customer_notifications' => 0,
            'sms_sending_mode' => 'disabled',
        ])->assertRedirect();
        $this->assertTrue($account->refresh()->enable_festivals);
    }

    public function test_platform_can_update_a_used_package_without_changing_the_paid_amount(): void
    {
        $admin = User::factory()->create(['system_role' => SystemRole::PlatformAdmin]);
        [$account, $owner, $package] = $this->festivalAccount();
        $plan = $package->plan;
        $package->forceFill(['name' => 'Original S'])->save();
        $purchase = FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $plan->id,
            'festival_tariff_package_id' => $package->id,
            'created_by_user_id' => $owner->id,
            'amount_cents' => 150000,
        ]);

        $this->actingAs($admin)->put(route('platform.subscription-plans.update', $plan), [
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => $plan->description,
            'price_uah' => number_format($plan->price_cents / 100, 2, '.', ''),
            'currency' => 'UAH',
            'billing_interval' => 'monthly',
            'plan_type' => 'standard',
            'access_days' => 30,
            'public_signup_enabled' => 0,
            'requires_recurring_payment' => 1,
            'renewal_lead_days' => 2,
            'is_active' => 1,
            'sort_order' => $plan->sort_order,
            'festival_packages' => [[
                'id' => $package->id,
                'name' => 'Free S',
                'price_uah' => '0.00',
                'max_participants' => 120,
                'max_tickets' => 350,
                'is_active' => 0,
            ]],
        ])->assertRedirect(route('platform.subscription-plans.index'));

        $package->refresh();
        $this->assertSame(0, $package->price_cents);
        $this->assertFalse($package->is_active);
        $this->assertSame(120, $package->max_participants);
        $purchase->refresh()->load('package');
        $this->assertSame('Free S', $purchase->package->name);
        $this->assertSame(120, $purchase->package->max_participants);
        $this->assertSame(150000, $purchase->amount_cents);
    }

    public function test_only_owner_can_buy_and_zero_price_grants_current_package_without_payment(): void
    {
        [$account, $owner, $package] = $this->festivalAccount(['price_cents' => 0]);
        $manager = User::factory()->create();
        $account->users()->attach($manager->id, ['role' => AccountRole::Manager->value]);

        $payload = [
            'festival_tariff_package_id' => $package->id,
            'idempotency_key' => (string) Str::uuid(),
        ];
        $this->actingAs($manager)->post(route('dashboard.accounts.festivals.purchases.store', $account), $payload)->assertForbidden();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.purchases.store', $account), $payload)->assertRedirect();
        $purchase = FestivalEditionPurchase::query()->whereBelongsTo($account)->firstOrFail();
        $this->assertSame(FestivalEditionPurchaseStatus::Available, $purchase->status);
        $this->assertNull($purchase->provider);
        $this->assertNull($purchase->order_id);
        $this->assertNull($purchase->paid_at);
        $this->assertSame(0, $purchase->fiscalReceipts()->count());

        $package->update(['name' => 'Changed', 'max_participants' => 999, 'max_tickets' => 999]);
        $purchase->refresh()->load('package');
        $this->assertSame('Changed', $purchase->package->name);
        $this->assertSame(999, $purchase->package->max_participants);
        $this->assertSame(999, $purchase->package->max_tickets);
    }

    public function test_paid_purchase_checkout_returns_to_the_payments_tab(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'festival-return-invoice',
                'pageUrl' => 'https://pay.example/festival-return-invoice',
            ]),
        ]);
        [$account, $owner, $package] = $this->festivalAccount();
        $this->platformMonopaySetting();
        $paymentsUrl = route('dashboard.accounts.festivals.index', [
            'account' => $account,
            'tab' => 'payments',
        ]);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.purchases.store', $account), [
            'festival_tariff_package_id' => $package->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect('https://pay.example/festival-return-invoice');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/invoice/create'
            && $request['redirectUrl'] === $paymentsUrl);
    }

    public function test_pending_tokenized_purchase_falls_back_to_the_payments_tab(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => Http::response([
                'invoiceId' => 'festival-pending-return-invoice',
                'status' => 'processing',
            ]),
        ]);
        [$account, $owner, $package] = $this->festivalAccount();
        $this->platformMonopaySetting();
        $subscription = $account->subscription()->firstOrFail();
        AccountSubscriptionPaymentMethod::factory()->create([
            'account_id' => $account->id,
            'account_subscription_id' => $subscription->id,
            'provider_card_token' => 'festival-pending-card-token',
            'status' => 'active',
            'verified_at' => now(),
        ]);
        $paymentsUrl = route('dashboard.accounts.festivals.index', [
            'account' => $account,
            'tab' => 'payments',
        ]);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.purchases.store', $account), [
            'festival_tariff_package_id' => $package->id,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect($paymentsUrl)
            ->assertSessionHas('status', __('app.festival_payment_pending'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/wallet/payment'
            && $request['redirectUrl'] === $paymentsUrl);
    }

    public function test_purchase_availability_requires_current_non_demo_subscription_and_accepts_grace(): void
    {
        $access = app(FestivalSaasAccess::class);
        [$trialAccount] = $this->festivalAccount();
        $this->assertTrue($access->canPurchase($trialAccount));

        $missingSubscription = Account::factory()->create(['enable_festivals' => true]);
        $this->assertFalse($access->canPurchase($missingSubscription));

        [$expiredAccount] = $this->festivalAccount();
        $expiredAccount->subscription()->update([
            'status' => SubscriptionStatus::Expired,
            'ends_at' => now()->subDay(),
        ]);
        $expiredAccount->unsetRelation('subscription');
        $this->assertFalse($access->canPurchase($expiredAccount));

        [$graceAccount] = $this->festivalAccount();
        $graceAccount->subscription()->update([
            'status' => SubscriptionStatus::PastDue,
            'billing_mode' => 'location_v2',
            'ends_at' => now()->subDay(),
            'grace_ends_at' => now()->addDays(3),
        ]);
        $graceAccount->unsetRelation('subscription');
        $this->assertTrue($access->canPurchase($graceAccount));
    }

    public function test_available_entitlement_is_consumed_once_and_manager_may_redeem_it(): void
    {
        [$account, $owner, $package] = $this->festivalAccount(['price_cents' => 0]);
        $manager = User::factory()->create();
        $account->users()->attach($manager->id, ['role' => AccountRole::Manager->value]);
        $series = FestivalSeries::factory()->for($account)->create();
        $purchase = FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $package->subscription_plan_id,
            'festival_tariff_package_id' => $package->id,
            'created_by_user_id' => $owner->id,
            'amount_cents' => 0,
            'provider' => null,
            'order_id' => null,
        ]);

        $response = $this->actingAs($manager)->post(route('dashboard.accounts.festivals.store', $account), $this->editionData($series, $purchase));
        $edition = FestivalEdition::query()->whereBelongsTo($account)->firstOrFail();
        $response->assertRedirect(route('dashboard.accounts.festivals.show', [$account, $edition]));
        $this->assertSame(FestivalEditionPurchaseStatus::Redeemed, $purchase->refresh()->status);
        $this->assertSame($edition->id, $purchase->festival_edition_id);

        $this->actingAs($manager)->post(route('dashboard.accounts.festivals.store', $account), $this->editionData($series, $purchase))
            ->assertSessionHasErrors('festival_purchase_id');
        $this->assertSame(1, FestivalEdition::query()->whereBelongsTo($account)->count());
    }

    public function test_callbacks_are_idempotent_validate_amount_and_reversal_makes_edition_read_only(): void
    {
        [$account, $owner, $package] = $this->festivalAccount();
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->for($series)->create(['account_id' => $account->id]);
        $purchase = FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $package->subscription_plan_id,
            'festival_tariff_package_id' => $package->id,
            'created_by_user_id' => $owner->id,
            'festival_edition_id' => $edition->id,
            'status' => FestivalEditionPurchaseStatus::Redeemed,
            'gateway_invoice_id' => 'festival-invoice-1',
        ]);
        $complete = app(CompleteFestivalEditionPurchase::class);

        $paid = $this->paymentCallback($purchase, PaymentCallbackStatus::Paid, 'success');
        $this->assertSame(FestivalEditionPurchaseStatus::Redeemed, $complete->execute($purchase, $paid)->status);
        $this->assertSame(FestivalEditionPurchaseStatus::Redeemed, $complete->execute($purchase, $paid)->status);

        $this->expectException(InvalidPaymentCallbackException::class);
        try {
            $complete->execute($purchase, new PaymentCallbackResult(
                orderId: (string) $purchase->order_id,
                status: PaymentCallbackStatus::Paid,
                gatewayStatus: 'success',
                amountCents: $purchase->amount_cents + 1,
                currency: $purchase->currency,
                gatewayInvoiceId: $purchase->gateway_invoice_id,
                gatewayPaymentId: null,
                failureReason: null,
                paidAt: now(),
                payload: [],
            ));
        } finally {
            $reversed = $this->paymentCallback($purchase, PaymentCallbackStatus::Cancelled, 'reversed');
            $this->assertSame(FestivalEditionPurchaseStatus::PaymentReversed, $complete->execute($purchase, $reversed)->status);
            $this->assertSame(FestivalEditionPurchaseStatus::PaymentReversed, $complete->execute($purchase->refresh(), $reversed)->status);
            $this->assertSame(FestivalEditionPurchaseStatus::PaymentReversed, $complete->execute($purchase->refresh(), $paid)->status);
            $this->actingAs($owner)->put(route('dashboard.accounts.festivals.update', [$account, $edition]), $this->editionData($series, $purchase))
                ->assertStatus(423);
        }
    }

    public function test_admission_inventory_cannot_exceed_the_current_package_ticket_limit(): void
    {
        [$account, $owner, $package] = $this->festivalAccount();
        $package->forceFill(['max_tickets' => 2])->save();
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->for($series)->create(['account_id' => $account->id]);
        FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $package->subscription_plan_id,
            'festival_tariff_package_id' => $package->id,
            'created_by_user_id' => $owner->id,
            'festival_edition_id' => $edition->id,
        ]);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.admission-types.store', [$account, $edition]), [
            'name' => 'Guests',
            'inventory' => 3,
            'price' => '0.00',
            'max_per_order' => 2,
        ])->assertSessionHasErrors('inventory');
        $this->assertSame(0, $edition->admissionTypes()->count());
    }

    public function test_participant_limit_counts_distinct_people_and_releases_rejected_capacity(): void
    {
        Queue::fake();
        [$account, $owner, $package] = $this->festivalAccount();
        $package->forceFill(['max_participants' => 1])->save();
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $package->subscription_plan_id,
            'festival_tariff_package_id' => $package->id,
            'created_by_user_id' => $owner->id,
            'festival_edition_id' => $edition->id,
        ]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $category = FestivalCategory::factory()->for($edition)->create([
            'account_id' => $account->id,
        ]);
        $firstParticipant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $secondParticipant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);

        $first = $this->entryWithParticipant($account, $edition, $portalUser, $category, $firstParticipant);
        $second = $this->entryWithParticipant($account, $edition, $portalUser, $category, $firstParticipant);
        $this->submitApplication($first);
        $this->submitApplication($second);

        $overLimit = $this->entryWithParticipant($account, $edition, $portalUser, $category, $secondParticipant);
        try {
            $this->submitApplication($overLimit);
            $this->fail('A second distinct participant should exceed the Festival package limit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('participants', $exception->errors());
        }

        $first->update(['status' => 'rejected']);
        $second->update(['status' => 'withdrawn']);
        $this->submitApplication($overLimit);
        $this->assertSame('submitted', $overLimit->refresh()->status->value);
    }

    public function test_paid_purchase_prefers_active_tokenized_saas_card(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/wallet/payment' => Http::response([
                'invoiceId' => 'festival-token-invoice',
                'status' => 'processing',
                'pageUrl' => 'https://pay.example/festival-token-invoice',
            ]),
        ]);
        [$account, $owner, $package] = $this->festivalAccount();
        $this->platformMonopaySetting();
        $subscription = $account->subscription()->firstOrFail();
        $method = AccountSubscriptionPaymentMethod::factory()->create([
            'account_id' => $account->id,
            'account_subscription_id' => $subscription->id,
            'provider_card_token' => 'festival-card-token',
            'status' => 'active',
            'verified_at' => now(),
        ]);
        $purchase = FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $package->subscription_plan_id,
            'festival_tariff_package_id' => $package->id,
            'account_subscription_payment_method_id' => $method->id,
            'created_by_user_id' => $owner->id,
            'status' => FestivalEditionPurchaseStatus::PaymentStarted,
        ]);

        $url = app(StartFestivalEditionPurchasePayment::class)->execute($purchase, 'https://ladna.local/festivals');
        $this->assertSame('https://pay.example/festival-token-invoice', $url);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/wallet/payment'
            && $request['cardToken'] === 'festival-card-token'
            && $request['amount'] === $purchase->amount_cents
            && $request['merchantPaymInfo']['reference'] === $purchase->order_id);
        $this->assertSame(FestivalEditionPurchaseStatus::PaymentPending, $purchase->refresh()->status);
    }

    public function test_paid_purchase_falls_back_to_hosted_checkout_without_tokenizing_card(): void
    {
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/create' => Http::response([
                'invoiceId' => 'festival-hosted-invoice',
                'pageUrl' => 'https://pay.example/festival-hosted-invoice',
            ]),
        ]);
        [$account, $owner, $package] = $this->festivalAccount();
        $this->platformMonopaySetting();
        $purchase = FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $package->subscription_plan_id,
            'festival_tariff_package_id' => $package->id,
            'account_subscription_payment_method_id' => null,
            'created_by_user_id' => $owner->id,
            'status' => FestivalEditionPurchaseStatus::PaymentStarted,
        ]);

        $url = app(StartFestivalEditionPurchasePayment::class)->execute($purchase, 'https://ladna.local/festivals');
        $this->assertSame('https://pay.example/festival-hosted-invoice', $url);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/invoice/create'
            && $request['amount'] === $purchase->amount_cents
            && ! isset($request['saveCardData']));
        $this->assertSame(FestivalEditionPurchaseStatus::PaymentPending, $purchase->refresh()->status);
    }

    /** @return array{Account, User, FestivalTariffPackage} */
    private function festivalAccount(array $packageOverrides = []): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $plan = SubscriptionPlan::factory()->create(['currency' => 'UAH']);
        AccountSubscription::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $plan->id,
        ]);
        $package = FestivalTariffPackage::factory()->create([
            'subscription_plan_id' => $plan->id,
            'name' => 'S '.Str::random(8),
            'price_cents' => 150000,
            'currency' => 'UAH',
            'max_participants' => 100,
            'max_tickets' => 300,
            ...$packageOverrides,
        ]);

        return [$account, $owner, $package];
    }

    /** @return array<string, mixed> */
    private function editionData(FestivalSeries $series, FestivalEditionPurchase $purchase): array
    {
        return [
            'festival_purchase_id' => $purchase->id,
            'festival_series_id' => $series->id,
            'title' => 'Prepaid Festival',
            'status' => 'draft',
            'registration_status' => 'closed',
            'timezone' => 'Europe/Kyiv',
            'currency' => 'UAH',
            'starts_at' => now()->addMonth()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addMonth()->addDay()->format('Y-m-d H:i:s'),
            'age_reference_date' => now()->addMonth()->toDateString(),
        ];
    }

    private function paymentCallback(FestivalEditionPurchase $purchase, PaymentCallbackStatus $status, string $gatewayStatus): PaymentCallbackResult
    {
        return new PaymentCallbackResult(
            orderId: (string) $purchase->order_id,
            status: $status,
            gatewayStatus: $gatewayStatus,
            amountCents: $purchase->amount_cents,
            currency: $purchase->currency,
            gatewayInvoiceId: $purchase->gateway_invoice_id,
            gatewayPaymentId: null,
            failureReason: null,
            paidAt: now(),
            payload: [],
        );
    }

    private function entryWithParticipant(
        Account $account,
        FestivalEdition $edition,
        FestivalPortalUser $portalUser,
        FestivalCategory $category,
        FestivalParticipant $participant,
    ): FestivalEntry {
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
        ]);
        $entry->participants()->sync([$participant->id => [
            'account_id' => $account->id,
            'sort_order' => 0,
        ]]);

        return $entry;
    }

    private function submitApplication(FestivalEntry $entry): void
    {
        $entry = app(InitializeFestivalEntryWorkflow::class)->execute($entry);
        app(SubmitFestivalEntryStep::class)->execute($entry, $entry->steps->first());
    }

    private function platformMonopaySetting(): IntegrationSetting
    {
        return IntegrationSetting::factory()->create([
            'is_enabled' => true,
            'credentials' => ['api_token' => 'festival-test-token', 'invoice_validity_seconds' => 3600],
        ]);
    }
}
