<?php

namespace Tests\Feature;

use App\Enums\AccountPaymentMethodVerificationPurpose;
use App\Enums\SmsDeliveryPurpose;
use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsSendingMode;
use App\Enums\SmsTopUpKind;
use App\Enums\SmsTopUpPaymentStatus;
use App\Enums\SmsWalletLedgerEntryType;
use App\Models\Account;
use App\Models\AccountSmsWallet;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPaymentMethod;
use App\Models\SmsDelivery;
use App\Models\SmsTopUpPayment;
use App\Models\SmsWalletLedgerEntry;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanSmsRateChange;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LogicException;
use Tests\TestCase;

class SmsPersistenceModelsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_new_customer_auth_settings_default_to_sms_disabled(): void
    {
        $account = Account::factory()->create();

        $settings = $account->customerAuthSetting()->create();

        $this->assertSame(SmsSendingMode::Disabled, $settings->sms_sending_mode);
        $this->assertNull($settings->sms_provider);
    }

    public function test_sms_wallet_payment_and_delivery_models_are_account_scoped_and_casted(): void
    {
        $wallet = AccountSmsWallet::factory()->create([
            'balance_cents' => 10_000,
            'reserved_cents' => 280,
        ]);
        $payment = SmsTopUpPayment::factory()->create([
            'account_id' => $wallet->account_id,
            'account_sms_wallet_id' => $wallet->id,
            'kind' => SmsTopUpKind::Automatic->value,
            'status' => SmsTopUpPaymentStatus::PaymentPaid->value,
            'paid_at' => now(),
        ]);
        $delivery = SmsDelivery::factory()->create([
            'account_id' => $wallet->account_id,
            'account_sms_wallet_id' => $wallet->id,
            'purpose' => SmsDeliveryPurpose::CustomerOtp->value,
            'status' => SmsDeliveryStatus::Accepted->value,
            'source_mode' => SmsSendingMode::LadnaService->value,
        ]);

        $this->assertSame(9_720, $wallet->spendableBalanceCents());
        $this->assertTrue($payment->account->is($wallet->account));
        $this->assertTrue($payment->wallet->is($wallet));
        $this->assertSame(SmsTopUpKind::Automatic, $payment->kind);
        $this->assertSame(SmsTopUpPaymentStatus::PaymentPaid, $payment->status);
        $this->assertSame(SmsDeliveryPurpose::CustomerOtp, $delivery->purpose);
        $this->assertSame(SmsDeliveryStatus::Accepted, $delivery->status);
        $this->assertSame(SmsSendingMode::LadnaService, $delivery->source_mode);
    }

    public function test_wallet_ledger_entries_are_append_only(): void
    {
        $entry = SmsWalletLedgerEntry::factory()->create([
            'type' => SmsWalletLedgerEntryType::TopUp->value,
        ]);

        $this->assertSame(SmsWalletLedgerEntryType::TopUp, $entry->type);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SMS wallet ledger entries are append-only.');

        $entry->update(['reason' => 'Changed']);
    }

    public function test_tariff_rate_changes_are_append_only_and_keep_nullable_rate_snapshots(): void
    {
        $plan = SubscriptionPlan::factory()->create([
            'sms_segment_price_cents' => 140,
        ]);
        $change = SubscriptionPlanSmsRateChange::factory()
            ->for($plan, 'plan')
            ->create([
                'old_sms_segment_price_cents' => null,
                'new_sms_segment_price_cents' => 140,
            ]);

        $this->assertSame(140, $plan->sms_segment_price_cents);
        $this->assertTrue($change->plan->is($plan));
        $this->assertNull($change->old_sms_segment_price_cents);
        $this->assertSame(140, $change->new_sms_segment_price_cents);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Subscription plan SMS rate changes cannot be deleted.');

        $change->delete();
    }

    public function test_payment_method_verification_can_target_an_sms_top_up(): void
    {
        $account = Account::factory()->create();
        $subscription = AccountSubscription::factory()
            ->for($account)
            ->create();
        $paymentMethod = AccountSubscriptionPaymentMethod::factory()
            ->for($subscription, 'subscription')
            ->create([
                'account_id' => $account->id,
                'verification_purpose' => AccountPaymentMethodVerificationPurpose::SmsTopUp->value,
                'verification_amount_cents' => 10_000,
            ]);

        $this->assertSame(
            AccountPaymentMethodVerificationPurpose::SmsTopUp,
            $paymentMethod->verification_purpose,
        );
        $this->assertSame(10_000, $paymentMethod->verification_amount_cents);
    }
}
