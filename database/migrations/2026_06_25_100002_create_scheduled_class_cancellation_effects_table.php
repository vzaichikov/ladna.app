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
        Schema::create('scheduled_class_cancellation_effects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id');
            $table->foreignId('scheduled_class_cancellation_id');
            $table->foreignId('class_booking_id');
            $table->foreignId('customer_class_pass_id')->nullable();
            $table->foreignId('customer_class_pass_reservation_id')->nullable();
            $table->string('previous_booking_status');
            $table->string('new_booking_status');
            $table->string('previous_reservation_status')->nullable();
            $table->string('new_reservation_status')->nullable();
            $table->timestamp('previous_reserved_at')->nullable();
            $table->timestamp('new_reserved_at')->nullable();
            $table->timestamp('previous_used_at')->nullable();
            $table->timestamp('new_used_at')->nullable();
            $table->timestamp('previous_released_at')->nullable();
            $table->timestamp('new_released_at')->nullable();
            $table->unsignedSmallInteger('added_sessions_count')->default(0);
            $table->unsignedSmallInteger('added_validity_days')->default(0);
            $table->unsignedSmallInteger('previous_sessions_count')->nullable();
            $table->unsignedSmallInteger('new_sessions_count')->nullable();
            $table->unsignedSmallInteger('previous_validity_days')->nullable();
            $table->unsignedSmallInteger('new_validity_days')->nullable();
            $table->unsignedSmallInteger('previous_total_validity_days')->nullable();
            $table->unsignedSmallInteger('new_total_validity_days')->nullable();
            $table->timestamp('reversed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['account_id', 'scheduled_class_cancellation_id'], 'class_cancellation_effects_account_event_index');
            $table->index(['customer_class_pass_id', 'reversed_at'], 'class_cancellation_effects_pass_reversed_index');

            $table->foreign('account_id', 'sc_cancel_effects_account_fk')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('scheduled_class_cancellation_id', 'sc_cancel_effects_cancel_fk')->references('id')->on('scheduled_class_cancellations')->cascadeOnDelete();
            $table->foreign('class_booking_id', 'sc_cancel_effects_booking_fk')->references('id')->on('class_bookings')->cascadeOnDelete();
            $table->foreign('customer_class_pass_id', 'sc_cancel_effects_pass_fk')->references('id')->on('customer_class_passes')->nullOnDelete();
            $table->foreign('customer_class_pass_reservation_id', 'sc_cancel_effects_reservation_fk')->references('id')->on('customer_class_pass_reservations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_class_cancellation_effects');
    }
};
