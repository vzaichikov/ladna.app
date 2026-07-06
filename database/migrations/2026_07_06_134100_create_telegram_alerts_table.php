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
        Schema::create('telegram_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scheduled_class_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('class_booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('telegram_bot_installation_id')->nullable();
            $table->foreignId('telegram_chat_authorization_id')->nullable();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->string('recipient_kind')->default('trainer');
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->string('telegram_chat_id')->nullable();
            $table->string('telegram_message_id')->nullable();
            $table->string('telegram_user_id')->nullable();
            $table->text('text')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at', 'id'], 'telegram_alerts_pending_lookup_index');
            $table->index(['account_id', 'type', 'status', 'created_at'], 'telegram_alerts_account_type_status_index');
            $table->index(['trainer_id', 'status'], 'telegram_alerts_trainer_status_index');
            $table->index(['scheduled_class_id', 'type'], 'telegram_alerts_class_type_index');
            $table->index(['class_booking_id', 'type'], 'telegram_alerts_booking_type_index');
            $table->foreign('telegram_bot_installation_id', 'telegram_alerts_installation_fk')
                ->references('id')
                ->on('telegram_bot_installations')
                ->nullOnDelete();
            $table->foreign('telegram_chat_authorization_id', 'telegram_alerts_authorization_fk')
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
        Schema::dropIfExists('telegram_alerts');
    }
};
