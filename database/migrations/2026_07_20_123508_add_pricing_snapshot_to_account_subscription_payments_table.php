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
        Schema::table('account_subscription_payments', function (Blueprint $table) {
            $table->foreignId('pending_location_id')->nullable()->after('account_id')->constrained('locations')->nullOnDelete();
            $table->string('plan_name_snapshot')->nullable()->after('subscription_plan_id');
            $table->string('billing_interval_snapshot')->nullable()->after('currency');
            $table->unsignedSmallInteger('billable_location_count')->nullable()->after('billing_interval_snapshot');
            $table->json('tier_breakdown_snapshot')->nullable()->after('billable_location_count');
            $table->unsignedInteger('subtotal_cents')->nullable()->after('tier_breakdown_snapshot');
            $table->unsignedInteger('discount_cents')->default(0)->after('subtotal_cents');
            $table->string('idempotency_key')->nullable()->after('discount_cents')->unique();
            $table->unsignedSmallInteger('renewal_attempt')->default(0)->after('idempotency_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_subscription_payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropConstrainedForeignId('pending_location_id');
            $table->dropColumn([
                'plan_name_snapshot',
                'billing_interval_snapshot',
                'billable_location_count',
                'tier_breakdown_snapshot',
                'subtotal_cents',
                'discount_cents',
                'idempotency_key',
                'renewal_attempt',
            ]);
        });
    }
};
