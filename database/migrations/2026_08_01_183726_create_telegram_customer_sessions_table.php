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
        Schema::create('telegram_customer_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_bot_installation_id');
            $table->foreignId('telegram_chat_authorization_id')->nullable();
            $table->string('telegram_chat_id');
            $table->string('telegram_user_id');
            $table->string('locale', 8)->default('uk');
            $table->string('state')->default('awaiting_contact');
            $table->longText('encrypted_context')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['telegram_bot_installation_id', 'telegram_chat_id'],
                'telegram_customer_sessions_installation_chat_unique',
            );
            $table->index(['account_id', 'state', 'expires_at'], 'telegram_customer_sessions_state_index');
            $table->foreign('telegram_bot_installation_id', 'tg_customer_sessions_installation_fk')
                ->references('id')
                ->on('telegram_bot_installations')
                ->cascadeOnDelete();
            $table->foreign('telegram_chat_authorization_id', 'tg_customer_sessions_authorization_fk')
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
        Schema::dropIfExists('telegram_customer_sessions');
    }
};
