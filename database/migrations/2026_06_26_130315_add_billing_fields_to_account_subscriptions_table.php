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
        Schema::table('account_subscriptions', function (Blueprint $table) {
            $table->timestamp('next_payment_at')->nullable()->after('ends_at')->index();
            $table->string('payment_provider')->nullable()->after('next_payment_at')->index();
            $table->string('provider_subscription_id')->nullable()->after('payment_provider')->index();
            $table->string('provider_status')->nullable()->after('provider_subscription_id');
            $table->boolean('auto_renew_enabled')->default(false)->after('provider_status')->index();
            $table->timestamp('cancelled_at')->nullable()->after('auto_renew_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['next_payment_at']);
            $table->dropIndex(['payment_provider']);
            $table->dropIndex(['provider_subscription_id']);
            $table->dropIndex(['auto_renew_enabled']);
            $table->dropColumn([
                'next_payment_at',
                'payment_provider',
                'provider_subscription_id',
                'provider_status',
                'auto_renew_enabled',
                'cancelled_at',
            ]);
        });
    }
};
