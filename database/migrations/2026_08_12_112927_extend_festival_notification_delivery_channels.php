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
            $table->boolean('send_sms')->default(false)->after('is_optional');
        });

        Schema::table('festival_notifications', function (Blueprint $table): void {
            $table->foreignId('festival_ticket_order_id')
                ->nullable()
                ->after('festival_entry_id')
                ->constrained('festival_ticket_orders')
                ->nullOnDelete();
            $table->string('recipient_phone')->nullable()->after('recipient_email');
            $table->string('subject')->nullable()->after('recipient_name');
            $table->text('text')->nullable()->after('subject');
            $table->index(['festival_ticket_order_id', 'channel'], 'festival_notifications_ticket_order_channel_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_notifications', function (Blueprint $table): void {
            $table->dropIndex('festival_notifications_ticket_order_channel_idx');
            $table->dropConstrainedForeignId('festival_ticket_order_id');
            $table->dropColumn(['recipient_phone', 'subject', 'text']);
        });

        Schema::table('festival_notification_settings', function (Blueprint $table): void {
            $table->dropColumn('send_sms');
        });
    }
};
