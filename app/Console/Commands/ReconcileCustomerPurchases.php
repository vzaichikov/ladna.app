<?php

namespace App\Console\Commands;

use App\Actions\Payments\ReconcileCustomerPurchasePayment;
use App\Enums\AccountMode;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\IntegrationProvider;
use App\Models\CustomerPurchase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('customer-purchases:reconcile {--limit=50 : Maximum stale payments to check} {--purchase= : Check only this purchase}')]
#[Description('Recover unfinished class-pass payments from their provider status')]
class ReconcileCustomerPurchases extends Command
{
    public function handle(ReconcileCustomerPurchasePayment $reconcile): int
    {
        $pendingStatuses = [CustomerPurchaseStatus::PaymentStarted->value, CustomerPurchaseStatus::PaymentPending->value];
        $purchases = CustomerPurchase::query()
            ->where('payment_source', CustomerPurchase::SourceOnlineCheckout)
            ->where('provider', IntegrationProvider::Monopay->value)
            ->whereIn('status', $pendingStatuses)
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->whereHas('account', fn (Builder $query): Builder => $query->where('mode', '!=', AccountMode::DemoReadonly->value))
            ->when($this->option('purchase') !== null, fn (Builder $query): Builder => $query->whereKey((int) $this->option('purchase')))
            ->orderBy('updated_at')->orderBy('id')
            ->limit(max(1, min(100, (int) $this->option('limit'))))
            ->get();
        $checked = 0;
        $unavailable = 0;

        foreach ($purchases as $purchase) {
            try {
                $reconcile->execute($purchase, requireFreshStatus: true);
                $checked++;
            } catch (Throwable $exception) {
                report($exception);
                $unavailable++;
            } finally {
                CustomerPurchase::query()
                    ->whereKey($purchase->id)
                    ->where('account_id', $purchase->account_id)
                    ->whereIn('status', $pendingStatuses)
                    ->update(['updated_at' => now()]);
            }
        }

        $this->info("Checked {$checked} class-pass payment(s); {$unavailable} unavailable.");

        return $unavailable > 0 ? self::FAILURE : self::SUCCESS;
    }
}
