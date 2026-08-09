<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEditionPurchaseStatus;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RedeemFestivalEditionPurchase
{
    public function __construct(private readonly SaveFestivalEdition $saveEdition) {}

    /** @param array<string, mixed> $input */
    public function execute(Account $account, FestivalEditionPurchase $purchase, array $input, User $actor): FestivalEdition
    {
        return DB::transaction(function () use ($account, $purchase, $input, $actor): FestivalEdition {
            $purchase = FestivalEditionPurchase::query()
                ->whereBelongsTo($account)
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($purchase->status !== FestivalEditionPurchaseStatus::Available || $purchase->festival_edition_id !== null) {
                throw ValidationException::withMessages(['festival_purchase_id' => __('app.festival_entitlement_unavailable')]);
            }

            $edition = $this->saveEdition->execute($account, $input, $actor);
            $purchase->forceFill([
                'status' => FestivalEditionPurchaseStatus::Redeemed,
                'festival_edition_id' => $edition->id,
                'redeemed_at' => now(),
            ])->save();

            return $edition->refresh();
        }, attempts: 3);
    }
}
