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
        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_chat_authorization_id')->nullable();
            $table->string('channel')->default('telegram');
            $table->string('profile');
            $table->string('status')->default('active');
            $table->string('title')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'profile', 'status', 'last_message_at'], 'ai_conversations_account_lookup_index');
            $table->index(['telegram_chat_authorization_id', 'status'], 'ai_conversations_telegram_auth_lookup_index');
            $table->foreign('telegram_chat_authorization_id', 'ai_conversations_tg_auth_fk')
                ->references('id')
                ->on('telegram_chat_authorizations')
                ->nullOnDelete();
        });

        Schema::create('ai_conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_message_id')->nullable();
            $table->string('role');
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->unsignedInteger('token_count')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['ai_conversation_id', 'occurred_at'], 'ai_conversation_messages_conversation_lookup_index');
            $table->index(['account_id', 'role', 'occurred_at'], 'ai_conversation_messages_account_role_index');
            $table->foreign('telegram_message_id', 'ai_messages_tg_message_fk')
                ->references('id')
                ->on('telegram_messages')
                ->nullOnDelete();
        });

        Schema::create('mcp_tool_invocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_api_token_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_conversation_message_id')->nullable();
            $table->string('tool_name');
            $table->string('required_ability')->nullable();
            $table->string('status');
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'tool_name', 'status', 'started_at'], 'mcp_tool_invocations_account_lookup_index');
            $table->index(['account_api_token_id', 'started_at'], 'mcp_tool_invocations_token_lookup_index');
            $table->foreign('ai_conversation_message_id', 'mcp_invocations_ai_message_fk')
                ->references('id')
                ->on('ai_conversation_messages')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_invocations');
        Schema::dropIfExists('ai_conversation_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
