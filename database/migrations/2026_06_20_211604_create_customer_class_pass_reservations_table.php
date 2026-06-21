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
        Schema::create('customer_class_pass_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_class_pass_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_class_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('reserved');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique('class_booking_id', 'class_pass_reservation_booking_unique');
            $table->index(['customer_class_pass_id', 'status'], 'class_pass_reservation_pass_status_index');
            $table->index(['account_id', 'status'], 'class_pass_reservation_account_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_class_pass_reservations');
    }
};
