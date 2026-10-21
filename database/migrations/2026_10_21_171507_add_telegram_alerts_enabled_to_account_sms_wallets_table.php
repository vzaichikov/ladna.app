<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_sms_wallets', function (Blueprint $table): void {
            $table->boolean('telegram_alerts_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('account_sms_wallets', function (Blueprint $table): void {
            $table->dropColumn('telegram_alerts_enabled');
        });
    }
};
