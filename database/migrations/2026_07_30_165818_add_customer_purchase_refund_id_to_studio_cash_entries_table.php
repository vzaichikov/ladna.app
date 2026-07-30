<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('studio_cash_entries', function (Blueprint $table) {
            $table->foreignId('customer_purchase_refund_id')
                ->nullable()
                ->after('studio_expense_id')
                ->constrained('customer_purchase_refunds')
                ->cascadeOnDelete();
            $table->unique('customer_purchase_refund_id', 'studio_cash_entries_refund_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('studio_cash_entries', function (Blueprint $table) {
            $table->dropForeign(['customer_purchase_refund_id']);
            $table->dropUnique('studio_cash_entries_refund_unique');
            $table->dropColumn('customer_purchase_refund_id');
        });
    }
};
