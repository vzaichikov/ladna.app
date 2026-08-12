<?php

namespace App\Console\Commands;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\EventOrderStatus;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\FiscalReceiptStatus;
use App\Enums\SmsTopUpPaymentStatus;
use App\Models\Account;
use App\Models\AccountSubscriptionPayment;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\EventOrder;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalTicketOrder;
use App\Models\SmsTopUpPayment;
use App\Support\Fiscalization\FiscalReceiptService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:fiscalize {account? : Optional studio account ID. Without it, all accounts are processed.}')]
#[Description('Fiscalize paid payments that are eligible for Ladna fiscalization.')]
class FiscalizePayments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FiscalReceiptService $fiscalReceipts): int
    {
        $accountId = $this->accountId();

        if ($accountId === false) {
            $this->components->error('Account argument must be a numeric account ID.');

            return self::FAILURE;
        }

        if ($accountId !== null && ! Account::query()->operational()->whereKey($accountId)->exists()) {
            $this->components->error("Operational account {$accountId} was not found.");

            return self::FAILURE;
        }

        $this->components->info($accountId ? "Fiscalizing payments for account {$accountId}." : 'Fiscalizing payments for all accounts.');

        $processed = 0;
        $fiscalized = 0;
        $failed = 0;
        $skipped = 0;

        AccountSubscriptionPayment::query()
            ->where('status', AccountSubscriptionPaymentStatus::PaymentPaid->value)
            ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
            ->when($accountId === null, fn ($query) => $query->where(function ($query): void {
                $query
                    ->whereNull('account_id')
                    ->orWhereHas('account', fn ($query) => $query->operational());
            }))
            ->with(['account', 'plan', 'fiscalReceipt'])
            ->lazyById()
            ->each(function (AccountSubscriptionPayment $payment) use ($fiscalReceipts, &$processed, &$fiscalized, &$failed, &$skipped): void {
                [$result, $message] = $this->processPayment('saas', $payment, $fiscalReceipts);
                $this->line($message);
                $processed++;

                match ($result) {
                    'fiscalized' => $fiscalized++,
                    'failed' => $failed++,
                    default => $skipped++,
                };
            });

        SmsTopUpPayment::query()
            ->where('status', SmsTopUpPaymentStatus::PaymentPaid->value)
            ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
            ->when($accountId === null, fn ($query) => $query->whereHas('account', fn ($query) => $query->operational()))
            ->with(['account', 'fiscalReceipt'])
            ->lazyById()
            ->each(function (SmsTopUpPayment $payment) use ($fiscalReceipts, &$processed, &$fiscalized, &$failed, &$skipped): void {
                [$result, $message] = $this->processPayment('sms-top-up', $payment, $fiscalReceipts);
                $this->line($message);
                $processed++;

                match ($result) {
                    'fiscalized' => $fiscalized++,
                    'failed' => $failed++,
                    default => $skipped++,
                };
            });

        FestivalEditionPurchase::query()
            ->whereIn('status', [FestivalEditionPurchaseStatus::Available->value, FestivalEditionPurchaseStatus::Redeemed->value])
            ->where('amount_cents', '>', 0)
            ->whereNotNull('paid_at')
            ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
            ->when($accountId === null, fn ($query) => $query->whereHas('account', fn ($query) => $query->operational()))
            ->with(['account', 'fiscalReceipt'])
            ->lazyById()
            ->each(function (FestivalEditionPurchase $purchase) use ($fiscalReceipts, &$processed, &$fiscalized, &$failed, &$skipped): void {
                [$result, $message] = $this->processPayment('festival', $purchase, $fiscalReceipts);
                $this->line($message);
                $processed++;

                match ($result) {
                    'fiscalized' => $fiscalized++,
                    'failed' => $failed++,
                    default => $skipped++,
                };
            });

        FestivalTicketOrder::query()
            ->where('status', FestivalTicketOrderStatus::Paid->value)
            ->where('amount_cents', '>', 0)
            ->whereNotNull('paid_at')
            ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
            ->when($accountId === null, fn ($query) => $query->whereHas('account', fn ($query) => $query->operational()))
            ->with(['account', 'edition', 'items', 'fiscalReceipt'])
            ->lazyById()
            ->each(function (FestivalTicketOrder $order) use ($fiscalReceipts, &$processed, &$fiscalized, &$failed, &$skipped): void {
                [$result, $message] = $this->processPayment('festival-admission', $order, $fiscalReceipts);
                $this->line($message);
                $processed++;

                match ($result) {
                    'fiscalized' => $fiscalized++,
                    'failed' => $failed++,
                    default => $skipped++,
                };
            });

        CustomerPurchase::query()
            ->where('status', CustomerPurchaseStatus::PaymentPaid->value)
            ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
            ->when($accountId === null, fn ($query) => $query->whereHas('account', fn ($query) => $query->operational()))
            ->with(['account', 'customer', 'classPassPlan', 'fiscalReceipt'])
            ->lazyById()
            ->each(function (CustomerPurchase $purchase) use ($fiscalReceipts, &$processed, &$fiscalized, &$failed, &$skipped): void {
                [$result, $message] = $this->processPayment('customer', $purchase, $fiscalReceipts);
                $this->line($message);
                $processed++;

                match ($result) {
                    'fiscalized' => $fiscalized++,
                    'failed' => $failed++,
                    default => $skipped++,
                };
            });

        CustomerPurchaseRefund::query()
            ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
            ->when($accountId === null, fn ($query) => $query->whereHas('account', fn ($query) => $query->operational()))
            ->with(['account', 'customerPurchase.customer', 'customerPurchase.fiscalReceipt', 'fiscalReceipt'])
            ->lazyById()
            ->each(function (CustomerPurchaseRefund $refund) use ($fiscalReceipts, &$processed, &$fiscalized, &$failed, &$skipped): void {
                [$result, $message] = $this->processPayment('customer-refund', $refund, $fiscalReceipts);
                $this->line($message);
                $processed++;

                match ($result) {
                    'fiscalized' => $fiscalized++,
                    'failed' => $failed++,
                    default => $skipped++,
                };
            });

        EventOrder::query()
            ->whereIn('status', [EventOrderStatus::Paid->value, EventOrderStatus::RefundRequired->value])
            ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
            ->when($accountId === null, fn ($query) => $query->whereHas('account', fn ($query) => $query->operational()))
            ->with(['account', 'event', 'fiscalReceipt'])
            ->lazyById()
            ->each(function (EventOrder $order) use ($fiscalReceipts, &$processed, &$fiscalized, &$failed, &$skipped): void {
                [$result, $message] = $this->processPayment('event', $order, $fiscalReceipts);
                $this->line($message);
                $processed++;

                match ($result) {
                    'fiscalized' => $fiscalized++,
                    'failed' => $failed++,
                    default => $skipped++,
                };
            });

        $this->components->info("Processed: {$processed}");
        $this->components->info("Fiscalized: {$fiscalized}");
        $this->components->warn("Failed: {$failed}");
        $this->components->info("Skipped: {$skipped}");

        return self::SUCCESS;
    }

    private function accountId(): int|false|null
    {
        $account = $this->argument('account');

        if ($account === null || $account === '') {
            return null;
        }

        return is_numeric($account) ? (int) $account : false;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function processPayment(
        string $kind,
        AccountSubscriptionPayment|CustomerPurchase|CustomerPurchaseRefund|EventOrder|SmsTopUpPayment|FestivalEditionPurchase|FestivalTicketOrder $payment,
        FiscalReceiptService $fiscalReceipts,
    ): array {
        if ($payment->fiscalReceipt?->isFiscalized()) {
            return ['skipped', "[{$kind}] #{$payment->id} {$this->paymentReference($payment)}: skipped, already fiscalized ({$payment->fiscalReceipt->fiscal_number})."];
        }

        $skipReason = $fiscalReceipts->skipReasonFor($payment);

        if ($skipReason !== null) {
            return ['skipped', "[{$kind}] #{$payment->id} {$this->paymentReference($payment)}: skipped, {$skipReason}."];
        }

        $receipt = match (true) {
            $payment instanceof CustomerPurchase => $fiscalReceipts->fiscalizeCustomerPurchase($payment),
            $payment instanceof CustomerPurchaseRefund => $fiscalReceipts->fiscalizeCustomerPurchaseRefund($payment),
            $payment instanceof EventOrder => $fiscalReceipts->fiscalizeEventOrder($payment),
            $payment instanceof SmsTopUpPayment => $fiscalReceipts->fiscalizeSmsTopUpPayment($payment),
            $payment instanceof FestivalEditionPurchase => $fiscalReceipts->fiscalizeFestivalEditionPurchase($payment),
            $payment instanceof FestivalTicketOrder => $fiscalReceipts->fiscalizeFestivalTicketOrder($payment),
            default => $fiscalReceipts->fiscalizeAccountSubscriptionPayment($payment),
        };

        if (! $receipt) {
            return ['skipped', "[{$kind}] #{$payment->id} {$this->paymentReference($payment)}: skipped, no fiscal receipt was created."];
        }

        if ($receipt->status === FiscalReceiptStatus::Fiscalized) {
            return ['fiscalized', "[{$kind}] #{$payment->id} {$this->paymentReference($payment)}: fiscalized ({$receipt->fiscal_number})."];
        }

        if ($receipt->status === FiscalReceiptStatus::Failed) {
            return ['failed', "[{$kind}] #{$payment->id} {$this->paymentReference($payment)}: failed, {$receipt->last_error}."];
        }

        return ['skipped', "[{$kind}] #{$payment->id} {$this->paymentReference($payment)}: {$receipt->status->value}."];
    }

    private function paymentReference(AccountSubscriptionPayment|CustomerPurchase|CustomerPurchaseRefund|EventOrder|SmsTopUpPayment|FestivalEditionPurchase|FestivalTicketOrder $payment): string
    {
        if ($payment instanceof CustomerPurchaseRefund) {
            return ($payment->customerPurchase?->order_id ?? 'payment').'/refund-'.$payment->id;
        }

        return (string) $payment->order_id;
    }
}
