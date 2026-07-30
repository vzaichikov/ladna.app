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
        Schema::table('platform_ai_settings', function (Blueprint $table) {
            $table->boolean('firewall_enabled')->default(true);
            $table->unsignedSmallInteger('firewall_user_turns_per_minute')->default(6);
            $table->unsignedSmallInteger('firewall_user_turns_per_hour')->default(30);
            $table->unsignedInteger('firewall_user_turns_per_day')->default(100);
            $table->unsignedSmallInteger('firewall_admin_turns_per_minute')->default(20);
            $table->unsignedSmallInteger('firewall_admin_turns_per_hour')->default(100);
            $table->unsignedInteger('firewall_admin_turns_per_day')->default(500);
            $table->unsignedInteger('firewall_account_turns_per_day')->default(500);
            $table->unsignedSmallInteger('firewall_user_provider_calls_per_hour')->default(90);
            $table->unsignedInteger('firewall_user_provider_calls_per_day')->default(300);
            $table->unsignedSmallInteger('firewall_admin_provider_calls_per_hour')->default(300);
            $table->unsignedInteger('firewall_admin_provider_calls_per_day')->default(1500);
            $table->unsignedInteger('firewall_account_provider_calls_per_day')->default(1500);
            $table->unsignedSmallInteger('firewall_user_out_of_scope_streak')->default(5);
            $table->unsignedSmallInteger('firewall_admin_out_of_scope_streak')->default(10);
            $table->unsignedInteger('firewall_cooldown_first_minutes')->default(60);
            $table->unsignedInteger('firewall_cooldown_second_minutes')->default(360);
            $table->unsignedInteger('firewall_cooldown_third_minutes')->default(1440);
            $table->unsignedSmallInteger('firewall_escalation_reset_days')->default(7);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_ai_settings', function (Blueprint $table) {
            $table->dropColumn([
                'firewall_enabled',
                'firewall_user_turns_per_minute',
                'firewall_user_turns_per_hour',
                'firewall_user_turns_per_day',
                'firewall_admin_turns_per_minute',
                'firewall_admin_turns_per_hour',
                'firewall_admin_turns_per_day',
                'firewall_account_turns_per_day',
                'firewall_user_provider_calls_per_hour',
                'firewall_user_provider_calls_per_day',
                'firewall_admin_provider_calls_per_hour',
                'firewall_admin_provider_calls_per_day',
                'firewall_account_provider_calls_per_day',
                'firewall_user_out_of_scope_streak',
                'firewall_admin_out_of_scope_streak',
                'firewall_cooldown_first_minutes',
                'firewall_cooldown_second_minutes',
                'firewall_cooldown_third_minutes',
                'firewall_escalation_reset_days',
            ]);
        });
    }
};
