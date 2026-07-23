<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_broadcast_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_bot_installation_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('purpose');
            $table->string('telegram_chat_id');
            $table->string('title');
            $table->string('chat_type')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['telegram_bot_installation_id', 'purpose'],
                'telegram_broadcast_targets_installation_purpose_unique',
            );
            $table->unique(
                ['telegram_bot_installation_id', 'telegram_chat_id'],
                'telegram_broadcast_targets_installation_chat_unique',
            );
            $table->index(
                ['purpose', 'is_enabled'],
                'telegram_broadcast_targets_purpose_enabled_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_broadcast_targets');
    }
};
