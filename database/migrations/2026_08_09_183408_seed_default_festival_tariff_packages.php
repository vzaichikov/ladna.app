<?php

use App\Enums\SubscriptionPlanType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();
        $packages = [
            ['name' => 'S', 'price_cents' => 150000, 'max_participants' => 100, 'max_tickets' => 300, 'sort_order' => 10],
            ['name' => 'M', 'price_cents' => 300000, 'max_participants' => 250, 'max_tickets' => 700, 'sort_order' => 20],
            ['name' => 'L', 'price_cents' => 500000, 'max_participants' => 500, 'max_tickets' => 1500, 'sort_order' => 30],
        ];

        DB::table('subscription_plans')
            ->where('is_active', true)
            ->where('plan_type', '!=', SubscriptionPlanType::Demo->value)
            ->orderBy('id')
            ->each(function (object $plan) use ($packages, $now): void {
                foreach ($packages as $package) {
                    DB::table('festival_tariff_packages')->updateOrInsert(
                        ['subscription_plan_id' => $plan->id, 'name' => $package['name']],
                        [...$package, 'currency' => 'UAH', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                    );
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('festival_tariff_packages')
            ->whereIn('name', ['S', 'M', 'L'])
            ->whereIn('sort_order', [10, 20, 30])
            ->delete();
    }
};
