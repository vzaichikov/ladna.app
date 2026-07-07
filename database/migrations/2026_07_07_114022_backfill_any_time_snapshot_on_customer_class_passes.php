<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE customer_class_passes
            INNER JOIN class_pass_plans ON class_pass_plans.id = customer_class_passes.class_pass_plan_id
            SET customer_class_passes.available_from_time = class_pass_plans.available_from_time,
                customer_class_passes.available_until_time = class_pass_plans.available_until_time,
                customer_class_passes.allows_any_time = class_pass_plans.allows_any_time,
                customer_class_passes.any_time_addon_price_cents = class_pass_plans.any_time_addon_price_cents
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Do not clear purchased-pass snapshots during rollback; the schema rollback drops these columns.
    }
};
