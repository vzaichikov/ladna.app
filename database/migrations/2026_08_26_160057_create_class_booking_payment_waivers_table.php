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
        Schema::create('class_booking_payment_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_class_pass_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_due_kind', 40);
            $table->unsignedInteger('amount_cents')->nullable();
            $table->string('currency', 3);
            $table->string('customer_name');
            $table->string('scheduled_class_title');
            $table->timestamp('scheduled_class_starts_at');
            $table->string('scheduled_class_timezone', 64);
            $table->string('location_name')->nullable();
            $table->string('room_name')->nullable();
            $table->string('customer_class_pass_code')->nullable();
            $table->text('reason');
            $table->timestamp('waived_at');
            $table->unsignedBigInteger('waived_by_actor_user_id')->nullable();
            $table->unsignedBigInteger('waived_by_actor_trainer_id')->nullable();
            $table->string('waived_by_actor_name')->nullable();
            $table->string('waived_by_actor_email')->nullable();
            $table->string('waived_by_actor_role')->nullable();
            $table->timestamp('unwaived_at')->nullable();
            $table->text('unwaive_reason')->nullable();
            $table->unsignedBigInteger('unwaived_by_actor_user_id')->nullable();
            $table->unsignedBigInteger('unwaived_by_actor_trainer_id')->nullable();
            $table->string('unwaived_by_actor_name')->nullable();
            $table->string('unwaived_by_actor_email')->nullable();
            $table->string('unwaived_by_actor_role')->nullable();
            $table->timestamps();

            $table->index(
                ['account_id', 'unwaived_at', 'waived_at'],
                'booking_payment_waivers_account_status_index',
            );
            $table->index(
                ['class_booking_id', 'unwaived_at'],
                'booking_payment_waivers_booking_status_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_booking_payment_waivers');
    }
};
