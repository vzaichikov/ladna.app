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
            $table->string('otp_sender_scope')->default('account')->change();
            $table->string('customer_sms_sender_scope')->default('account')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_auth_settings', function (Blueprint $table): void {
            $table->string('otp_sender_scope')->default('platform')->change();
            $table->string('customer_sms_sender_scope')->default('platform')->change();
        });
    }
};
