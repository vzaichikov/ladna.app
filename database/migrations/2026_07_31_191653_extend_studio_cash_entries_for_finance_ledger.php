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
            $table->dropForeign(['location_id']);
            $table->dropForeign(['studio_expense_id']);
            $table->dropForeign(['customer_purchase_refund_id']);
        });

        Schema::table('studio_cash_entries', function (Blueprint $table) {
            $table->foreignId('finance_epoch_id')->nullable()->after('account_id')->constrained('finance_epochs')->restrictOnDelete();
            $table->foreignId('customer_purchase_id')->nullable()->after('studio_expense_id')->constrained()->nullOnDelete();
            $table->foreignId('customer_purchase_correction_id')->nullable()->after('customer_purchase_id')->constrained()->nullOnDelete();
            $table->string('source_key', 191)->nullable()->after('customer_purchase_refund_id')->unique();
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->foreign('studio_expense_id')->references('id')->on('studio_expenses')->nullOnDelete();
            $table->foreign('customer_purchase_refund_id')->references('id')->on('customer_purchase_refunds')->nullOnDelete();
            $table->index(
                ['account_id', 'finance_epoch_id', 'location_id', 'currency', 'id'],
                'studio_cash_entries_balance_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('studio_cash_entries', function (Blueprint $table) {
            $table->dropIndex('studio_cash_entries_balance_idx');
            $table->dropUnique(['source_key']);
            $table->dropForeign(['location_id']);
            $table->dropForeign(['studio_expense_id']);
            $table->dropForeign(['customer_purchase_refund_id']);
            $table->dropConstrainedForeignId('customer_purchase_correction_id');
            $table->dropConstrainedForeignId('customer_purchase_id');
            $table->dropConstrainedForeignId('finance_epoch_id');
            $table->dropColumn('source_key');
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            $table->foreign('studio_expense_id')->references('id')->on('studio_expenses')->cascadeOnDelete();
            $table->foreign('customer_purchase_refund_id')->references('id')->on('customer_purchase_refunds')->cascadeOnDelete();
        });
    }
};
