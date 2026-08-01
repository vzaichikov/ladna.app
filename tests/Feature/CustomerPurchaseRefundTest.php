<?php

namespace Tests\Feature;

use App\Actions\RecordStudioCashEntry;
use App\Enums\AccountRole;
use App\Enums\CustomerPurchaseStatus;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class CustomerPurchaseRefundTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cash_refund_is_audited_idempotent_and_reconciles_class_pass_in_source_currency(): void
    {
        [$owner, $account, $location, $customerClassPass, $purchase] = $this->classPassPaymentContext([
            'payment_source' => CustomerPurchase::SourceManualCashClassPass,
            'provider' => CustomerPurchase::ProviderStudioCash,
            'currency' => 'USD',
        ]);
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'refund_payment_id' => $purchase->id,
            'amount' => '250.00',
            'method' => CustomerPurchaseRefund::MethodCash,
            'cash_location_id' => $location->id,
            'reason' => 'Client requested a partial cash refund.',
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), $payload)
            ->assertRedirect()
            ->assertSessionHas('status', __('app.payment_refund_saved'));

        $refund = CustomerPurchaseRefund::query()->sole();
        $cashEntry = StudioCashEntry::query()->sole();

        $this->assertSame($purchase->id, $refund->customer_purchase_id);
        $this->assertSame(25000, $refund->amount_cents);
        $this->assertSame('USD', $refund->currency);
        $this->assertSame(CustomerPurchaseRefund::MethodCash, $refund->method);
        $this->assertSame($location->id, $refund->location_id);
        $this->assertSame($location->id, $refund->cash_location_id);
        $this->assertSame($owner->id, $refund->actor_user_id);
        $this->assertSame('Client requested a partial cash refund.', $refund->reason);
        $this->assertSame(StudioCashEntry::DirectionOut, $cashEntry->direction);
        $this->assertSame(StudioCashEntry::PurposePaymentRefund, $cashEntry->purpose);
        $this->assertSame($refund->id, $cashEntry->customer_purchase_refund_id);
        $this->assertSame('USD', $cashEntry->currency);
        $this->assertSame(100000, $purchase->fresh()->amount_cents);
        $this->assertSame(75000, $customerClassPass->fresh()->paid_amount_cents);
        $this->assertFalse($customerClassPass->fresh()->is_paid);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), $payload)
            ->assertRedirect();

        $this->assertSame(1, CustomerPurchaseRefund::query()->count());
        $this->assertSame(1, StudioCashEntry::query()->count());

        foreach ([
            fn () => $refund->update(['amount_cents' => 1]),
            fn () => $refund->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('An audited refund was mutated.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_multiple_cashless_refunds_are_capped_and_create_no_cash_movements(): void
    {
        [$owner, $account, , $customerClassPass, $purchase] = $this->classPassPaymentContext();

        foreach (['400.00', '600.00'] as $amount) {
            $this->actingAs($owner)
                ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), [
                    'refund_payment_id' => $purchase->id,
                    'amount' => $amount,
                    'method' => CustomerPurchaseRefund::MethodCashless,
                    'reason' => 'Cashless partial refund for the client.',
                    'idempotency_key' => (string) Str::uuid(),
                ])
                ->assertRedirect()
                ->assertSessionDoesntHaveErrors();
        }

        $this->assertSame(2, $purchase->refunds()->count());
        $this->assertSame(100000, (int) $purchase->refunds()->sum('amount_cents'));
        $this->assertSame(0, StudioCashEntry::query()->count());
        $this->assertSame(0, $customerClassPass->fresh()->paid_amount_cents);
        $this->assertFalse($customerClassPass->fresh()->is_paid);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), [
                'refund_payment_id' => $purchase->id,
                'amount' => '0.01',
                'method' => CustomerPurchaseRefund::MethodCashless,
                'reason' => 'This exceeds the remaining refundable amount.',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('amount');

        $this->assertSame(2, $purchase->refunds()->count());
    }

    public function test_refund_idempotency_key_rejects_any_changed_business_payload(): void
    {
        [$owner, $account, $location, , $purchase] = $this->classPassPaymentContext();
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'amount' => '100.00',
            'method' => CustomerPurchaseRefund::MethodCash,
            'cash_location_id' => $location->id,
            'reason' => 'Original cash refund.',
            'idempotency_key' => $idempotencyKey,
        ];

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), $payload)
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        foreach ([
            [...$payload, 'amount' => '101.00'],
            [...$payload, 'method' => CustomerPurchaseRefund::MethodCashless, 'cash_location_id' => null],
            [...$payload, 'reason' => 'Changed refund reason.'],
        ] as $changedPayload) {
            $this->actingAs($owner)
                ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), $changedPayload)
                ->assertRedirect()
                ->assertSessionHasErrors('idempotency_key');
        }

        $refund = CustomerPurchaseRefund::query()->sole();

        $this->assertSame(10000, $refund->amount_cents);
        $this->assertSame(CustomerPurchaseRefund::MethodCash, $refund->method);
        $this->assertSame('Original cash refund.', $refund->reason);
        $this->assertSame(1, StudioCashEntry::query()->count());
    }

    public function test_cash_refund_rolls_back_when_its_ledger_write_fails(): void
    {
        [$owner, $account, $location, $customerClassPass, $purchase] = $this->classPassPaymentContext();
        $nextRefundId = $this->nextAutoIncrementId('customer_purchase_refunds');

        app(RecordStudioCashEntry::class)->execute(
            $account,
            $location,
            StudioCashEntry::DirectionIn,
            1,
            now(),
            $owner,
            'Reserve a conflicting refund source key.',
            sourceKey: 'refund:'.$nextRefundId,
        );

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), [
                'amount' => '100.00',
                'method' => CustomerPurchaseRefund::MethodCash,
                'cash_location_id' => $location->id,
                'reason' => 'This refund must roll back.',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('source_key');

        $this->assertSame(0, CustomerPurchaseRefund::query()->count());
        $this->assertSame(100000, $customerClassPass->fresh()->paid_amount_cents);
        $this->assertTrue($customerClassPass->fresh()->is_paid);
        $this->assertSame(1, StudioCashEntry::query()->count());
    }

    public function test_payment_correction_cannot_reduce_source_below_recorded_refunds(): void
    {
        [$owner, $account, $location, , $purchase] = $this->classPassPaymentContext([
            'payment_source' => CustomerPurchase::SourceManualCashClassPass,
            'provider' => CustomerPurchase::ProviderStudioCash,
        ]);
        CustomerPurchaseRefund::factory()
            ->for($account)
            ->for($purchase, 'customerPurchase')
            ->create([
                'location_id' => $location->id,
                'method' => CustomerPurchaseRefund::MethodCashless,
                'amount_cents' => 40000,
                'currency' => $purchase->currency,
            ]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.corrections.store', [$account, $purchase]), [
                'location_id' => $location->id,
                'amount' => '300.00',
                'paid_at' => '2026-07-30T12:00',
                'reason' => 'Attempt to reduce below the refunded amount.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('amount');

        $this->assertSame(100000, $purchase->fresh()->amount_cents);
        $this->assertSame(0, $purchase->corrections()->count());
    }

    public function test_booking_addon_refund_does_not_change_class_pass_payment_state(): void
    {
        [$owner, $account, , $customerClassPass, $purchase] = $this->classPassPaymentContext([
            'payment_source' => CustomerPurchase::SourceManualCashBooking,
            'provider' => CustomerPurchase::ProviderStudioCash,
            'amount_cents' => 10000,
        ]);
        $customerClassPass->update([
            'paid_amount_cents' => 100000,
            'is_paid' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), [
                'refund_payment_id' => $purchase->id,
                'amount' => '100.00',
                'method' => CustomerPurchaseRefund::MethodCashless,
                'reason' => 'Return an any-time booking add-on.',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(100000, $customerClassPass->fresh()->paid_amount_cents);
        $this->assertTrue($customerClassPass->fresh()->is_paid);
    }

    public function test_refund_route_is_permission_and_tenant_scoped(): void
    {
        [$owner, $account, , , $purchase] = $this->classPassPaymentContext();
        $trainer = User::factory()->create();
        $account->users()->syncWithoutDetaching([
            $trainer->id => ['role' => AccountRole::Trainer->value, 'permissions' => null],
        ]);
        $otherAccount = Account::factory()->create();

        $payload = [
            'amount' => '100.00',
            'method' => CustomerPurchaseRefund::MethodCashless,
            'reason' => 'Unauthorized refund attempt.',
            'idempotency_key' => (string) Str::uuid(),
        ];

        $this->actingAs($trainer)
            ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), $payload)
            ->assertForbidden();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.refunds.store', [$otherAccount, $purchase]), $payload)
            ->assertNotFound();

        $this->assertSame(0, CustomerPurchaseRefund::query()->count());
    }

    public function test_payment_history_projects_refunds_and_period_totals_by_refund_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 12:00:00', 'UTC'));
        [$owner, $account, $location, , $purchase] = $this->classPassPaymentContext([
            'paid_at' => '2026-07-29 10:00:00',
        ]);
        CustomerPurchaseRefund::factory()
            ->for($account)
            ->for($purchase, 'customerPurchase')
            ->for($location)
            ->create([
                'method' => CustomerPurchaseRefund::MethodCashless,
                'amount_cents' => 25000,
                'currency' => 'UAH',
                'refunded_at' => '2026-07-30 11:00:00',
                'reason' => 'Visible partial payment refund.',
            ]);

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'date_from' => '2026-07-29',
                'date_to' => '2026-07-30',
            ]))
            ->assertOk()
            ->assertSee(__('app.payment_refund'))
            ->assertSee('Visible partial payment refund.')
            ->assertSee(__('app.refund_payment'));

        $this->assertSame(['UAH' => 100000], $response->viewData('periodOverview')['gross_income_by_currency']);
        $this->assertSame(['UAH' => 25000], $response->viewData('periodOverview')['refunds_by_currency']);
        $this->assertSame(['UAH' => 75000], $response->viewData('periodOverview')['income_by_currency']);
        $this->assertSame('refund', $response->viewData('payments')->first()['type']);

        $filteredResponse = $this->actingAs($owner)
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'date_from' => '2026-07-29',
                'date_to' => '2026-07-30',
                'status' => CustomerPurchaseRefund::StatusRecorded,
            ]))
            ->assertOk()
            ->assertSee('Visible partial payment refund.');

        $this->assertCount(1, $filteredResponse->viewData('payments'));
        $this->assertSame('refund', $filteredResponse->viewData('payments')->sole()['type']);

        Carbon::setTestNow();
    }

    /**
     * @param  array<string, mixed>  $purchaseAttributes
     * @return array{0: User, 1: Account, 2: Location, 3: CustomerClassPass, 4: CustomerPurchase}
     */
    private function classPassPaymentContext(array $purchaseAttributes = []): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'default_currency' => 'UAH',
            'timezone' => 'UTC',
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['timezone' => 'UTC']);
        $customer = Customer::factory()->for($account)->create(['name' => 'Refund Client']);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Refund pass',
            'price_cents' => 100000,
            'currency' => 'UAH',
        ]);
        $customerClassPass = CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->create([
                'plan_name' => $plan->name,
                'plan_slug' => $plan->slug,
                'price_cents' => 100000,
                'paid_amount_cents' => 100000,
                'is_paid' => true,
                'currency' => 'UAH',
            ]);
        $purchase = CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($location)
            ->for($plan, 'classPassPlan')
            ->for($customerClassPass, 'customerClassPass')
            ->create([
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'payment_source' => CustomerPurchase::SourceOnlineCheckout,
                'provider' => 'liqpay',
                'amount_cents' => 100000,
                'currency' => 'UAH',
                'paid_at' => now(),
                ...$purchaseAttributes,
            ]);

        return [$owner, $account, $location, $customerClassPass, $purchase];
    }

    private function nextAutoIncrementId(string $table): int
    {
        $result = DB::selectOne(
            'SELECT AUTO_INCREMENT AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        return (int) $result->next_id;
    }
}
