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
        Schema::create('platform_ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('owner_ai_assistant_enabled')->default(false);
            $table->string('active_provider')->nullable();
            $table->string('active_model')->nullable();
            $table->string('bot_display_name')->nullable();
            $table->text('internal_instructions')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_ai_provider_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->unique();
            $table->string('model')->nullable();
            $table->longText('credentials')->nullable();
            $table->boolean('is_configured')->default(false);
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_ai_provider_credentials');
        Schema::dropIfExists('platform_ai_settings');
    }
};
