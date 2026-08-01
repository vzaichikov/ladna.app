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
        Schema::table('customer_purchase_corrections', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->after('new_paid_at')->unique();
        });

        Schema::table('studio_expenses', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->after('payment_method')->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('studio_expenses', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        Schema::table('customer_purchase_corrections', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
