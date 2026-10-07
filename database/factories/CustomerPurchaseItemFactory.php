<?php

namespace Database\Factories;

use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerPurchaseItem> */
class CustomerPurchaseItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_purchase_id' => CustomerPurchase::factory(),
            'account_id' => fn (array $attributes): int => CustomerPurchase::findOrFail($attributes['customer_purchase_id'])->account_id,
            'class_pass_plan_id' => fn (array $attributes): ?int => CustomerPurchase::findOrFail($attributes['customer_purchase_id'])->class_pass_plan_id,
            'position' => 0,
            'plan_name' => 'Individual lesson',
            'plan_slug' => 'individual-lesson',
            'schedule_kind' => 'private_class',
            'subtotal_cents' => 100000,
            'discount_cents' => 0,
            'amount_cents' => 100000,
            'currency' => 'UAH',
            'sessions_count' => 1,
            'validity_days' => 30,
            'total_validity_days' => 180,
            'allows_any_time' => false,
            'is_trial' => false,
        ];
    }
}
