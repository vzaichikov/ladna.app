<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\IntegrationProvider;
use App\Models\Account;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalTariffPackage;
use App\Models\User;
use App\Support\Festivals\FestivalSaasAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateFestivalEditionPurchase
{
    public function __construct(private readonly FestivalSaasAccess $access) {}

    public function execute(Account $account, FestivalTariffPackage $package, User $creator, string $idempotencyKey): FestivalEditionPurchase
    {
        abort_unless($account->isOwnedBy($creator), 403);

        if (! $this->access->canPurchase($account)) {
            throw ValidationException::withMessages(['package' => __('app.festival_purchase_unavailable')]);
        }

        $account->loadMissing('subscription.plan');
        $subscription = $account->subscription;
        abort_unless($subscription && $package->subscription_plan_id === $subscription->subscription_plan_id && $package->is_active, 404);

        return DB::transaction(function () use ($account, $package, $creator, $idempotencyKey, $subscription): FestivalEditionPurchase {
            $existing = FestivalEditionPurchase::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->account_id === $account->id && $existing->festival_tariff_package_id === $package->id, 409);

                return $existing;
            }

            $package = FestivalTariffPackage::query()->whereKey($package->id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $paymentMethod = $subscription->paymentMethod()->where('account_id', $account->id)->first();
            $isFree = $package->price_cents === 0;

            return FestivalEditionPurchase::query()->create([
                'account_id' => $account->id,
                'subscription_plan_id' => $subscription->subscription_plan_id,
                'festival_tariff_package_id' => $package->id,
                'account_subscription_payment_method_id' => $paymentMethod?->id,
                'created_by_user_id' => $creator->id,
                'provider' => $isFree ? null : IntegrationProvider::Monopay->value,
                'status' => $isFree ? FestivalEditionPurchaseStatus::Available : FestivalEditionPurchaseStatus::PaymentStarted,
                'order_id' => $isFree ? null : 'FEST-'.Str::upper(Str::random(24)),
                'amount_cents' => $package->price_cents,
                'currency' => strtoupper($package->currency),
                'idempotency_key' => $idempotencyKey,
                'started_at' => now(),
                'paid_at' => null,
            ]);
        }, attempts: 3);
    }
}
