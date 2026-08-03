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
        Schema::table('platform_ai_settings', function (Blueprint $table): void {
            $table->boolean('owner_voice_input_enabled')
                ->default(false)
                ->after('owner_ai_assistant_enabled');
            $table->string('owner_voice_recognition_provider')
                ->default('openai')
                ->after('owner_voice_input_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_ai_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'owner_voice_input_enabled',
                'owner_voice_recognition_provider',
            ]);
        });
    }
};
