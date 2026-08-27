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
        Schema::table('festival_notifications', function (Blueprint $table) {
            $table->foreignId('telegram_chat_authorization_id')
                ->nullable()
                ->after('festival_ticket_order_id');
            $table->index(
                ['telegram_chat_authorization_id', 'channel', 'status'],
                'festival_notifications_telegram_delivery_idx',
            );
            $table->foreign('telegram_chat_authorization_id', 'festival_notifications_telegram_auth_fk')
                ->references('id')->on('telegram_chat_authorizations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_notifications', function (Blueprint $table) {
            $table->dropIndex('festival_notifications_telegram_delivery_idx');
            $table->dropForeign('festival_notifications_telegram_auth_fk');
            $table->dropColumn('telegram_chat_authorization_id');
        });
    }
};
