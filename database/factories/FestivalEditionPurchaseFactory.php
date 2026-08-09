<?php

namespace Database\Factories;

use App\Enums\FestivalEditionPurchaseStatus;
use App\Models\Account;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalTariffPackage;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FestivalEditionPurchase>
 */
class FestivalEditionPurchaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'festival_tariff_package_id' => fn (array $attributes): int => FestivalTariffPackage::factory()->create([
                'subscription_plan_id' => $attributes['subscription_plan_id'],
            ])->id,
            'created_by_user_id' => User::factory(),
            'provider' => 'monopay',
            'status' => FestivalEditionPurchaseStatus::Available,
            'order_id' => 'FEST-'.Str::upper(Str::random(24)),
            'amount_cents' => 150000,
            'currency' => 'UAH',
            'tariff_name_snapshot' => 'Studio',
            'package_name_snapshot' => 'S',
            'max_participants' => 100,
            'max_tickets' => 300,
            'idempotency_key' => (string) Str::uuid(),
            'started_at' => now(),
            'paid_at' => now(),
        ];
    }
}
