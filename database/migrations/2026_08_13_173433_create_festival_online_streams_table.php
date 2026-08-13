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
        Schema::create('festival_online_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->string('path')->unique();
            $table->text('publisher_token_encrypted');
            $table->char('publisher_token_hash', 64)->unique();
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->string('playback_override')->default('automatic');
            $table->timestamps();
            $table->unique('festival_edition_id');
            $table->index(['account_id', 'is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_online_streams');
    }
};
