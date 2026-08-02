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
        Schema::table('customer_notifications', function (Blueprint $table): void {
            $table->string('resolved_channel')->nullable()->after('channel');
            $table->foreignId('telegram_chat_authorization_id')->nullable()->after('class_booking_id');
            $table->timestamp('fallback_used_at')->nullable()->after('skipped_at');
            $table->index(
                ['telegram_chat_authorization_id', 'status'],
                'customer_notifications_telegram_auth_status_index',
            );
            $table->foreign('telegram_chat_authorization_id', 'customer_notifications_telegram_auth_fk')
                ->references('id')
                ->on('telegram_chat_authorizations')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table): void {
            $table->dropForeign('customer_notifications_telegram_auth_fk');
            $table->dropIndex('customer_notifications_telegram_auth_status_index');
            $table->dropColumn([
                'resolved_channel',
                'telegram_chat_authorization_id',
                'fallback_used_at',
            ]);
        });
    }
};
