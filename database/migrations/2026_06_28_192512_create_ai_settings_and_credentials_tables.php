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
        Schema::create('account_ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('active_provider')->nullable();
            $table->string('active_model')->nullable();
            $table->string('bot_display_name')->nullable();
            $table->text('studio_ai_instructions')->nullable();
            $table->timestamps();

            $table->index(['is_enabled', 'active_provider']);
        });

        Schema::create('account_ai_provider_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('model')->nullable();
            $table->longText('credentials')->nullable();
            $table->boolean('is_configured')->default(false);
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'provider']);
            $table->index(['provider', 'is_configured']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_ai_provider_credentials');
        Schema::dropIfExists('account_ai_settings');
    }
};
