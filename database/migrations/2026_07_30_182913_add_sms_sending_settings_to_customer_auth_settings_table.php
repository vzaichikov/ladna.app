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
        Schema::table('customer_auth_settings', function (Blueprint $table): void {
            $table->string('sms_sending_mode')
                ->default('disabled')
                ->after('allow_google');
            $table->string('sms_provider')
                ->nullable()
                ->after('sms_sending_mode');

            $table->index(
                ['sms_sending_mode', 'sms_provider'],
                'customer_auth_settings_sms_mode_provider_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_auth_settings', function (Blueprint $table): void {
            $table->dropIndex('customer_auth_settings_sms_mode_provider_idx');
            $table->dropColumn(['sms_sending_mode', 'sms_provider']);
        });
    }
};
