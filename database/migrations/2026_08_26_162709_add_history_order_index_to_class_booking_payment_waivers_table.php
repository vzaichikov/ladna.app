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
        Schema::table('class_booking_payment_waivers', function (Blueprint $table) {
            $table->index(
                ['account_id', 'waived_at', 'id'],
                'booking_payment_waivers_history_order_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_booking_payment_waivers', function (Blueprint $table) {
            $table->dropIndex('booking_payment_waivers_history_order_index');
        });
    }
};
