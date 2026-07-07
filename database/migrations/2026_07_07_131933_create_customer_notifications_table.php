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
        Schema::create('customer_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scheduled_class_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('class_booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel')->default('sms');
            $table->string('type');
            $table->string('status')->default('pending');
            $table->string('recipient_kind')->default('customer');
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->text('text')->nullable();
            $table->json('payload')->nullable();
            $table->string('provider_scope')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('scheduled_send_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_send_at', 'next_attempt_at', 'id'], 'customer_notifications_pending_lookup_index');
            $table->index(['account_id', 'type', 'status', 'created_at'], 'customer_notifications_account_type_status_index');
            $table->index(['scheduled_class_id', 'type', 'status'], 'customer_notifications_class_type_status_index');
            $table->index(['class_booking_id', 'type', 'status'], 'customer_notifications_booking_type_status_index');
            $table->index(['recipient_phone', 'status'], 'customer_notifications_phone_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_notifications');
    }
};
