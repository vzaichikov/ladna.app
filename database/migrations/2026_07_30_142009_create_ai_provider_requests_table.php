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
        Schema::create('ai_provider_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_conversation_message_id')->nullable();
            $table->string('channel');
            $table->string('provider');
            $table->string('model');
            $table->string('request_type');
            $table->unsignedSmallInteger('provider_round')->nullable();
            $table->string('status');
            $table->string('provider_request_id')->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('cached_input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('reasoning_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamps();

            $table->index(['account_id', 'started_at'], 'ai_provider_requests_account_started_index');
            $table->index(['user_id', 'started_at'], 'ai_provider_requests_user_started_index');
            $table->index(['channel', 'started_at'], 'ai_provider_requests_channel_started_index');
            $table->index(['provider', 'model', 'started_at'], 'ai_provider_requests_provider_model_index');
            $table->index(['status', 'started_at'], 'ai_provider_requests_status_started_index');
            $table->foreign('ai_conversation_message_id', 'ai_provider_requests_message_fk')
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
        Schema::dropIfExists('ai_provider_requests');
    }
};
