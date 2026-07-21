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
        if (! Schema::hasColumn('account_subscriptions', 'pending_subscription_price_version_id')) {
            Schema::table('account_subscriptions', function (Blueprint $table) {
                $table->foreignId('pending_subscription_price_version_id')
                    ->nullable()
                    ->after('subscription_price_version_id');
            });
        }

        if (! Schema::hasColumn('account_subscriptions', 'pending_tariff_change_at')) {
            Schema::table('account_subscriptions', function (Blueprint $table) {
                $table->timestamp('pending_tariff_change_at')
                    ->nullable()
                    ->after('pending_subscription_price_version_id');
            });
        }

        Schema::table('account_subscriptions', function (Blueprint $table) {
            $table->index('pending_subscription_price_version_id', 'saas_pending_price_version_idx');
            $table->index('pending_tariff_change_at', 'saas_pending_tariff_change_at_idx');
            $table->foreign('pending_subscription_price_version_id', 'saas_pending_price_version_fk')
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
        Schema::table('account_subscriptions', function (Blueprint $table) {
            $table->dropForeign('saas_pending_price_version_fk');
            $table->dropIndex('saas_pending_price_version_idx');
            $table->dropIndex('saas_pending_tariff_change_at_idx');
            $table->dropColumn([
                'pending_subscription_price_version_id',
                'pending_tariff_change_at',
            ]);
        });
    }
};
