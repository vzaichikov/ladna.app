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
        Schema::table('class_bookings', function (Blueprint $table) {
            $table->boolean('skip_class_pass_reservation')
                ->default(false)
                ->after('notes')
                ->index();
        });

        Schema::table('customer_purchases', function (Blueprint $table) {
            $table->foreignId('class_booking_id')
                ->nullable()
                ->after('customer_class_pass_id')
                ->constrained('class_bookings')
                ->nullOnDelete();

            $table->index(['account_id', 'class_booking_id', 'payment_source'], 'customer_purchases_booking_source_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_purchases', function (Blueprint $table) {
            $table->dropIndex('customer_purchases_booking_source_idx');
            $table->dropConstrainedForeignId('class_booking_id');
        });

        Schema::table('class_bookings', function (Blueprint $table) {
            $table->dropIndex(['skip_class_pass_reservation']);
            $table->dropColumn('skip_class_pass_reservation');
        });
    }
};
