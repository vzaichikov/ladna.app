<?php

namespace App\Console\Commands;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\FiscalReceiptStatus;
use App\Models\Account;
use App\Models\AccountSubscriptionPayment;
use App\Models\CustomerPurchase;
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

        if ($accountId !== null && ! Account::whereKey($accountId)->exists()) {
            $this->components->error("Account {$accountId} was not found.");

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

        CustomerPurchase::query()
            ->where('status', CustomerPurchaseStatus::PaymentPaid->value)
            ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
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
        AccountSubscriptionPayment|CustomerPurchase $payment,
        FiscalReceiptService $fiscalReceipts,
    ): array {
        if ($payment->fiscalReceipt?->isFiscalized()) {
            return ['skipped', "[{$kind}] #{$payment->id} {$payment->order_id}: skipped, already fiscalized ({$payment->fiscalReceipt->fiscal_number})."];
        }

        $skipReason = $fiscalReceipts->skipReasonFor($payment);

        if ($skipReason !== null) {
            return ['skipped', "[{$kind}] #{$payment->id} {$payment->order_id}: skipped, {$skipReason}."];
        }

        $receipt = $payment instanceof CustomerPurchase
            ? $fiscalReceipts->fiscalizeCustomerPurchase($payment)
            : $fiscalReceipts->fiscalizeAccountSubscriptionPayment($payment);

        if (! $receipt) {
            return ['skipped', "[{$kind}] #{$payment->id} {$payment->order_id}: skipped, no fiscal receipt was created."];
        }

        if ($receipt->status === FiscalReceiptStatus::Fiscalized) {
            return ['fiscalized', "[{$kind}] #{$payment->id} {$payment->order_id}: fiscalized ({$receipt->fiscal_number})."];
        }

        if ($receipt->status === FiscalReceiptStatus::Failed) {
            return ['failed', "[{$kind}] #{$payment->id} {$payment->order_id}: failed, {$receipt->last_error}."];
        }

        return ['skipped', "[{$kind}] #{$payment->id} {$payment->order_id}: {$receipt->status->value}."];
    }
}
