<?php

namespace Tests\Feature;

use App\Actions\Payments\CompleteCustomerPurchase;
use App\Actions\Payments\CreateCustomerPurchase;
use App\Actions\Payments\ReconcilePaidTrialCustomerPurchase;
use App\Actions\ReconcileUnreservedCustomerBookingsForIssuedClassPass;
use App\Enums\CustomerClassPassAdjustmentType;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassAdjustment;
use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use App\Support\Payments\InvalidPaymentCallbackException;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class CustomerPurchaseTrialReconciliationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_trial_eligibility_validated_before_payment_survives_a_later_booking(): void
    {
        Carbon::setTestNow('2026-08-30 11:17:39');
        $context = $this->context();
        $purchase = app(CreateCustomerPurchase::class)->execute(
            $context['account'],
            $context['customer'],
            $context['plan'],
            IntegrationProvider::Monopay,
            $context['location'],
        );

        $this->assertNotNull($purchase->trial_eligibility_validated_at);

        Carbon::setTestNow('2026-08-30 11:18:39');
        $this->booking($context);
        $completed = app(CompleteCustomerPurchase::class)->execute($purchase, $this->paidCallback($purchase));

        $this->assertSame('payment_paid', $completed->status->value);
        $this->assertSame(1, CustomerClassPass::whereBelongsTo($context['customer'])->count());
        $this->assertSame(0, CustomerClassPassAdjustment::whereBelongsTo($context['account'])->count());

        Carbon::setTestNow();
    }

    public function test_purchase_evaluates_trial_eligibility_at_the_exact_persisted_marker(): void
    {
        Carbon::setTestNow('2026-08-30 11:17:39');
        $context = $this->context();
        $booking = $this->booking($context);
        $booking->timestamps = false;
        $booking->forceFill(['created_at' => now()->addMinute()])->save();

        $purchase = app(CreateCustomerPurchase::class)->execute(
            $context['account'],
            $context['customer'],
            $context['plan'],
            IntegrationProvider::Monopay,
            $context['location'],
        );

        $this->assertTrue($purchase->trial_eligibility_validated_at->equalTo(now()));
        $this->assertTrue($purchase->started_at->equalTo($purchase->trial_eligibility_validated_at));

        Carbon::setTestNow();
    }

    public function test_paid_trial_requires_and_records_an_authorized_audited_exception(): void
    {
        $context = $this->context();
        $owner = User::factory()->create(['name' => 'Auditing Owner']);
        $context['account']->addOwner($owner);
        $this->booking($context);
        $purchase = CustomerPurchase::factory()
            ->for($context['account'])
            ->for($context['customer'])
            ->for($context['plan'], 'classPassPlan')
            ->create([
                'location_id' => $context['location']->id,
                'provider' => IntegrationProvider::Monopay->value,
                'order_id' => 'MON-20260830111739-0KFZCIX7B5',
                'gateway_invoice_id' => 'mono-invoice-paid-trial',
                'status' => 'payment_pending',
                'amount_cents' => 25000,
                'plan_name' => $context['plan']->name,
                'plan_slug' => $context['plan']->slug,
                'sessions_count' => 1,
                'trial_eligibility_validated_at' => null,
            ]);
        $callback = $this->paidCallback($purchase);

        try {
            app(CompleteCustomerPurchase::class)->execute($purchase, $callback);
            $this->fail('An unvalidated trial must fail closed after payment.');
        } catch (ValidationException) {
            $this->assertSame('payment_pending', $purchase->fresh()->status->value);
        }

        $reason = 'Audited exception for verified Monobank charge after legacy checkout.';
        $completed = app(ReconcilePaidTrialCustomerPurchase::class)->execute(
            $purchase,
            $callback,
            $owner,
            $reason,
        );
        $classPass = $completed->customerClassPass()->firstOrFail();
        $adjustment = $classPass->adjustments()->sole();

        $this->assertSame('payment_paid', $completed->status->value);
        $this->assertTrue($classPass->is_paid);
        $this->assertSame(25000, $classPass->paid_amount_cents);
        $this->assertSame('online_payment', $classPass->source);
        $this->assertSame(CustomerClassPassAdjustmentType::TrialEligibilityOverride, $adjustment->adjustment_type);
        $this->assertSame($reason, $adjustment->reason);
        $this->assertSame($owner->id, $adjustment->actor_user_id);
        $this->assertSame(0, $completed->cashEntries()->count());
    }

    public function test_older_monopay_callback_cannot_regress_a_newer_customer_purchase_failure(): void
    {
        $context = $this->context();
        $context['plan']->update(['is_trial' => false]);
        $purchase = app(CreateCustomerPurchase::class)->execute(
            $context['account'],
            $context['customer'],
            $context['plan'],
            IntegrationProvider::Monopay,
            $context['location'],
        );
        $newerModifiedAt = now()->startOfSecond();

        app(CompleteCustomerPurchase::class)->execute($purchase, new PaymentCallbackResult(
            orderId: $purchase->order_id,
            status: PaymentCallbackStatus::Failed,
            gatewayStatus: 'failure',
            amountCents: $purchase->amount_cents,
            currency: $purchase->currency,
            failureReason: '3-D Secure interrupted by timeout',
            modifiedAt: $newerModifiedAt,
            payload: ['status' => 'failure', 'modifiedDate' => $newerModifiedAt->toIso8601String()],
        ));
        $completed = app(CompleteCustomerPurchase::class)->execute($purchase, new PaymentCallbackResult(
            orderId: $purchase->order_id,
            status: PaymentCallbackStatus::Pending,
            gatewayStatus: 'processing',
            amountCents: $purchase->amount_cents,
            currency: $purchase->currency,
            modifiedAt: $newerModifiedAt->copy()->subMinute(),
            payload: ['status' => 'processing', 'modifiedDate' => $newerModifiedAt->copy()->subMinute()->toIso8601String()],
        ));

        $this->assertSame('payment_failed', $completed->status->value);
        $this->assertSame('failure', $completed->gateway_status);
        $this->assertSame('failure', $completed->last_callback_payload['status']);
    }

    public function test_reconciliation_command_is_dry_run_first_and_requires_exact_execute_guards(): void
    {
        $context = $this->context();
        $owner = User::factory()->create(['name' => 'Command Audit Owner']);
        $context['account']->addOwner($owner);
        $this->booking($context);
        $purchase = CustomerPurchase::factory()
            ->for($context['account'])
            ->for($context['customer'])
            ->for($context['plan'], 'classPassPlan')
            ->create([
                'location_id' => $context['location']->id,
                'provider' => IntegrationProvider::Monopay->value,
                'order_id' => 'MON-COMMAND-AUDIT-1',
                'gateway_invoice_id' => 'mono-command-invoice-1',
                'status' => 'payment_pending',
                'amount_cents' => 25000,
                'plan_name' => $context['plan']->name,
                'plan_slug' => $context['plan']->slug,
                'sessions_count' => 1,
                'trial_eligibility_validated_at' => null,
            ]);
        IntegrationSetting::create([
            'scope_type' => IntegrationScope::Account->value,
            'scope_id' => $context['account']->id,
            'account_id' => $context['account']->id,
            'provider' => IntegrationProvider::Monopay->value,
            'category' => IntegrationCategory::Payment->value,
            'is_enabled' => true,
            'credentials' => ['api_token' => 'mono-token'],
        ]);
        Http::fake([
            'https://api.monobank.ua/api/merchant/invoice/status*' => Http::response([
                'invoiceId' => 'mono-command-invoice-1',
                'status' => 'success',
                'amount' => 25000,
                'finalAmount' => 25000,
                'ccy' => 980,
                'reference' => $purchase->order_id,
                'modifiedDate' => now()->toIso8601String(),
            ]),
        ]);
        $baseOptions = [
            '--order' => $purchase->order_id,
            '--actor' => $owner->id,
            '--reason' => 'Audited trial exception after authoritative Monobank verification.',
        ];

        $this->artisan('payments:reconcile-paid-trial', $baseOptions)
            ->expectsOutputToContain('Dry run only. No database changes were made.')
            ->assertSuccessful();
        $this->assertSame('payment_pending', $purchase->fresh()->status->value);

        $this->artisan('payments:reconcile-paid-trial', $baseOptions + ['--execute' => true])
            ->expectsOutputToContain('Exact expected purchase, account, customer, amount, and invoice values are required')
            ->assertFailed();

        $this->artisan('payments:reconcile-paid-trial', $baseOptions + [
            '--execute' => true,
            '--expected-purchase' => $purchase->id,
            '--expected-account' => $context['account']->id,
            '--expected-customer' => $context['customer']->id,
            '--expected-amount' => 25000,
            '--expected-invoice' => 'mono-command-invoice-1',
        ])->expectsOutputToContain('Reconciled paid trial purchase')
            ->assertSuccessful();

        $purchase->refresh();
        $this->assertSame('payment_paid', $purchase->status->value);
        $this->assertSame(1, $purchase->customerClassPass()->count());
        $this->assertSame(1, $purchase->customerClassPass()->firstOrFail()->adjustments()->count());
        $this->artisan('payments:reconcile-paid-trial', $baseOptions + [
            '--execute' => true,
            '--expected-purchase' => $purchase->id,
            '--expected-account' => $context['account']->id,
            '--expected-customer' => $context['customer']->id,
            '--expected-amount' => 25000,
            '--expected-invoice' => 'mono-command-invoice-1',
        ])->expectsOutputToContain('was already reconciled with this exact audit record')
            ->assertSuccessful();
        $this->assertSame(1, $purchase->customerClassPass()->firstOrFail()->adjustments()->count());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.monobank.ua/api/merchant/invoice/status?invoiceId=mono-command-invoice-1'
            && $request->hasHeader('X-Token', 'mono-token'));
    }

    public function test_audited_reconciliation_rejects_mismatched_payment_and_unauthorized_actor_without_writes(): void
    {
        [$context, $purchase, $owner] = $this->legacyPurchaseContext('MON-NEGATIVE-1', 'mono-negative-invoice-1');
        $callback = $this->paidCallback($purchase);
        $mismatched = new PaymentCallbackResult(
            orderId: $purchase->order_id,
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: $purchase->amount_cents - 1,
            currency: $purchase->currency,
            gatewayInvoiceId: $purchase->gateway_invoice_id,
            payload: ['amount' => $purchase->amount_cents - 1, 'ccy' => 980],
        );

        $this->assertThrows(
            fn () => app(ReconcilePaidTrialCustomerPurchase::class)->execute($purchase, $mismatched, $owner, 'Mismatch must fail.'),
            InvalidPaymentCallbackException::class,
        );
        $this->assertThrows(
            fn () => app(ReconcilePaidTrialCustomerPurchase::class)->execute(
                $purchase,
                new PaymentCallbackResult(
                    orderId: $purchase->order_id,
                    status: PaymentCallbackStatus::Paid,
                    gatewayStatus: 'success',
                    amountCents: $purchase->amount_cents,
                    currency: $purchase->currency,
                    gatewayInvoiceId: $purchase->gateway_invoice_id,
                    payload: ['finalAmount' => $purchase->amount_cents, 'ccy' => 980],
                ),
                $owner,
                'Missing raw amount must fail.',
            ),
            InvalidPaymentCallbackException::class,
        );
        $this->assertThrows(
            fn () => app(ReconcilePaidTrialCustomerPurchase::class)->execute(
                $purchase,
                new PaymentCallbackResult(
                    orderId: $purchase->order_id,
                    status: PaymentCallbackStatus::Paid,
                    gatewayStatus: 'success',
                    amountCents: $purchase->amount_cents,
                    currency: $purchase->currency,
                    gatewayInvoiceId: $purchase->gateway_invoice_id,
                    payload: ['amount' => $purchase->amount_cents, 'ccy' => 999],
                ),
                $owner,
                'Unknown raw currency must fail.',
            ),
            InvalidPaymentCallbackException::class,
        );
        $this->assertThrows(
            fn () => app(ReconcilePaidTrialCustomerPurchase::class)->execute(
                $purchase,
                $callback,
                User::factory()->create(),
                'Unauthorized actor must fail.',
            ),
            ValidationException::class,
        );

        $this->assertSame('payment_pending', $purchase->fresh()->status->value);
        $this->assertSame(0, CustomerClassPass::whereBelongsTo($context['customer'])->count());
        $this->assertSame(0, CustomerClassPassAdjustment::whereBelongsTo($context['account'])->count());
    }

    public function test_audited_reconciliation_rolls_back_purchase_pass_and_audit_together(): void
    {
        [$context, $purchase, $owner] = $this->legacyPurchaseContext('MON-ROLLBACK-1', 'mono-rollback-invoice-1');
        $this->mock(ReconcileUnreservedCustomerBookingsForIssuedClassPass::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')
                ->once()
                ->andThrow(new RuntimeException('Synthetic audited reconciliation failure.'));
        });

        try {
            app(ReconcilePaidTrialCustomerPurchase::class)->execute(
                $purchase,
                $this->paidCallback($purchase),
                $owner,
                'Rollback every audited trial mutation.',
            );
            $this->fail('The synthetic reconciliation failure must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audited reconciliation failure.', $exception->getMessage());
        }

        $this->assertSame('payment_pending', $purchase->fresh()->status->value);
        $this->assertSame(0, CustomerClassPass::whereBelongsTo($context['customer'])->count());
        $this->assertSame(0, CustomerClassPassAdjustment::whereBelongsTo($context['account'])->count());
    }

    /**
     * @return array{account: Account, customer: Customer, plan: ClassPassPlan, location: Location, room: Room, class_type: ClassType, trainer: Trainer}
     */
    private function context(): array
    {
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $customer = Customer::factory()->for($account)->create();
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
        $trainer = Trainer::factory()->for($account)->create();
        $plan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Trial 250',
            'slug' => 'trial-250',
            'is_trial' => true,
            'price_cents' => 25000,
            'currency' => 'UAH',
            'sessions_count' => 1,
        ]);
        $plan->classTypes()->sync([$classType->id]);

        return [
            'account' => $account,
            'customer' => $customer,
            'plan' => $plan,
            'location' => $location,
            'room' => $room,
            'class_type' => $classType,
            'trainer' => $trainer,
        ];
    }

    /**
     * @param  array{account: Account, customer: Customer, location: Location, room: Room, class_type: ClassType, trainer: Trainer}  $context
     */
    private function booking(array $context): ClassBooking
    {
        $scheduledClass = ScheduledClass::factory()
            ->for($context['account'])
            ->for($context['location'])
            ->for($context['room'])
            ->for($context['class_type'])
            ->for($context['trainer'])
            ->create();

        return ClassBooking::factory()
            ->for($context['account'])
            ->for($scheduledClass)
            ->for($context['customer'])
            ->create();
    }

    private function paidCallback(CustomerPurchase $purchase): PaymentCallbackResult
    {
        return new PaymentCallbackResult(
            orderId: $purchase->order_id,
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: $purchase->amount_cents,
            currency: $purchase->currency,
            gatewayInvoiceId: $purchase->gateway_invoice_id ?? 'mono-invoice-paid-trial',
            paidAt: now(),
            modifiedAt: now(),
            payload: [
                'status' => 'success',
                'amount' => $purchase->amount_cents,
                'ccy' => 980,
                'modifiedDate' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * @return array{0: array{account: Account, customer: Customer, plan: ClassPassPlan, location: Location, room: Room, class_type: ClassType, trainer: Trainer}, 1: CustomerPurchase, 2: User}
     */
    private function legacyPurchaseContext(string $orderId, string $invoiceId): array
    {
        $context = $this->context();
        $owner = User::factory()->create();
        $context['account']->addOwner($owner);
        $this->booking($context);
        $purchase = CustomerPurchase::factory()
            ->for($context['account'])
            ->for($context['customer'])
            ->for($context['plan'], 'classPassPlan')
            ->create([
                'location_id' => $context['location']->id,
                'provider' => IntegrationProvider::Monopay->value,
                'order_id' => $orderId,
                'gateway_invoice_id' => $invoiceId,
                'status' => 'payment_pending',
                'amount_cents' => 25000,
                'plan_name' => $context['plan']->name,
                'plan_slug' => $context['plan']->slug,
                'sessions_count' => 1,
                'trial_eligibility_validated_at' => null,
            ]);

        return [$context, $purchase, $owner];
    }
}
