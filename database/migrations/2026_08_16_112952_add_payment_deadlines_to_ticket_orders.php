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
        Schema::table('event_orders', function (Blueprint $table) {
            $table->dateTime('payment_expires_at')->nullable()->after('failure_reason');
            $table->index(['status', 'expires_at'], 'event_orders_status_expires_at_index');
        });

        Schema::table('festival_ticket_orders', function (Blueprint $table) {
            $table->timestamp('payment_expires_at')->nullable()->after('failure_reason');
            $table->index(['status', 'expires_at'], 'festival_ticket_orders_status_expires_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_orders', function (Blueprint $table) {
            $table->dropIndex('event_orders_status_expires_at_index');
            $table->dropColumn('payment_expires_at');
        });

        Schema::table('festival_ticket_orders', function (Blueprint $table) {
            $table->dropIndex('festival_ticket_orders_status_expires_at_index');
            $table->dropColumn('payment_expires_at');
        });
    }
};
