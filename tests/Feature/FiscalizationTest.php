<?php

namespace Tests\Feature;

use App\Actions\CompleteEventOrder;
use App\Actions\CreateEventOrder;
use App\Actions\Festivals\CompleteFestivalEditionPurchase;
use App\Actions\Payments\CompleteCustomerPurchase;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\FestivalPaymentStatus;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FiscalReceiptStatus;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\AccountSmsWallet;
use App\Models\AccountSubscriptionPayment;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\Event;
use App\Models\EventTicketType;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalEntry;
use App\Models\FestivalPaymentAttempt;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\FestivalTariffPackage;
use App\Models\FestivalTicketOrder;
use App\Models\FiscalReceipt;
use App\Models\IntegrationSetting;
use App\Models\SmsTopUpPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Fiscalization\FiscalReceiptService;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\SaasBilling\CompleteAccountSubscriptionPayment;
use App\Support\Sms\CompleteSmsTopUpPayment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FiscalizationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::preventStrayRequests();
    }

    public function test_customer_purchase_success_callback_fiscalizes_paid_payment(): void
    {
        $account = Account::factory()->create();
        $this->enableAccountFiscalization($account);
        $purchase = $this->customerPurchase($account);
        $this->fakeCheckboxSuccess('FN-CUSTOMER-1');

        $completed = app(CompleteCustomerPurchase::class)->execute(
            $purchase,
            $this->paidCallback($purchase->order_id, $purchase->amount_cents, $purchase->currency),
        );

        $receipt = $completed->fiscalReceipt()->first();

        $this->assertSame(CustomerPurchaseStatus::PaymentPaid, $completed->status);
        $this->assertNotNull($completed->customer_class_pass_id);
        $this->assertNotNull($receipt);
        $this->assertSame(FiscalReceiptStatus::Fiscalized, $receipt->status);
        $this->assertSame('FN-CUSTOMER-1', $receipt->fiscal_number);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/cashier/signin'
            && $request->data() === [
                'login' => 'cashier-login',
                'password' => 'cashier-password',
            ]
            && ! $request->hasHeader('X-Client-Name')
            && ! $request->hasHeader('X-Client-Version'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/shifts'
            && $request->hasHeader('X-License-Key', 'license-key'));
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/cashier/signinPinCode');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/receipts/sell');
    }

    public function test_checkbox_failure_does_not_block_successful_customer_purchase(): void
    {
        $account = Account::factory()->create();
        $this->enableAccountFiscalization($account);
        $purchase = $this->customerPurchase($account);
        $this->fakeCheckboxFailure('Cashier shift is closed.');

        $completed = app(CompleteCustomerPurchase::class)->execute(
            $purchase,
            $this->paidCallback($purchase->order_id, $purchase->amount_cents, $purchase->currency),
        );

        $receipt = $completed->fiscalReceipt()->first();

        $this->assertSame(CustomerPurchaseStatus::PaymentPaid, $completed->status);
        $this->assertNotNull($completed->customer_class_pass_id);
        $this->assertNotNull($receipt);
        $this->assertSame(FiscalReceiptStatus::Failed, $receipt->status);
        $this->assertSame('Cashier shift is closed.', $receipt->last_error);
    }

    public function test_saas_payment_success_callback_uses_platform_fiscalization_settings(): void
    {
        $this->enablePlatformFiscalization();
        $account = Account::factory()->create();
        $owner = User::factory()->create(['email' => 'saas-receipt-owner@example.com']);
        $account->addOwner($owner);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Studio Pro']);
        $payment = AccountSubscriptionPayment::factory()
            ->for($account)
            ->for($plan, 'plan')
            ->create([
                'payment_type' => AccountSubscriptionPaymentType::ManualRenewal->value,
                'amount_cents' => 250000,
                'currency' => 'UAH',
            ]);
        $this->fakeCheckboxSuccess('FN-SAAS-1');

        $completed = app(CompleteAccountSubscriptionPayment::class)->execute(
            $payment,
            $this->paidCallback($payment->order_id, $payment->amount_cents, $payment->currency),
        );

        $receipt = $completed->fiscalReceipt()->first();

        $this->assertTrue($completed->isPaid());
        $this->assertNotNull($receipt);
        $this->assertSame(IntegrationScope::Platform, $receipt->scope_type);
        $this->assertSame(0, $receipt->scope_id);
        $this->assertSame($account->id, $receipt->account_id);
        $this->assertSame('FN-SAAS-1', $receipt->fiscal_number);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/receipts/sell'
            && data_get($request->data(), 'delivery.email') === 'saas-receipt-owner@example.com');
    }

    public function test_sms_top_up_credits_once_and_retries_the_platform_receipt_after_checkbox_outage(): void
    {
        $this->enablePlatformFiscalization();
        $account = Account::factory()->create();
        $owner = User::factory()->create(['email' => 'sms-receipt-owner@example.com']);
        $account->addOwner($owner);
        $wallet = AccountSmsWallet::factory()->for($account)->create();
        $payment = SmsTopUpPayment::factory()
            ->for($account)
            ->for($wallet, 'wallet')
            ->create([
                'amount_cents' => 5_000,
                'currency' => 'UAH',
            ]);
        $this->fakeCheckboxFailureThenSuccess(
            'Checkbox is temporarily unavailable.',
            'FN-SMS-TOP-UP',
        );

        $completed = app(CompleteSmsTopUpPayment::class)->execute(
            $payment,
            $this->paidCallback($payment->order_id, $payment->amount_cents, $payment->currency),
        );
        $failedReceipt = $completed->fiscalReceipt()->firstOrFail();

        $this->assertTrue($completed->isPaid());
        $this->assertSame(5_000, $wallet->refresh()->balance_cents);
        $this->assertSame(FiscalReceiptStatus::Failed, $failedReceipt->status);
        $this->assertSame(1, $payment->ledgerEntries()->count());

        $fiscalReceipts = app(FiscalReceiptService::class);
        $this->assertNull($fiscalReceipts->skipReasonFor($completed->fresh()));
        $this->assertNotNull($fiscalReceipts->fiscalizeSmsTopUpPayment($completed->fresh()));

        $retriedReceipt = $completed->fiscalReceipt()->firstOrFail();
        $this->assertSame($failedReceipt->id, $retriedReceipt->id);
        $this->assertSame(
            FiscalReceiptStatus::Fiscalized,
            $retriedReceipt->status,
            (string) $retriedReceipt->last_error,
        );
        $this->assertSame('FN-SMS-TOP-UP', $retriedReceipt->fiscal_number);
        $this->assertSame(1, $completed->fiscalReceipts()->count());
        $this->assertSame(5_000, $wallet->refresh()->balance_cents);
        $this->assertSame(1, $payment->ledgerEntries()->count());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/receipts/sell'
            && data_get($request->data(), 'goods.0.good.name') === __('app.sms_top_up_receipt_item')
            && data_get($request->data(), 'goods.0.good.price') === 5_000
            && data_get($request->data(), 'delivery.email') === 'sms-receipt-owner@example.com');
    }

    public function test_paid_festival_purchase_uses_platform_fiscalization_and_owner_delivery(): void
    {
        $this->enablePlatformFiscalization();
        $account = Account::factory()->create();
        $owner = User::factory()->create(['email' => 'festival-receipt-owner@example.com']);
        $account->addOwner($owner);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Festival Studio']);
        $package = FestivalTariffPackage::factory()->create([
            'subscription_plan_id' => $plan->id,
            'name' => 'Festival M '.str()->random(8),
        ]);
        $purchase = FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $plan->id,
            'festival_tariff_package_id' => $package->id,
            'created_by_user_id' => $owner->id,
            'status' => 'payment_pending',
            'amount_cents' => 300000,
            'currency' => 'UAH',
            'paid_at' => null,
        ]);
        $this->fakeCheckboxSuccess('FN-FESTIVAL-1');

        $completed = app(CompleteFestivalEditionPurchase::class)->execute(
            $purchase,
            $this->paidCallback((string) $purchase->order_id, $purchase->amount_cents, $purchase->currency),
        );
        $receipt = $completed->fiscalReceipt()->firstOrFail();

        $this->assertSame('available', $completed->status->value);
        $this->assertSame(IntegrationScope::Platform, $receipt->scope_type);
        $this->assertSame('FN-FESTIVAL-1', $receipt->fiscal_number);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/receipts/sell'
            && data_get($request->data(), 'goods.0.good.name') === 'Ladna Festival · '.$package->name
            && data_get($request->data(), 'delivery.email') === 'festival-receipt-owner@example.com');
    }

    public function test_paid_festival_entry_charge_is_fiscalized_for_the_applicant(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $this->enableAccountFiscalization($account);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'email' => 'festival-applicant@example.com',
            'phone' => '+380501112233',
        ]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
        ]);
        $charge = $entry->charges()->create([
            'account_id' => $account->id,
            'code' => 'FCH-FISCAL-'.$entry->id,
            'kind' => 'qualification',
            'name' => 'Festival qualification fee',
            'amount_cents' => 25000,
            'currency' => 'UAH',
        ]);
        $attempt = FestivalPaymentAttempt::query()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $charge->id,
            'provider' => IntegrationProvider::Monopay->value,
            'order_id' => 'FCHP-FISCAL-'.$entry->id,
            'amount_cents' => 25000,
            'currency' => 'UAH',
            'expires_at' => now()->addMinutes(30),
        ]);
        $this->fakeCheckboxSuccess('FN-FESTIVAL-ENTRY-1');

        $completed = app(FestivalPaymentService::class)->completeAttempt(
            $attempt,
            $this->paidCallback($attempt->order_id, $attempt->amount_cents, $attempt->currency),
        );

        $receipt = $completed->fiscalReceipt()->firstOrFail();
        $this->assertSame(FiscalReceiptStatus::Fiscalized, $receipt->status);
        $this->assertSame('FN-FESTIVAL-ENTRY-1', $receipt->fiscal_number);
        $this->assertSame(IntegrationScope::Account, $receipt->scope_type);
        $this->assertSame($account->id, $receipt->scope_id);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/receipts/sell'
            && data_get($request->data(), 'goods.0.good.name') === 'Festival qualification fee'
            && data_get($request->data(), 'delivery.email') === 'festival-applicant@example.com'
            && data_get($request->data(), 'delivery.phone') === '+380501112233');
    }

    public function test_paid_event_order_is_fiscalized_with_event_and_buyer_snapshots(): void
    {
        $account = Account::factory()->create();
        $this->enableAccountFiscalization($account);
        $event = Event::factory()->published()->for($account)->create(['title' => 'Summer Workshop']);
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'price_cents' => 65000,
            'inventory' => 10,
        ]);
        $order = app(CreateEventOrder::class)->execute($event, [
            'buyer_name' => 'Ticket Buyer',
            'buyer_email' => 'ticket-buyer@example.com',
            'buyer_phone' => '+380501112233',
            'provider' => IntegrationProvider::Liqpay->value,
            'items' => [['ticket_type_id' => $ticketType->id, 'quantity' => 1]],
            'accept_terms' => true,
        ], 'en');
        $this->fakeCheckboxSuccess('FN-EVENT-1');

        $completed = app(CompleteEventOrder::class)->execute(
            $order,
            $this->paidCallback($order->order_id, $order->amount_cents, $order->currency),
        );
        $receipt = $completed->fiscalReceipt()->first();

        $this->assertNotNull($receipt);
        $this->assertSame(FiscalReceiptStatus::Fiscalized, $receipt->status);
        $this->assertSame(IntegrationScope::Account, $receipt->scope_type);
        $this->assertSame($account->id, $receipt->scope_id);
        $this->assertSame('FN-EVENT-1', $receipt->fiscal_number);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/receipts/sell'
            && data_get($request->data(), 'goods.0.good.name') === 'Summer Workshop'
            && data_get($request->data(), 'delivery.email') === 'ticket-buyer@example.com'
            && data_get($request->data(), 'delivery.phone') === '+380501112233');
    }

    public function test_paid_festival_admission_is_fiscalized_once_with_frozen_items_and_buyer_delivery(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $this->enableAccountFiscalization($account);
        $edition = FestivalEdition::factory()
            ->published()
            ->for(FestivalSeries::factory()->for($account))
            ->create(['account_id' => $account->id, 'title' => 'Admission Festival']);
        $balcony = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Mutable balcony name',
            'inventory' => 10,
            'price_cents' => 45000,
        ]);
        $floor = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Mutable floor name',
            'inventory' => 10,
            'price_cents' => 30000,
        ]);
        $order = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'provider' => IntegrationProvider::Liqpay->value,
            'buyer_name' => 'Festival Buyer',
            'buyer_email' => 'festival-ticket-buyer@example.com',
            'buyer_phone' => '+380501112233',
            'amount_cents' => 120000,
            'currency' => 'UAH',
        ]);
        $order->items()->createMany([
            [
                'account_id' => $account->id,
                'festival_admission_type_id' => $balcony->id,
                'admission_name' => 'Frozen balcony',
                'unit_price_cents' => 45000,
                'quantity' => 2,
                'total_cents' => 90000,
            ],
            [
                'account_id' => $account->id,
                'festival_admission_type_id' => $floor->id,
                'admission_name' => 'Frozen floor',
                'unit_price_cents' => 30000,
                'quantity' => 1,
                'total_cents' => 30000,
            ],
        ]);
        $this->fakeCheckboxSuccess('FN-FESTIVAL-ADMISSION-1');

        $completed = app(FestivalPaymentService::class)->completeOrder(
            $order,
            $this->paidCallback($order->order_id, $order->amount_cents, $order->currency),
        );
        app(FestivalPaymentService::class)->completeOrder(
            $completed,
            $this->paidCallback($order->order_id, $order->amount_cents, $order->currency),
        );

        $receipt = $completed->fiscalReceipt()->firstOrFail();
        $this->assertSame(FestivalTicketOrderStatus::Paid, $completed->status);
        $this->assertSame(3, $completed->tickets()->count());
        $this->assertSame(FiscalReceiptStatus::Fiscalized, $receipt->status);
        $this->assertSame(IntegrationScope::Account, $receipt->scope_type);
        $this->assertSame($account->id, $receipt->scope_id);
        $this->assertSame('FN-FESTIVAL-ADMISSION-1', $receipt->fiscal_number);
        $this->assertSame(1, $completed->fiscalReceipts()->count());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/receipts/sell'
            && data_get($request->data(), 'goods.0.good.name') === 'Frozen balcony'
            && data_get($request->data(), 'goods.0.good.price') === 45000
            && data_get($request->data(), 'goods.0.quantity') === 2000
            && data_get($request->data(), 'goods.1.good.name') === 'Frozen floor'
            && data_get($request->data(), 'goods.1.quantity') === 1000
            && data_get($request->data(), 'payments.0.value') === 120000
            && data_get($request->data(), 'total_sum') === 120000
            && data_get($request->data(), 'delivery.email') === 'festival-ticket-buyer@example.com'
            && data_get($request->data(), 'delivery.phone') === '+380501112233');
        $this->assertCount(1, collect(Http::recorded())->filter(fn (array $record): bool => $record[0]->url() === 'https://api.checkbox.ua/api/v1/receipts/sell'));
    }

    public function test_zero_value_festival_admission_issues_tickets_without_a_fiscal_receipt(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $this->enableAccountFiscalization($account);
        $edition = FestivalEdition::factory()
            ->published()
            ->for(FestivalSeries::factory()->for($account))
            ->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'inventory' => 10,
            'price_cents' => 0,
        ]);
        $order = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'amount_cents' => 0,
            'provider' => IntegrationProvider::Liqpay->value,
        ]);
        $order->items()->create([
            'account_id' => $account->id,
            'festival_admission_type_id' => $type->id,
            'admission_name' => 'Free admission',
            'unit_price_cents' => 0,
            'quantity' => 1,
            'total_cents' => 0,
        ]);

        $completed = app(FestivalPaymentService::class)->completeOrder(
            $order,
            $this->paidCallback($order->order_id, 0, $order->currency),
        );

        $this->assertSame(FestivalTicketOrderStatus::Paid, $completed->status);
        $this->assertSame(1, $completed->tickets()->count());
        $this->assertNull($completed->fiscalReceipt()->first());
        Http::assertNothingSent();
    }

    public function test_fiscalization_command_recovers_an_eligible_paid_festival_admission(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $this->enableAccountFiscalization($account);
        $edition = FestivalEdition::factory()
            ->published()
            ->for(FestivalSeries::factory()->for($account))
            ->create(['account_id' => $account->id]);
        $type = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id]);
        $order = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'status' => FestivalTicketOrderStatus::Paid->value,
            'provider' => IntegrationProvider::Liqpay->value,
            'amount_cents' => 30000,
            'paid_at' => now(),
            'expires_at' => null,
        ]);
        $order->items()->create([
            'account_id' => $account->id,
            'festival_admission_type_id' => $type->id,
            'admission_name' => 'Recovery admission',
            'unit_price_cents' => 30000,
            'quantity' => 1,
            'total_cents' => 30000,
        ]);
        $this->fakeCheckboxSuccess('FN-FESTIVAL-RECOVERY-1');

        $this->artisan('payments:fiscalize', ['account' => $account->id])
            ->expectsOutputToContain("[festival-admission] #{$order->id} {$order->order_id}: fiscalized (FN-FESTIVAL-RECOVERY-1).")
            ->assertExitCode(0);

        $this->assertSame('FN-FESTIVAL-RECOVERY-1', $order->fiscalReceipt()->firstOrFail()->fiscal_number);
    }

    public function test_fiscalization_command_recovers_an_eligible_paid_festival_entry_charge(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $this->enableAccountFiscalization($account);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create([
            'email' => 'festival-recovery@example.com',
        ]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
        ]);
        $charge = $entry->charges()->create([
            'account_id' => $account->id,
            'code' => 'FCH-FISCAL-RECOVERY-'.$entry->id,
            'kind' => 'qualification',
            'name' => 'Festival recovery fee',
            'amount_cents' => 25000,
            'currency' => 'UAH',
            'status' => 'paid',
            'paid_at' => now(),
        ]);
        $attempt = FestivalPaymentAttempt::query()->create([
            'account_id' => $account->id,
            'festival_charge_id' => $charge->id,
            'provider' => IntegrationProvider::Monopay->value,
            'order_id' => 'FCHP-FISCAL-RECOVERY-'.$entry->id,
            'status' => FestivalPaymentStatus::Paid->value,
            'amount_cents' => 25000,
            'currency' => 'UAH',
            'paid_at' => now(),
        ]);
        $this->fakeCheckboxSuccess('FN-FESTIVAL-ENTRY-RECOVERY-1');

        $this->artisan('payments:fiscalize', ['account' => $account->id])
            ->expectsOutputToContain("[festival-entry] #{$attempt->id} {$attempt->order_id}: fiscalized (FN-FESTIVAL-ENTRY-RECOVERY-1).")
            ->assertExitCode(0);

        $this->assertSame('FN-FESTIVAL-ENTRY-RECOVERY-1', $attempt->fiscalReceipt()->firstOrFail()->fiscal_number);
    }

    public function test_command_fiscalizes_eligible_paid_payments_for_account(): void
    {
        $account = Account::factory()->create();
        $this->enableAccountFiscalization($account);
        $purchase = $this->customerPurchase($account, [
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'paid_at' => now(),
        ]);
        $this->fakeCheckboxSuccess('FN-COMMAND-1');

        $this->artisan('payments:fiscalize', ['account' => $account->id])
            ->expectsOutputToContain("[customer] #{$purchase->id} {$purchase->order_id}: fiscalized (FN-COMMAND-1).")
            ->assertExitCode(0);

        $receipt = FiscalReceipt::query()
            ->whereMorphedTo('payment', $purchase)
            ->first();

        $this->assertNotNull($receipt);
        $this->assertSame(FiscalReceiptStatus::Fiscalized, $receipt->status);
    }

    public function test_partial_customer_refund_uses_checkbox_return_payload_after_source_receipt(): void
    {
        $account = Account::factory()->create();
        $this->enableAccountFiscalization($account);
        $purchase = $this->customerPurchase($account, [
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'paid_at' => now(),
        ]);
        FiscalReceipt::factory()
            ->forAccountScope($account)
            ->for($purchase, 'payment')
            ->fiscalized('FN-SOURCE-1')
            ->create();
        $refund = CustomerPurchaseRefund::factory()
            ->for($account)
            ->for($purchase, 'customerPurchase')
            ->create([
                'method' => CustomerPurchaseRefund::MethodCashless,
                'amount_cents' => 35000,
                'currency' => 'UAH',
            ]);
        $this->fakeCheckboxSuccess('FN-REFUND-1');

        $receipt = app(FiscalReceiptService::class)->fiscalizeCustomerPurchaseRefund($refund);

        $this->assertNotNull($receipt);
        $this->assertSame(FiscalReceiptStatus::Fiscalized, $receipt->status);
        $this->assertSame('FN-REFUND-1', $receipt->fiscal_number);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/receipts/sell'
            && data_get($request->data(), 'goods.0.is_return') === true
            && data_get($request->data(), 'goods.0.good.code') === $purchase->order_id
            && data_get($request->data(), 'goods.0.good.price') === 35000
            && data_get($request->data(), 'payments.0.type') === 'CASHLESS'
            && data_get($request->data(), 'payments.0.value') === 35000
            && data_get($request->data(), 'total_sum') === 35000);
    }

    public function test_failed_refund_receipt_is_retried_without_duplicate_receipt(): void
    {
        $account = Account::factory()->create();
        $this->enableAccountFiscalization($account);
        $purchase = $this->customerPurchase($account, [
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'paid_at' => now(),
        ]);
        FiscalReceipt::factory()
            ->forAccountScope($account)
            ->for($purchase, 'payment')
            ->fiscalized('FN-SOURCE-2')
            ->create();
        $refund = CustomerPurchaseRefund::factory()
            ->for($account)
            ->for($purchase, 'customerPurchase')
            ->create([
                'method' => CustomerPurchaseRefund::MethodCash,
                'amount_cents' => 10000,
                'currency' => 'UAH',
            ]);
        $this->fakeCheckboxFailureThenSuccess('Return receipt failed.', 'FN-REFUND-RETRY');

        $failedReceipt = app(FiscalReceiptService::class)->fiscalizeCustomerPurchaseRefund($refund);

        $this->assertNotNull($failedReceipt);
        $this->assertSame(FiscalReceiptStatus::Failed, $failedReceipt->status);
        $this->assertSame(1, $refund->fiscalReceipts()->count());
        $this->assertSame(1, $failedReceipt->attempts);

        $retriedReceipt = app(FiscalReceiptService::class)->fiscalizeCustomerPurchaseRefund($refund->fresh());

        $this->assertNotNull($retriedReceipt);
        $this->assertSame($failedReceipt->id, $retriedReceipt->id);
        $this->assertSame(FiscalReceiptStatus::Fiscalized, $retriedReceipt->status);
        $this->assertSame(2, $retriedReceipt->attempts);
        $this->assertSame(1, $refund->fiscalReceipts()->count());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/receipts/sell'
            && data_get($request->data(), 'payments.0.type') === 'CASH');
    }

    public function test_refund_without_successful_source_receipt_is_not_fiscalized(): void
    {
        $account = Account::factory()->create();
        $this->enableAccountFiscalization($account);
        $purchase = $this->customerPurchase($account, [
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'paid_at' => now(),
        ]);
        $refund = CustomerPurchaseRefund::factory()
            ->for($account)
            ->for($purchase, 'customerPurchase')
            ->create();

        $receipt = app(FiscalReceiptService::class)->fiscalizeCustomerPurchaseRefund($refund);

        $this->assertNull($receipt);
        $this->assertSame(0, $refund->fiscalReceipts()->count());
        Http::assertNothingSent();
    }

    public function test_fiscalization_command_processes_source_payment_before_its_refund(): void
    {
        $account = Account::factory()->create();
        $this->enableAccountFiscalization($account);
        $purchase = $this->customerPurchase($account, [
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'paid_at' => now(),
        ]);
        $refund = CustomerPurchaseRefund::factory()
            ->for($account)
            ->for($purchase, 'customerPurchase')
            ->create([
                'method' => CustomerPurchaseRefund::MethodCashless,
                'amount_cents' => 15000,
                'currency' => 'UAH',
            ]);
        $this->fakeCheckboxSuccess('FN-COMMAND-RETURN');

        $this->artisan('payments:fiscalize', ['account' => $account->id])
            ->assertExitCode(0);

        $this->assertSame(FiscalReceiptStatus::Fiscalized, $purchase->fiscalReceipt()->firstOrFail()->status);
        $this->assertSame(FiscalReceiptStatus::Fiscalized, $refund->fiscalReceipt()->firstOrFail()->status);
        $this->assertSame(2, FiscalReceipt::query()->whereBelongsTo($account)->count());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/receipts/sell'
            && data_get($request->data(), 'goods.0.is_return') === false);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.checkbox.ua/api/v1/receipts/sell'
            && data_get($request->data(), 'goods.0.is_return') === true);
    }

    private function customerPurchase(Account $account, array $attributes = []): CustomerPurchase
    {
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Payment Client',
            'email' => 'payment-client@example.com',
            'phone' => '+380501119900',
        ]);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Group 8 classes',
            'price_cents' => 180000,
            'currency' => 'UAH',
            'sessions_count' => 8,
        ]);

        return CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->create([
                'plan_name' => $plan->name,
                'plan_slug' => $plan->slug,
                'amount_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'sessions_count' => $plan->sessions_count,
                ...$attributes,
            ]);
    }

    private function paidCallback(string $orderId, int $amountCents, string $currency): PaymentCallbackResult
    {
        return new PaymentCallbackResult(
            orderId: $orderId,
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: $amountCents,
            currency: $currency,
            gatewayInvoiceId: 'gateway-invoice-1',
            gatewayPaymentId: 'gateway-payment-1',
            paidAt: now(),
            payload: ['status' => 'success'],
        );
    }

    private function enableAccountFiscalization(Account $account): void
    {
        $this->enableFiscalizationSetting(IntegrationScope::Account, IntegrationProvider::LadnaFiscalization, $account);
        $this->enableFiscalizationSetting(IntegrationScope::Account, IntegrationProvider::Checkbox, $account, $this->checkboxCredentials());
    }

    private function enablePlatformFiscalization(): void
    {
        $this->enableFiscalizationSetting(IntegrationScope::Platform, IntegrationProvider::LadnaFiscalization);
        $this->enableFiscalizationSetting(IntegrationScope::Platform, IntegrationProvider::Checkbox, credentials: $this->checkboxCredentials());
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function enableFiscalizationSetting(
        IntegrationScope $scope,
        IntegrationProvider $provider,
        ?Account $account = null,
        array $credentials = [],
    ): void {
        IntegrationSetting::updateOrCreate(
            [
                'scope_type' => $scope->value,
                'scope_id' => $scope === IntegrationScope::Account ? $account?->id : 0,
                'provider' => $provider->value,
            ],
            [
                'account_id' => $account?->id,
                'category' => IntegrationCategory::Fiscalization->value,
                'is_enabled' => true,
                'credentials' => $credentials,
            ],
        );
    }

    /**
     * @return array<string, string>
     */
    private function checkboxCredentials(): array
    {
        return [
            'license_key' => 'license-key',
            'cashier_login' => 'cashier-login',
            'cashier_password' => 'cashier-password',
        ];
    }

    private function fakeCheckboxSuccess(string $fiscalNumber): void
    {
        $shiftId = '11111111-1111-1111-1111-111111111111';
        $receiptId = '22222222-2222-2222-2222-222222222222';

        Http::fake([
            'https://api.checkbox.ua/api/v1/cashier/signin' => Http::response(['access_token' => 'checkbox-token']),
            'https://api.checkbox.ua/api/v1/cashier/shift' => Http::response(['message' => 'No opened shift'], 422),
            'https://api.checkbox.ua/api/v1/shifts' => Http::response([
                'id' => $shiftId,
                'status' => 'OPENING',
            ], 202),
            'https://api.checkbox.ua/api/v1/shifts/'.$shiftId => Http::response([
                'id' => $shiftId,
                'status' => 'OPENED',
            ]),
            'https://api.checkbox.ua/api/v1/receipts/sell' => Http::response([
                'id' => $receiptId,
                'status' => 'CREATED',
            ], 201),
            'https://api.checkbox.ua/api/v1/receipts/'.$receiptId => Http::response([
                'id' => $receiptId,
                'status' => 'DONE',
                'fiscal_code' => $fiscalNumber,
            ]),
        ]);
    }

    private function fakeCheckboxFailure(string $message): void
    {
        $shiftId = '11111111-1111-1111-1111-111111111111';

        Http::fake([
            'https://api.checkbox.ua/api/v1/cashier/signin' => Http::response(['access_token' => 'checkbox-token']),
            'https://api.checkbox.ua/api/v1/cashier/shift' => Http::response([
                'id' => $shiftId,
                'status' => 'OPENED',
            ]),
            'https://api.checkbox.ua/api/v1/receipts/sell' => Http::response([
                'id' => '22222222-2222-2222-2222-222222222222',
                'status' => 'ERROR',
                'message' => $message,
            ], 422),
        ]);
    }

    private function fakeCheckboxFailureThenSuccess(string $message, string $fiscalNumber): void
    {
        $shiftId = '11111111-1111-1111-1111-111111111111';
        $receiptId = '22222222-2222-2222-2222-222222222222';

        Http::fake([
            'https://api.checkbox.ua/api/v1/cashier/signin' => Http::response(['access_token' => 'checkbox-token']),
            'https://api.checkbox.ua/api/v1/cashier/shift' => Http::response([
                'id' => $shiftId,
                'status' => 'OPENED',
            ]),
            'https://api.checkbox.ua/api/v1/receipts/sell' => Http::sequence()
                ->push([
                    'id' => $receiptId,
                    'status' => 'ERROR',
                    'message' => $message,
                ], 422)
                ->push([
                    'id' => $receiptId,
                    'status' => 'ERROR',
                    'message' => $message,
                ], 422)
                ->push([
                    'id' => $receiptId,
                    'status' => 'ERROR',
                    'message' => $message,
                ], 422)
                ->push([
                    'id' => $receiptId,
                    'status' => 'CREATED',
                ], 201),
            'https://api.checkbox.ua/api/v1/receipts/'.$receiptId => Http::response([
                'id' => $receiptId,
                'status' => 'DONE',
                'fiscal_code' => $fiscalNumber,
            ]),
        ]);
    }
}
