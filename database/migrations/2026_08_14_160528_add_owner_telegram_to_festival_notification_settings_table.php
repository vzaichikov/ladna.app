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
        Schema::table('festival_notification_settings', function (Blueprint $table): void {
            $table->boolean('notify_owner_telegram')->default(false)->after('send_sms');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_notification_settings', function (Blueprint $table): void {
            $table->dropColumn('notify_owner_telegram');
        });
    }
};
