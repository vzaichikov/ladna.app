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
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('plan_type')->default('standard')->after('billing_interval')->index();
            $table->unsignedSmallInteger('access_days')->nullable()->after('plan_type');
            $table->boolean('public_signup_enabled')->default(false)->after('access_days')->index();
            $table->boolean('requires_recurring_payment')->default(false)->after('public_signup_enabled');
            $table->unsignedSmallInteger('renewal_lead_days')->default(2)->after('requires_recurring_payment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropIndex(['plan_type']);
            $table->dropIndex(['public_signup_enabled']);
            $table->dropColumn([
                'plan_type',
                'access_days',
                'public_signup_enabled',
                'requires_recurring_payment',
                'renewal_lead_days',
            ]);
        });
    }
};
