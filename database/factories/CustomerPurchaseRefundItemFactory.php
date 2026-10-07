<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseItem;
use App\Models\CustomerPurchaseRefund;
use App\Models\CustomerPurchaseRefundItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerPurchaseRefundItem>
 */
class CustomerPurchaseRefundItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $account = Account::factory();
        $purchase = CustomerPurchase::factory()->for($account);

        return [
            'account_id' => $account,
            'customer_purchase_refund_id' => CustomerPurchaseRefund::factory()->for($account)->for($purchase, 'customerPurchase'),
            'customer_purchase_item_id' => CustomerPurchaseItem::factory()->for($account)->for($purchase, 'purchase'),
            'amount_cents' => fake()->numberBetween(1, 10000),
        ];
    }
}
