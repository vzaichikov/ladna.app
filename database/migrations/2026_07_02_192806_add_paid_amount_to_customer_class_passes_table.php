<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_class_passes', function (Blueprint $table) {
            $table->unsignedInteger('paid_amount_cents')->default(0)->after('price_cents');

            $table->index(['account_id', 'is_active', 'is_paid', 'paid_amount_cents'], 'customer_passes_account_active_payment_idx');
        });

        DB::statement(<<<'SQL'
            UPDATE customer_class_passes
            LEFT JOIN (
                SELECT customer_class_pass_id, SUM(amount_cents) AS paid_amount_cents
                FROM customer_purchases
                WHERE status = 'payment_paid'
                    AND customer_class_pass_id IS NOT NULL
                GROUP BY customer_class_pass_id
            ) paid_purchases ON paid_purchases.customer_class_pass_id = customer_class_passes.id
            SET customer_class_passes.paid_amount_cents = LEAST(
                customer_class_passes.price_cents,
                COALESCE(
                    paid_purchases.paid_amount_cents,
                    CASE WHEN customer_class_passes.is_paid = 1 THEN customer_class_passes.price_cents ELSE 0 END
                )
            ),
            customer_class_passes.is_paid = CASE
                WHEN COALESCE(
                    paid_purchases.paid_amount_cents,
                    CASE WHEN customer_class_passes.is_paid = 1 THEN customer_class_passes.price_cents ELSE 0 END
                ) >= customer_class_passes.price_cents THEN 1
                ELSE 0
            END
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_class_passes', function (Blueprint $table) {
            $table->dropIndex('customer_passes_account_active_payment_idx');
            $table->dropColumn('paid_amount_cents');
        });
    }
};
