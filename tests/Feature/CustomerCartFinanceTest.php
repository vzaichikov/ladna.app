<?php

namespace Tests\Feature;

use App\Actions\RecalculateCustomerClassPassPayment;
use App\Actions\RecordCustomerPurchaseRefund;
use App\Actions\RecordManualCustomerClassPassPayment;
use App\Actions\RecordStudioCashEntry;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\FiscalReceiptStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassReservation;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseItem;
use App\Models\CustomerPurchaseRefund;
use App\Models\CustomerPurchaseRefundItem;
use App\Models\FiscalReceipt;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\StudioCashEntry;
use App\Models\Trainer;
use App\Models\User;
use App\Support\Finance\RentalReportData;
use App\Support\Fiscalization\FiscalReceiptService;
use App\Support\Payments\StudioPaymentToolData;
use App\Support\Salary\ClassPassSessionValueResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class CustomerCartFinanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_cart_recalculation_keeps_list_prices_and_settles_discounted_and_free_passes(): void
    {
        [, $account, , , $items] = $this->cartPaymentContext();

        foreach ($items as $item) {
            $pass = $item->customerClassPass;
            $pass->update(['paid_amount_cents' => 0, 'is_paid' => false]);
            $pass = app(RecalculateCustomerClassPassPayment::class)->execute($pass);

            $this->assertSame(10000, $pass->price_cents);
            $this->assertSame($item->amount_cents, $pass->payableAmountCents());
            $this->assertSame($item->amount_cents, $pass->paid_amount_cents);
            $this->assertSame(0, $pass->remainingPaymentCents());
            $this->assertTrue($pass->is_paid);
        }

        $this->assertSame(0, $account->customerClassPasses()->outstandingBalance()->count());
    }

    public function test_cart_refund_allocates_one_payment_and_cash_movement_only_to_selected_passes(): void
    {
        [$owner, $account, $location, $purchase, $items] = $this->cartPaymentContext();
        $payload = $this->refundPayload($purchase, [
            ['customer_purchase_item_id' => $items[0]->id, 'amount' => '10.00'],
            ['customer_purchase_item_id' => $items[1]->id, 'amount' => '20.00'],
        ], '30.00', [
            'method' => CustomerPurchaseRefund::MethodCash,
            'cash_location_id' => $location->id,
        ]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), $payload)
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $refund = CustomerPurchaseRefund::query()->sole();
        $entry = StudioCashEntry::query()->sole();

        $this->assertSame(3000, $refund->amount_cents);
        $this->assertSame(2, $refund->items()->count());
        $this->assertSame(3000, (int) $refund->items()->sum('amount_cents'));
        $this->assertSame(3000, $entry->amount_cents);
        $this->assertSame(StudioCashEntry::DirectionOut, $entry->direction);
        $this->assertSame($refund->id, $entry->customer_purchase_refund_id);
        $this->assertSame(9000, $items[0]->customerClassPass->fresh()->paid_amount_cents);
        $this->assertSame(6500, $items[1]->customerClassPass->fresh()->paid_amount_cents);
        $this->assertFalse($items[0]->customerClassPass->fresh()->is_paid);
        $this->assertTrue($items[2]->customerClassPass->fresh()->is_paid);
        $this->assertSame(0, $items[2]->customerClassPass->fresh()->paid_amount_cents);
        $this->assertSame(18500, $purchase->fresh()->amount_cents);
        $this->assertSame(11500, $purchase->fresh()->discount_cents);
        $this->assertSame(30000, $purchase->fresh()->subtotal_cents);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), [
                ...$payload,
                'items' => array_reverse($payload['items']),
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(1, $purchase->refunds()->count());
        $this->assertSame(2, CustomerPurchaseRefundItem::query()->count());
        $this->assertSame(1, StudioCashEntry::query()->count());

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), [
                ...$payload,
                'items' => [
                    ['customer_purchase_item_id' => $items[0]->id, 'amount' => '20.00'],
                    ['customer_purchase_item_id' => $items[1]->id, 'amount' => '10.00'],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('idempotency_key');

        $allocation = $refund->items()->firstOrFail();

        foreach ([fn () => $allocation->update(['amount_cents' => 1]), fn () => $allocation->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('A refund allocation was mutated.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cart_refund_rejects_missing_duplicate_foreign_free_and_excessive_item_allocations(): void
    {
        [$owner, $account, , $purchase, $items] = $this->cartPaymentContext();
        [, , , , $otherItems] = $this->cartPaymentContext();
        $cases = [
            [[], '1.00', 'items'],
            [[['customer_purchase_item_id' => $items[2]->id, 'amount' => '0.01']], '0.01', 'items'],
            [[['customer_purchase_item_id' => $items[1]->id, 'amount' => '85.01']], '85.01', 'items'],
            [[['customer_purchase_item_id' => $items[0]->id, 'amount' => '1.00']], '2.00', 'amount'],
            [[['customer_purchase_item_id' => $otherItems[0]->id, 'amount' => '1.00']], '1.00', 'items.0.customer_purchase_item_id'],
            [[
                ['customer_purchase_item_id' => $items[0]->id, 'amount' => '1.00'],
                ['customer_purchase_item_id' => $items[0]->id, 'amount' => '1.00'],
            ], '2.00', 'items.0.customer_purchase_item_id'],
        ];

        foreach ($cases as [$allocations, $amount, $error]) {
            $this->actingAs($owner)
                ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), $this->refundPayload($purchase, $allocations, $amount))
                ->assertRedirect()
                ->assertSessionHasErrors($error);
        }

        $this->assertSame(0, $purchase->refunds()->count());
        $this->assertSame(0, CustomerPurchaseRefundItem::query()->count());
        $this->assertSame(0, StudioCashEntry::query()->count());
    }

    public function test_discounted_cart_pass_refund_balance_can_be_repaid_without_charging_list_price(): void
    {
        [$owner, $account, $location, $purchase, $items] = $this->cartPaymentContext();
        $pass = $items[1]->customerClassPass;

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), $this->refundPayload($purchase, [
                ['customer_purchase_item_id' => $items[1]->id, 'amount' => '85.00'],
            ], '85.00'))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(8500, $pass->fresh()->remainingPaymentCents());
        $this->assertSame(1, $account->customerClassPasses()->outstandingBalance()->count());
        $this->assertSame(0, StudioCashEntry::query()->count());

        app(RecordManualCustomerClassPassPayment::class)->execute($account, $pass->fresh(), $location, 8500, user: $owner);
        $pass = app(RecalculateCustomerClassPassPayment::class)->execute($pass);

        $this->assertSame(8500, $pass->paid_amount_cents);
        $this->assertSame(10000, $pass->price_cents);
        $this->assertSame(0, $pass->remainingPaymentCents());
        $this->assertTrue($pass->is_paid);
        $this->assertTrue($items[2]->customerClassPass->fresh()->is_paid);
    }

    public function test_cart_refund_rolls_back_all_allocations_when_cash_ledger_fails(): void
    {
        [$owner, $account, $location, $purchase, $items] = $this->cartPaymentContext();
        $this->mock(RecordStudioCashEntry::class)
            ->shouldReceive('execute')
            ->once()
            ->andThrow(new RuntimeException('Cash ledger unavailable.'));

        try {
            app(RecordCustomerPurchaseRefund::class)->execute(
                $account,
                $purchase,
                CustomerPurchaseRefund::MethodCash,
                $location,
                1000,
                now(),
                $owner,
                'Refund must roll back with its ledger.',
                (string) Str::uuid(),
                [['customer_purchase_item_id' => $items[0]->id, 'amount_cents' => 1000]],
            );
            $this->fail('A failed ledger write was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cash ledger unavailable.', $exception->getMessage());
        }

        $this->assertSame(0, $purchase->refunds()->count());
        $this->assertSame(0, CustomerPurchaseRefundItem::query()->count());
        $this->assertSame(10000, $items[0]->customerClassPass->fresh()->paid_amount_cents);
        $this->assertTrue($items[0]->customerClassPass->fresh()->is_paid);
    }

    public function test_finance_and_tools_count_one_card_transfer_payment_with_pass_details(): void
    {
        [$owner, $account, , $purchase, $items] = $this->cartPaymentContext();
        $response = $this->actingAs($owner)
            ->withSession(['locale' => 'en'])
            ->get(route('dashboard.accounts.payments.index', [
                'account' => $account,
                'payment_method' => CustomerPurchase::PaymentMethodCardTransfer,
            ]))
            ->assertOk()
            ->assertSee(__('app.payment_method_card_transfer'));

        foreach ($items as $item) {
            $response->assertSee($item->customerClassPass->code);
        }

        $this->assertCount(1, $response->viewData('payments'));
        $this->assertSame(['UAH' => 18500], $response->viewData('periodOverview')['gross_income_by_currency']);
        $this->assertSame([], $response->viewData('periodOverview')['cash_received_by_currency']);
        $this->assertSame($purchase->id, $response->viewData('payments')->sole()['record']->id);

        $payload = app(StudioPaymentToolData::class)->search($account, ['kind' => 'customer_payment']);

        $this->assertSame(1, $payload['returned']);
        $this->assertSame(CustomerPurchase::PaymentMethodCardTransfer, $payload['items'][0]['payment_method']);
        $this->assertSame(3, $payload['items'][0]['class_pass_items_count']);
        $this->assertCount(3, $payload['items'][0]['class_pass_items']);

        foreach ([CustomerPurchase::PaymentMethodCash, CustomerPurchase::PaymentMethodOnline] as $paymentMethod) {
            $this->actingAs($owner)
                ->get(route('dashboard.accounts.payments.index', ['account' => $account, 'payment_method' => $paymentMethod]))
                ->assertOk()
                ->assertViewHas('payments', fn ($payments): bool => $payments->isEmpty());
        }
    }

    public function test_class_pass_history_includes_allocated_parent_payment_and_its_own_refund(): void
    {
        [$owner, $account, , $purchase, $items] = $this->cartPaymentContext();
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), $this->refundPayload($purchase, [
                ['customer_purchase_item_id' => $items[1]->id, 'amount' => '1.00'],
            ], '1.00'))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.customer-class-passes.edit', [$account, $items[1]->customerClassPass]))
            ->assertOk()
            ->assertSee($purchase->order_id);
        $history = $response->viewData('classPassHistoryEntries');

        $this->assertSame(8500, $history->where('type', 'payment')->sole()['context']['amount_cents']);
        $this->assertSame(18500, $history->where('type', 'payment')->sole()['context']['parent_amount_cents']);
        $this->assertSame(100, $history->where('type', 'refund')->sole()['context']['amount_cents']);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.customer-class-passes.edit', [$account, $items[2]->customerClassPass]))
            ->assertOk()
            ->assertViewHas('classPassHistoryEntries', fn (Collection $entries): bool => $entries->where('type', 'refund')->isEmpty()
                && $entries->where('type', 'payment')->sole()['context']['amount_cents'] === 0);
    }

    public function test_cart_cash_payment_amount_cannot_be_corrected(): void
    {
        [$owner, $account, $location, $purchase] = $this->cartPaymentContext([
            'provider' => CustomerPurchase::ProviderStudioCash,
            'payment_source' => CustomerPurchase::SourceManualCashClassPass,
        ]);

        $this->assertFalse($purchase->canBeCorrectedAsStudioCash());
        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.corrections.store', [$account, $purchase]), [
                'location_id' => $location->id,
                'amount' => '100.00',
                'paid_at' => now()->format('Y-m-d\TH:i'),
                'reason' => 'Attempt to change a completed cart amount.',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame(18500, $purchase->fresh()->amount_cents);
    }

    public function test_discounted_and_free_rental_passes_have_no_debt_and_preserve_payroll_value(): void
    {
        [, $account, $location, $purchase, $items] = $this->cartPaymentContext();
        $direction = ActivityDirection::factory()->for($account)->create();
        $classType = ClassType::factory()->for($account)->for($direction)->create(['schedule_kind' => ScheduleKind::RoomRental]);
        $room = Room::factory()->for($account)->for($location)->create();
        $trainer = Trainer::factory()->for($account)->create();
        $reservations = collect();

        foreach ($items as $position => $item) {
            $rental = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($trainer)->for($classType)->create([
                'starts_at' => now()->subDay()->startOfDay()->addHours($position),
                'ends_at' => now()->subDay()->startOfDay()->addHours($position + 1),
            ]);
            $booking = ClassBooking::factory()->for($account)->for($rental)->for($purchase->customer)->create(['status' => ClassBookingStatus::Attended]);
            $reservations->push(CustomerClassPassReservation::factory()->for($account)->for($item->customerClassPass)->for($booking)->for($rental)->create([
                'status' => CustomerClassPassReservationStatus::Used,
                'reserved_at' => $rental->starts_at,
                'used_at' => $rental->ends_at,
            ]));
        }

        $report = app(RentalReportData::class)->forAccount($account, ['date_from' => now()->subDay()->toDateString(), 'date_to' => now()->toDateString(), 'location_id' => $location->id], now()->subDay()->startOfDay(), now()->endOfDay());

        $this->assertSame(['UAH' => 18500], $report['totals']['accrued']);
        $this->assertSame(['UAH' => 18500], $report['totals']['paid']);
        $this->assertSame(['UAH' => 0], $report['totals']['debt']);
        $this->assertSame(['paid', 'paid', 'paid'], $report['rows']->pluck('status')->all());

        $sessionValues = app(ClassPassSessionValueResolver::class);
        $positions = $sessionValues->positionsFor($account, $reservations);

        foreach ($reservations as $reservation) {
            $this->assertSame(10000, $sessionValues->amountCents($reservation, $positions));
        }
    }

    public function test_cashless_cart_receipt_uses_discounted_item_goods_and_omits_free_passes(): void
    {
        [, $account, , $purchase, $items] = $this->cartPaymentContext();
        $this->enableFiscalization($account);
        $this->fakeCheckbox();

        $receipt = app(FiscalReceiptService::class)->fiscalizeCustomerPurchase($purchase);

        $this->assertSame(FiscalReceiptStatus::Fiscalized, $receipt?->status);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/receipts/sell'
            && count($request->data()['goods']) === 2
            && data_get($request->data(), 'goods.0.good.code') === $purchase->order_id.'-'.$items[0]->id
            && data_get($request->data(), 'goods.0.good.price') === 10000
            && data_get($request->data(), 'goods.1.good.price') === 8500
            && data_get($request->data(), 'payments.0.type') === 'CASHLESS'
            && data_get($request->data(), 'total_sum') === 18500);
    }

    public function test_cart_refund_receipt_uses_original_item_codes_and_selected_allocations(): void
    {
        [$owner, $account, , $purchase, $items] = $this->cartPaymentContext();
        $this->enableFiscalization($account);
        FiscalReceipt::factory()->forAccountScope($account)->for($purchase, 'payment')->fiscalized()->create();
        $this->fakeCheckbox();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.payments.refunds.store', [$account, $purchase]), $this->refundPayload($purchase, [
                ['customer_purchase_item_id' => $items[0]->id, 'amount' => '10.00'],
                ['customer_purchase_item_id' => $items[1]->id, 'amount' => '5.00'],
            ], '15.00'))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $refund = CustomerPurchaseRefund::query()->sole();
        $this->assertSame(FiscalReceiptStatus::Fiscalized, $refund->fiscalReceipt?->status);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/receipts/sell'
            && count($request->data()['goods']) === 2
            && data_get($request->data(), 'goods.0.good.code') === $purchase->order_id.'-'.$items[0]->id
            && data_get($request->data(), 'goods.1.good.code') === $purchase->order_id.'-'.$items[1]->id
            && data_get($request->data(), 'goods.0.good.price') === 1000
            && data_get($request->data(), 'goods.1.good.price') === 500
            && data_get($request->data(), 'goods.0.is_return') === true
            && data_get($request->data(), 'payments.0.type') === 'CASHLESS'
            && data_get($request->data(), 'total_sum') === 1500);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{User, Account, Location, CustomerPurchase, Collection<int, CustomerPurchaseItem>}
     */
    private function cartPaymentContext(array $attributes = []): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_language' => 'en', 'default_currency' => 'UAH', 'timezone' => 'UTC']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();
        $plan = ClassPassPlan::factory()->for($account)->create(['name' => 'Cart individual', 'price_cents' => 10000, 'currency' => 'UAH', 'sessions_count' => 1]);
        $purchase = CustomerPurchase::factory()->for($account)->for($customer)->for($location)->create([
            'class_pass_plan_id' => null,
            'customer_class_pass_id' => null,
            'plan_name' => 'Class-pass cart',
            'plan_slug' => null,
            'schedule_kind' => null,
            'sessions_count' => null,
            'validity_days' => null,
            'total_validity_days' => null,
            'provider' => CustomerPurchase::ProviderStudioCardTransfer,
            'payment_source' => CustomerPurchase::SourceManualCardClassPass,
            'status' => CustomerPurchaseStatus::PaymentPaid,
            'currency' => 'UAH',
            'subtotal_cents' => 30000,
            'discount_cents' => 11500,
            'amount_cents' => 18500,
            'paid_at' => now(),
            ...$attributes,
        ]);
        $items = collect([10000, 8500, 0])->map(function (int $amountCents, int $position) use ($account, $customer, $location, $plan, $purchase): CustomerPurchaseItem {
            $pass = CustomerClassPass::factory()->for($account)->for($customer)->for($plan, 'classPassPlan')->create([
                'issued_location_id' => $location->id,
                'plan_name' => $plan->name,
                'plan_slug' => $plan->slug,
                'price_cents' => 10000,
                'paid_amount_cents' => $amountCents,
                'is_paid' => true,
                'currency' => 'UAH',
                'sessions_count' => 1,
            ]);

            return CustomerPurchaseItem::factory()->for($account)->for($purchase, 'purchase')->for($plan, 'classPassPlan')->for($pass, 'customerClassPass')->create([
                'position' => $position,
                'plan_name' => $plan->name,
                'plan_slug' => $plan->slug,
                'schedule_kind' => $plan->schedule_kind->value,
                'subtotal_cents' => 10000,
                'discount_cents' => 10000 - $amountCents,
                'amount_cents' => $amountCents,
                'currency' => 'UAH',
                'sessions_count' => 1,
                'validity_days' => 30,
                'total_validity_days' => 180,
            ])->load('customerClassPass');
        });

        return [$owner, $account, $location, $purchase, $items];
    }

    /**
     * @param  array<int, array{customer_purchase_item_id: int, amount: string}>  $items
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function refundPayload(CustomerPurchase $purchase, array $items, string $amount, array $overrides = []): array
    {
        return [
            'refund_payment_id' => $purchase->id,
            'amount' => $amount,
            'items' => $items,
            'method' => CustomerPurchaseRefund::MethodCashless,
            'reason' => 'Return selected customer class passes.',
            'idempotency_key' => (string) Str::uuid(),
            ...$overrides,
        ];
    }

    private function enableFiscalization(Account $account): void
    {
        foreach ([IntegrationProvider::LadnaFiscalization, IntegrationProvider::Checkbox] as $provider) {
            IntegrationSetting::factory()->create([
                'account_id' => $account->id,
                'scope_type' => IntegrationScope::Account,
                'scope_id' => $account->id,
                'provider' => $provider,
                'category' => IntegrationCategory::Fiscalization,
                'is_enabled' => true,
                'credentials' => $provider === IntegrationProvider::Checkbox
                    ? ['license_key' => 'test-license', 'cashier_login' => 'test-cashier', 'cashier_password' => 'test-password']
                    : [],
            ]);
        }
    }

    private function fakeCheckbox(): void
    {
        Http::fake([
            'https://api.checkbox.ua/api/v1/cashier/signin' => Http::response(['access_token' => 'test-token']),
            'https://api.checkbox.ua/api/v1/cashier/shift' => Http::response(['status' => 'OPENED']),
            'https://api.checkbox.ua/api/v1/receipts/sell' => Http::response(['id' => 'test-receipt', 'status' => 'CREATED'], 201),
            'https://api.checkbox.ua/api/v1/receipts/test-receipt' => Http::response(['id' => 'test-receipt', 'status' => 'DONE', 'fiscal_code' => 'TEST-FISCAL']),
        ]);
    }
}
