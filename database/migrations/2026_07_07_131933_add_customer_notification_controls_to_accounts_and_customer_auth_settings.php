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
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('enable_customer_notifications')->default(false)->after('enable_telegram_alerts');
        });

        Schema::table('customer_auth_settings', function (Blueprint $table): void {
            $table->string('customer_sms_sender_scope')->default('platform')->after('otp_provider');
            $table->string('customer_sms_provider')->nullable()->after('customer_sms_sender_scope');

            $table->index(
                ['customer_sms_sender_scope', 'customer_sms_provider'],
                'customer_auth_settings_customer_sms_scope_provider_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_auth_settings', function (Blueprint $table): void {
            $table->dropIndex('customer_auth_settings_customer_sms_scope_provider_index');
            $table->dropColumn(['customer_sms_sender_scope', 'customer_sms_provider']);
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('enable_customer_notifications');
        });
    }
};
