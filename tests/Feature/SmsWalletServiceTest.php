<?php

namespace Tests\Feature;

use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsTopUpPaymentStatus;
use App\Models\AccountSmsWallet;
use App\Models\SmsDelivery;
use App\Models\SmsTopUpPayment;
use App\Support\Sms\SmsWalletService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SmsWalletServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reservation_and_provider_segment_reconciliation_never_make_balance_negative(): void
    {
        $wallet = AccountSmsWallet::factory()->create(['balance_cents' => 300]);
        $delivery = SmsDelivery::factory()->create([
            'account_id' => $wallet->account_id,
            'account_sms_wallet_id' => $wallet->id,
            'sms_segment_price_cents' => 100,
            'reserved_amount_cents' => 0,
        ]);
        $service = app(SmsWalletService::class);

        $this->assertTrue($service->reserve($delivery, 100));
        $this->assertSame(100, $wallet->refresh()->reserved_cents);

        $delivery = $service->capture($delivery, 4, 240);
        $wallet->refresh();

        $this->assertSame(SmsDeliveryStatus::Accepted, $delivery->status);
        $this->assertSame(4, $delivery->billed_segments);
        $this->assertSame(400, $delivery->amount_cents);
        $this->assertSame(0, $wallet->balance_cents);
        $this->assertSame(0, $wallet->reserved_cents);
        $this->assertSame(100, $wallet->outstanding_cents);
        $this->assertDatabaseHas('sms_wallet_ledger_entries', [
            'account_sms_wallet_id' => $wallet->id,
            'amount_cents' => -400,
            'balance_after_cents' => 0,
            'outstanding_after_cents' => 100,
        ]);
    }

    public function test_explicit_rejection_releases_the_reservation(): void
    {
        $wallet = AccountSmsWallet::factory()->create(['balance_cents' => 500]);
        $delivery = SmsDelivery::factory()->create([
            'account_id' => $wallet->account_id,
            'account_sms_wallet_id' => $wallet->id,
            'sms_segment_price_cents' => 100,
            'reserved_amount_cents' => 0,
        ]);
        $service = app(SmsWalletService::class);

        $this->assertTrue($service->reserve($delivery, 200));
        $service->release($delivery, SmsDeliveryStatus::Failed, 'rejected', 'Rejected.');

        $this->assertSame(0, $wallet->refresh()->reserved_cents);
        $this->assertSame(500, $wallet->balance_cents);
        $this->assertSame(SmsDeliveryStatus::Failed, $delivery->refresh()->status);
    }

    public function test_top_up_credit_and_reversal_are_idempotent_and_record_uncovered_debt(): void
    {
        $wallet = AccountSmsWallet::factory()->create();
        $payment = SmsTopUpPayment::factory()->create([
            'account_id' => $wallet->account_id,
            'account_sms_wallet_id' => $wallet->id,
            'amount_cents' => 5_000,
            'status' => SmsTopUpPaymentStatus::PaymentPaid->value,
        ]);
        $service = app(SmsWalletService::class);

        $service->creditTopUp($payment);
        $service->creditTopUp($payment);
        $this->assertSame(5_000, $wallet->refresh()->balance_cents);

        $wallet->forceFill(['balance_cents' => 1_000])->save();
        $service->reverseTopUp($payment);
        $service->reverseTopUp($payment);

        $this->assertSame(0, $wallet->refresh()->balance_cents);
        $this->assertSame(4_000, $wallet->outstanding_cents);
        $this->assertSame(2, $wallet->ledgerEntries()->count());
    }

    public function test_outstanding_warning_is_cleared_only_after_the_debt_is_fully_settled(): void
    {
        $wallet = AccountSmsWallet::factory()->create([
            'outstanding_cents' => 700,
            'last_outstanding_warning_at' => now(),
        ]);
        $partialPayment = SmsTopUpPayment::factory()->create([
            'account_id' => $wallet->account_id,
            'account_sms_wallet_id' => $wallet->id,
            'amount_cents' => 500,
            'status' => SmsTopUpPaymentStatus::PaymentPaid->value,
        ]);
        $finalPayment = SmsTopUpPayment::factory()->create([
            'account_id' => $wallet->account_id,
            'account_sms_wallet_id' => $wallet->id,
            'amount_cents' => 200,
            'status' => SmsTopUpPaymentStatus::PaymentPaid->value,
        ]);
        $service = app(SmsWalletService::class);

        $service->creditTopUp($partialPayment);

        $this->assertSame(200, $wallet->refresh()->outstanding_cents);
        $this->assertNotNull($wallet->last_outstanding_warning_at);

        $service->creditTopUp($finalPayment);

        $this->assertSame(0, $wallet->refresh()->outstanding_cents);
        $this->assertNull($wallet->last_outstanding_warning_at);
    }
}
