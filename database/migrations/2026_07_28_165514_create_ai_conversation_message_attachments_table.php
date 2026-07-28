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
        Schema::create('ai_conversation_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_conversation_message_id')
                ->constrained(indexName: 'ai_msg_attachments_message_fk')
                ->cascadeOnDelete();
            $table->string('source');
            $table->string('disk')->default('local');
            $table->string('path')->unique();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();

            $table->unique('ai_conversation_message_id', 'ai_msg_attachments_message_unique');
            $table->index(['account_id', 'created_at'], 'ai_message_attachments_account_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_message_attachments');
    }
};
