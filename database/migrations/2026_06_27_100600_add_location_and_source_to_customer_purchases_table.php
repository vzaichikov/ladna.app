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
        Schema::table('customer_purchases', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->string('payment_source')->default('online_checkout')->after('provider')->index();

            $table->index(['account_id', 'location_id', 'paid_at'], 'customer_purchases_location_paid_idx');
            $table->index(['customer_class_pass_id', 'payment_source'], 'customer_purchases_pass_source_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_purchases', function (Blueprint $table) {
            $table->dropIndex('customer_purchases_pass_source_idx');
            $table->dropIndex('customer_purchases_location_paid_idx');
            $table->dropIndex(['payment_source']);
            $table->dropForeign(['location_id']);
            $table->dropColumn(['location_id', 'payment_source']);
        });
    }
};
