<?php

namespace App\Console\Commands;

use App\Actions\Payments\ReconcilePaidTrialCustomerPurchase;
use App\Enums\CustomerClassPassAdjustmentType;
use App\Enums\IntegrationProvider;
use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\Payments\MonopayGateway;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('payments:reconcile-paid-trial
    {--order= : Required exact customer purchase order ID}
    {--actor= : Required audit actor user ID}
    {--reason= : Required audit reason, 3 to 2000 characters}
    {--expected-purchase= : Required exact purchase ID with --execute}
    {--expected-account= : Required exact account ID with --execute}
    {--expected-customer= : Required exact customer ID with --execute}
    {--expected-amount= : Required exact amount in cents with --execute}
    {--expected-invoice= : Required exact Monopay invoice ID with --execute}
    {--execute : Apply the displayed audited reconciliation}
    {--force : Required with --execute in production}')]
#[Description('Dry-run and reconcile one exact charged legacy Monopay trial purchase with an audited exception.')]
class ReconcilePaidTrialCustomerPurchaseCommand extends Command
{
    public function handle(
        MonopayGateway $monopay,
        ReconcilePaidTrialCustomerPurchase $reconcile,
    ): int {
        $orderId = trim((string) $this->option('order'));
        $actorId = $this->positiveIntegerOption('actor');
        $reason = trim((string) $this->option('reason'));

        if ($orderId === '' || ! $actorId || mb_strlen($reason) < 3 || mb_strlen($reason) > 2000) {
            $this->error('Exact --order, positive --actor, and --reason between 3 and 2000 characters are required.');

            return self::FAILURE;
        }

        $purchase = CustomerPurchase::query()
            ->with(['account', 'customer', 'classPassPlan', 'customerClassPass.adjustments'])
            ->where('provider', IntegrationProvider::Monopay->value)
            ->where('order_id', $orderId)
            ->first();
        $actor = User::query()->find($actorId);

        if (! $purchase || ! $actor || ! filled($purchase->gateway_invoice_id)) {
            $this->error('The exact purchase, actor, or stored Monopay invoice was not found.');

            return self::FAILURE;
        }

        $setting = IntegrationSetting::forAccount($purchase->account)
            ->where('provider', IntegrationProvider::Monopay->value)
            ->where('is_enabled', true)
            ->first();

        if (! $setting) {
            $this->error('The enabled account Monopay integration was not found.');

            return self::FAILURE;
        }

        try {
            $callback = $monopay->invoiceStatus((string) $purchase->gateway_invoice_id, $setting);
            $reconcile->assertAuthoritativePayment($purchase, $callback);

            if (! $this->isAlreadyReconciled($purchase, $actor, $reason)) {
                $reconcile->assertAvailable($purchase, $callback, $actor, $reason);
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Target', 'Verified value'], [
            ['purchase', $purchase->id],
            ['order', $purchase->order_id],
            ['account', $purchase->account_id],
            ['customer', $purchase->customer_id],
            ['plan', "{$purchase->class_pass_plan_id} ({$purchase->plan_name})"],
            ['amount', "{$purchase->amount_cents} {$purchase->currency}"],
            ['invoice', $purchase->gateway_invoice_id],
            ['Monopay status', $callback->gatewayStatus],
            ['actor', "{$actor->id} ({$actor->name})"],
            ['audit reason', $reason],
        ]);

        if (! $this->option('execute')) {
            $this->warn('Dry run only. No database changes were made. Re-run with --execute and all exact --expected-* values after backup verification.');

            return self::SUCCESS;
        }

        if (! $this->expectedValuesMatch($purchase)) {
            $this->error('Exact expected purchase, account, customer, amount, and invoice values are required and must match the latest dry run.');

            return self::FAILURE;
        }

        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Use --force together with --execute in production after the verified backup and dry run.');

            return self::FAILURE;
        }

        if ($this->isAlreadyReconciled($purchase, $actor, $reason)) {
            $this->info("Paid trial purchase {$purchase->id} was already reconciled with this exact audit record. No changes made.");

            return self::SUCCESS;
        }

        try {
            $completed = $reconcile->execute($purchase, $callback, $actor, $reason)
                ->refresh()
                ->load('customerClassPass.adjustments');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $adjustments = $completed->customerClassPass?->adjustments
            ->where('adjustment_type', CustomerClassPassAdjustmentType::TrialEligibilityOverride);

        if (! $completed->isPaid()
            || ! $completed->customerClassPass?->is_paid
            || $adjustments?->count() !== 1) {
            $this->error('Post-write verification failed for the purchase, pass, or audit adjustment.');

            return self::FAILURE;
        }

        $this->info("Reconciled paid trial purchase {$completed->id}; pass {$completed->customer_class_pass_id} and one audited exception were verified.");

        return self::SUCCESS;
    }

    private function positiveIntegerOption(string $name): int|false
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT);

        return $value !== false && $value > 0 ? $value : false;
    }

    private function expectedValuesMatch(CustomerPurchase $purchase): bool
    {
        return $this->positiveIntegerOption('expected-purchase') === $purchase->id
            && $this->positiveIntegerOption('expected-account') === $purchase->account_id
            && $this->positiveIntegerOption('expected-customer') === $purchase->customer_id
            && $this->positiveIntegerOption('expected-amount') === $purchase->amount_cents
            && hash_equals((string) $purchase->gateway_invoice_id, (string) $this->option('expected-invoice'));
    }

    private function isAlreadyReconciled(CustomerPurchase $purchase, User $actor, string $reason): bool
    {
        if (! $purchase->isPaid() || ! $purchase->customerClassPass?->is_paid) {
            return false;
        }

        return $purchase->customerClassPass->adjustments
            ->where('adjustment_type', CustomerClassPassAdjustmentType::TrialEligibilityOverride)
            ->where('actor_user_id', $actor->id)
            ->where('reason', $reason)
            ->count() === 1;
    }
}
