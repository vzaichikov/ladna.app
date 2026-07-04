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
        Schema::create('class_booking_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('old_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('new_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('previous_customer_class_pass_id')->nullable();
            $table->foreignId('new_customer_class_pass_id')->nullable();
            $table->foreignId('customer_class_pass_reservation_id')->nullable();
            $table->foreignId('manual_cash_payment_id')->nullable()->constrained('customer_purchases')->nullOnDelete();
            $table->string('action');
            $table->string('pass_effect')->nullable();
            $table->string('old_customer_name')->nullable();
            $table->string('new_customer_name')->nullable();
            $table->string('previous_booking_status')->nullable();
            $table->string('new_booking_status')->nullable();
            $table->string('previous_reservation_status')->nullable();
            $table->string('new_reservation_status')->nullable();
            $table->timestamp('previous_reserved_at')->nullable();
            $table->timestamp('new_reserved_at')->nullable();
            $table->timestamp('previous_used_at')->nullable();
            $table->timestamp('new_used_at')->nullable();
            $table->timestamp('previous_released_at')->nullable();
            $table->timestamp('new_released_at')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_trainer_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role')->nullable();
            $table->text('reason');
            $table->timestamps();
            $table->index(['account_id', 'scheduled_class_id'], 'class_corrections_class_index');
            $table->index(['account_id', 'action', 'created_at'], 'class_corrections_action_index');
            $table->foreign('previous_customer_class_pass_id', 'class_corr_prev_pass_fk')
                ->references('id')
                ->on('customer_class_passes')
                ->nullOnDelete();
            $table->foreign('new_customer_class_pass_id', 'class_corr_new_pass_fk')
                ->references('id')
                ->on('customer_class_passes')
                ->nullOnDelete();
            $table->foreign('customer_class_pass_reservation_id', 'class_corr_reservation_fk')
                ->references('id')
                ->on('customer_class_pass_reservations')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_booking_corrections');
    }
};
