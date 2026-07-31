<?php

namespace App\Support\Sms;

use App\Enums\SmsDeliveryStatus;
use App\Enums\SmsWalletLedgerEntryType;
use App\Models\Account;
use App\Models\AccountSmsWallet;
use App\Models\SmsDelivery;
use App\Models\SmsTopUpPayment;
use App\Models\SmsWalletLedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SmsWalletService
{
    public function __construct(private readonly AccountSmsPricing $pricing) {}

    public function walletFor(Account $account): AccountSmsWallet
    {
        return $account->smsWallet()->firstOrCreate(
            ['account_id' => $account->id],
            ['currency' => 'UAH'],
        );
    }

    public function canReserve(AccountSmsWallet $wallet, int $amountCents): bool
    {
        return $wallet->outstanding_cents === 0
            && $wallet->spendableBalanceCents() >= $amountCents;
    }

    public function reserve(SmsDelivery $delivery, int $amountCents): bool
    {
        if ($amountCents === 0) {
            $delivery->forceFill([
                'status' => SmsDeliveryStatus::Reserved,
                'reserved_amount_cents' => 0,
                'reserved_at' => now(),
            ])->save();

            return true;
        }

        return DB::transaction(function () use ($delivery, $amountCents): bool {
            $wallet = AccountSmsWallet::query()
                ->whereKey($delivery->account_sms_wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->canReserve($wallet, $amountCents)) {
                return false;
            }

            $wallet->forceFill([
                'reserved_cents' => $wallet->reserved_cents + $amountCents,
            ])->save();

            $delivery->forceFill([
                'status' => SmsDeliveryStatus::Reserved,
                'reserved_amount_cents' => $amountCents,
                'reserved_at' => now(),
            ])->save();

            return true;
        }, attempts: 3);
    }

    public function capture(
        SmsDelivery $delivery,
        int $billedSegments,
        ?int $wholesaleCostCents = null,
    ): SmsDelivery {
        return DB::transaction(function () use ($delivery, $billedSegments, $wholesaleCostCents): SmsDelivery {
            $delivery = SmsDelivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();

            if ($delivery->status === SmsDeliveryStatus::Accepted || $delivery->status === SmsDeliveryStatus::Delivered) {
                return $delivery;
            }

            $wallet = $delivery->wallet()->lockForUpdate()->first();
            $rateCents = (int) ($delivery->sms_segment_price_cents ?? 0);
            $amountCents = $rateCents * max(1, $billedSegments);

            if ($wallet) {
                $remainingReservedCents = max(0, $wallet->reserved_cents - $delivery->reserved_amount_cents);
                $availableForCaptureCents = max(0, $wallet->balance_cents - $remainingReservedCents);
                $capturedCents = min($amountCents, $availableForCaptureCents);
                $shortfallCents = max(0, $amountCents - $capturedCents);

                $wallet->forceFill([
                    'balance_cents' => $wallet->balance_cents - $capturedCents,
                    'reserved_cents' => $remainingReservedCents,
                    'outstanding_cents' => $wallet->outstanding_cents + $shortfallCents,
                ])->save();

                $this->appendLedgerEntry(
                    wallet: $wallet,
                    type: SmsWalletLedgerEntryType::Usage,
                    amountCents: -$amountCents,
                    reference: $delivery,
                    reason: $shortfallCents > 0
                        ? 'provider_segment_reconciliation_shortfall'
                        : 'sms_delivery_usage',
                    idempotencyKey: 'sms-delivery-usage:'.$delivery->id,
                );

                if ($shortfallCents > 0) {
                    report(new \RuntimeException(
                        "SMS delivery {$delivery->id} created {$shortfallCents} cents of reconciliation debt for account {$delivery->account_id}.",
                    ));
                }
            }

            $delivery->forceFill([
                'status' => SmsDeliveryStatus::Accepted,
                'billed_segments' => max(1, $billedSegments),
                'amount_cents' => $amountCents,
                'wholesale_cost_cents' => $wholesaleCostCents,
                'accepted_at' => now(),
                'next_status_check_at' => now()->addMinutes(5),
                'status_polling_expires_at' => now()->addHours(48),
                'last_error' => null,
                'error_code' => null,
            ])->save();

            return $delivery->refresh();
        }, attempts: 3);
    }

    public function release(
        SmsDelivery $delivery,
        SmsDeliveryStatus $status,
        ?string $errorCode = null,
        ?string $error = null,
    ): SmsDelivery {
        return DB::transaction(function () use ($delivery, $status, $errorCode, $error): SmsDelivery {
            $delivery = SmsDelivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();
            $wallet = $delivery->wallet()->lockForUpdate()->first();

            if ($wallet && $delivery->reserved_amount_cents > 0) {
                $wallet->forceFill([
                    'reserved_cents' => max(0, $wallet->reserved_cents - $delivery->reserved_amount_cents),
                ])->save();
            }

            $delivery->forceFill([
                'status' => $status,
                'failed_at' => $status === SmsDeliveryStatus::Failed ? now() : null,
                'cancelled_at' => $status === SmsDeliveryStatus::Cancelled ? now() : null,
                'error_code' => $errorCode,
                'last_error' => $this->sanitizedError($error),
            ])->save();

            return $delivery->refresh();
        }, attempts: 3);
    }

    public function creditTopUp(SmsTopUpPayment $payment): AccountSmsWallet
    {
        return DB::transaction(function () use ($payment): AccountSmsWallet {
            $existing = SmsWalletLedgerEntry::query()
                ->where('idempotency_key', 'sms-top-up-credit:'.$payment->id)
                ->first();

            if ($existing) {
                return $existing->wallet()->firstOrFail();
            }

            $wallet = AccountSmsWallet::query()
                ->whereKey($payment->account_sms_wallet_id)
                ->lockForUpdate()
                ->firstOrFail();
            $debtSettlementCents = min($wallet->outstanding_cents, $payment->amount_cents);
            $balanceCreditCents = $payment->amount_cents - $debtSettlementCents;

            $wallet->forceFill([
                'balance_cents' => $wallet->balance_cents + $balanceCreditCents,
                'outstanding_cents' => $wallet->outstanding_cents - $debtSettlementCents,
                'auto_top_up_suspended_at' => null,
                'last_auto_top_up_failure_warning_at' => null,
            ]);
            $wallet->forceFill($this->recoveredWarningValues($wallet))->save();

            $this->appendLedgerEntry(
                wallet: $wallet,
                type: SmsWalletLedgerEntryType::TopUp,
                amountCents: $payment->amount_cents,
                reference: $payment,
                reason: $debtSettlementCents > 0
                    ? 'top_up_settled_outstanding_sms_usage'
                    : 'sms_top_up',
                idempotencyKey: 'sms-top-up-credit:'.$payment->id,
            );

            return $wallet->refresh();
        }, attempts: 3);
    }

    public function reverseTopUp(SmsTopUpPayment $payment): AccountSmsWallet
    {
        return DB::transaction(function () use ($payment): AccountSmsWallet {
            $existing = SmsWalletLedgerEntry::query()
                ->where('idempotency_key', 'sms-top-up-reversal:'.$payment->id)
                ->first();

            if ($existing) {
                return $existing->wallet()->firstOrFail();
            }

            $wallet = AccountSmsWallet::query()
                ->whereKey($payment->account_sms_wallet_id)
                ->lockForUpdate()
                ->firstOrFail();
            $balanceDebitCents = min($wallet->balance_cents, $payment->amount_cents);
            $shortfallCents = $payment->amount_cents - $balanceDebitCents;

            $wallet->forceFill([
                'balance_cents' => $wallet->balance_cents - $balanceDebitCents,
                'outstanding_cents' => $wallet->outstanding_cents + $shortfallCents,
            ])->save();

            $this->appendLedgerEntry(
                wallet: $wallet,
                type: SmsWalletLedgerEntryType::PaymentReversal,
                amountCents: -$payment->amount_cents,
                reference: $payment,
                reason: 'payment_reversed',
                idempotencyKey: 'sms-top-up-reversal:'.$payment->id,
            );

            return $wallet->refresh();
        }, attempts: 3);
    }

    public function adjust(
        AccountSmsWallet $wallet,
        int $amountCents,
        User $actor,
        string $reason,
    ): AccountSmsWallet {
        return DB::transaction(function () use ($wallet, $amountCents, $actor, $reason): AccountSmsWallet {
            $wallet = AccountSmsWallet::query()->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();

            if ($amountCents >= 0) {
                $settledCents = min($wallet->outstanding_cents, $amountCents);
                $wallet->outstanding_cents -= $settledCents;
                $wallet->balance_cents += $amountCents - $settledCents;
            } else {
                $debitCents = abs($amountCents);
                $fromBalanceCents = min($wallet->balance_cents, $debitCents);
                $wallet->balance_cents -= $fromBalanceCents;
                $wallet->outstanding_cents += $debitCents - $fromBalanceCents;
            }

            $wallet->forceFill($this->recoveredWarningValues($wallet))->save();

            $this->appendLedgerEntry(
                wallet: $wallet,
                type: SmsWalletLedgerEntryType::ManualAdjustment,
                amountCents: $amountCents,
                actor: $actor,
                reason: $reason,
                idempotencyKey: 'sms-manual-adjustment:'.Str::uuid(),
            );

            return $wallet->refresh();
        }, attempts: 3);
    }

    public function markAutomaticTopUpSpent(AccountSmsWallet $wallet, int $amountCents): AccountSmsWallet
    {
        $account = $wallet->account()->firstOrFail();
        $period = now($account->timezone ?: config('app.timezone'))->startOfMonth()->toDateString();

        if ($wallet->auto_top_up_monthly_period?->toDateString() !== $period) {
            $wallet->auto_top_up_monthly_period = $period;
            $wallet->auto_top_up_monthly_spent_cents = 0;
        }

        $wallet->auto_top_up_monthly_spent_cents += $amountCents;
        $wallet->save();

        return $wallet;
    }

    private function appendLedgerEntry(
        AccountSmsWallet $wallet,
        SmsWalletLedgerEntryType $type,
        int $amountCents,
        SmsDelivery|SmsTopUpPayment|null $reference = null,
        ?User $actor = null,
        ?string $reason = null,
        ?string $idempotencyKey = null,
    ): SmsWalletLedgerEntry {
        $entry = new SmsWalletLedgerEntry([
            'account_id' => $wallet->account_id,
            'account_sms_wallet_id' => $wallet->id,
            'type' => $type,
            'amount_cents' => $amountCents,
            'balance_after_cents' => $wallet->balance_cents,
            'outstanding_after_cents' => $wallet->outstanding_cents,
            'actor_user_id' => $actor?->id,
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
        ]);

        if ($reference) {
            $entry->reference()->associate($reference);
        }

        $entry->save();

        return $entry;
    }

    /**
     * @return array<string, null>
     */
    private function recoveredWarningValues(AccountSmsWallet $wallet): array
    {
        $values = [];
        $segmentPriceCents = $this->pricing->segmentPriceCents($wallet->account()->firstOrFail());

        if ($wallet->outstanding_cents === 0) {
            $values['last_outstanding_warning_at'] = null;
        }

        if (
            $segmentPriceCents === null
            || $segmentPriceCents === 0
            || (
                $wallet->outstanding_cents === 0
                && $wallet->spendableBalanceCents() >= $segmentPriceCents
            )
        ) {
            $values['last_low_balance_warning_at'] = null;
        }

        return $values;
    }

    private function sanitizedError(?string $error): ?string
    {
        return $error === null ? null : Str::limit(strip_tags($error), 1000, '');
    }
}
