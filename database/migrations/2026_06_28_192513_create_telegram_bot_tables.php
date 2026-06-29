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
        Schema::create('telegram_bot_installations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('profile');
            $table->string('bot_username')->nullable();
            $table->longText('encrypted_token')->nullable();
            $table->string('token_last_four', 16)->nullable();
            $table->longText('encrypted_webhook_key')->nullable();
            $table->string('webhook_key_hash', 64)->unique();
            $table->longText('encrypted_webhook_secret')->nullable();
            $table->string('webhook_secret_token_hash', 64)->nullable();
            $table->string('webhook_url')->nullable();
            $table->string('status')->default('pending');
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('last_webhook_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'profile']);
            $table->index(['profile', 'is_enabled']);
        });

        Schema::create('telegram_bot_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('profile');
            $table->string('mode')->default('disabled');
            $table->boolean('is_enabled')->default(false);
            $table->text('welcome_message')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'profile']);
            $table->index(['profile', 'mode', 'is_enabled']);
        });

        Schema::create('telegram_chat_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_bot_installation_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('profile');
            $table->string('telegram_chat_id');
            $table->string('telegram_user_id')->nullable();
            $table->string('telegram_username')->nullable();
            $table->string('phone')->nullable();
            $table->string('status')->default('authorized');
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['telegram_bot_installation_id', 'telegram_chat_id'], 'telegram_chat_authorizations_installation_chat_unique');
            $table->index(['account_id', 'profile', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['trainer_id', 'status']);
            $table->foreign('telegram_bot_installation_id', 'tg_chat_auth_installation_fk')
                ->references('id')
                ->on('telegram_bot_installations')
                ->cascadeOnDelete();
        });

        Schema::create('telegram_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_bot_installation_id');
            $table->string('profile');
            $table->unsignedBigInteger('update_id');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['telegram_bot_installation_id', 'update_id']);
            $table->index(['account_id', 'status', 'received_at']);
            $table->foreign('telegram_bot_installation_id', 'tg_updates_installation_fk')
                ->references('id')
                ->on('telegram_bot_installations')
                ->cascadeOnDelete();
        });

        Schema::create('telegram_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_bot_installation_id');
            $table->foreignId('telegram_chat_authorization_id')->nullable();
            $table->foreignId('telegram_update_id')->nullable();
            $table->string('profile');
            $table->string('telegram_chat_id');
            $table->string('telegram_message_id')->nullable();
            $table->string('telegram_user_id')->nullable();
            $table->string('direction');
            $table->string('message_type')->default('text');
            $table->text('text')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'profile', 'telegram_chat_id', 'sent_at'], 'telegram_messages_chat_lookup_index');
            $table->index(['telegram_chat_authorization_id', 'sent_at'], 'telegram_messages_authorization_lookup_index');
            $table->foreign('telegram_bot_installation_id', 'tg_messages_installation_fk')
                ->references('id')
                ->on('telegram_bot_installations')
                ->cascadeOnDelete();
            $table->foreign('telegram_chat_authorization_id', 'tg_messages_auth_fk')
                ->references('id')
                ->on('telegram_chat_authorizations')
                ->nullOnDelete();
            $table->foreign('telegram_update_id', 'tg_messages_update_fk')
                ->references('id')
                ->on('telegram_updates')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
        Schema::dropIfExists('telegram_updates');
        Schema::dropIfExists('telegram_chat_authorizations');
        Schema::dropIfExists('telegram_bot_profiles');
        Schema::dropIfExists('telegram_bot_installations');
    }
};
