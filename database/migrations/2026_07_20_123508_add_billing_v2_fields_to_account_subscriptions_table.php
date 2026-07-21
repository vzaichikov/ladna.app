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
            $table->string('billing_mode')->default('legacy')->after('status')->index();
            $table->string('billing_interval_v2')->nullable()->after('billing_mode')->index();
            $table->unsignedSmallInteger('billable_location_count')->nullable()->after('billing_interval_v2');
            $table->timestamp('trial_started_at')->nullable()->after('billable_location_count');
            $table->timestamp('trial_ends_at')->nullable()->after('trial_started_at')->index();
            $table->timestamp('billing_anchor_at')->nullable()->after('trial_ends_at');
            $table->timestamp('grace_ends_at')->nullable()->after('billing_anchor_at')->index();
            $table->boolean('cancel_at_period_end')->default(false)->after('grace_ends_at')->index();
            $table->timestamp('cancellation_requested_at')->nullable()->after('cancel_at_period_end');
            $table->unsignedSmallInteger('renewal_attempts')->default(0)->after('cancellation_requested_at');
            $table->timestamp('next_retry_at')->nullable()->after('renewal_attempts')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['billing_mode']);
            $table->dropIndex(['billing_interval_v2']);
            $table->dropIndex(['trial_ends_at']);
            $table->dropIndex(['grace_ends_at']);
            $table->dropIndex(['cancel_at_period_end']);
            $table->dropIndex(['next_retry_at']);
            $table->dropColumn([
                'billing_mode',
                'billing_interval_v2',
                'billable_location_count',
                'trial_started_at',
                'trial_ends_at',
                'billing_anchor_at',
                'grace_ends_at',
                'cancel_at_period_end',
                'cancellation_requested_at',
                'renewal_attempts',
                'next_retry_at',
            ]);
        });
    }
};
