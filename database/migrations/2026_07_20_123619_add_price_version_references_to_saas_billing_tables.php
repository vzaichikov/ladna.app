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
            $table->foreignId('subscription_price_version_id')
                ->nullable()
                ->after('subscription_plan_id')
                ->constrained()
                ->restrictOnDelete();
        });

        Schema::table('account_subscription_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_price_version_id')
                ->nullable()
                ->after('subscription_plan_id');
            $table->foreign('subscription_price_version_id', 'saas_payments_price_version_fk')
                ->references('id')
                ->on('subscription_price_versions')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_subscription_payments', function (Blueprint $table) {
            $table->dropForeign('saas_payments_price_version_fk');
            $table->dropColumn('subscription_price_version_id');
        });

        Schema::table('account_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_price_version_id');
        });
    }
};
