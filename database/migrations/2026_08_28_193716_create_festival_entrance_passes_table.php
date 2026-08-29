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
        Schema::create('festival_entrance_passes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_participant_id')->constrained()->restrictOnDelete();
            $table->string('code')->unique();
            $table->text('token_encrypted');
            $table->char('token_hash', 64)->unique();
            $table->string('status')->default('valid')->index();
            $table->boolean('is_checked_in')->default(false)->index();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->text('disabled_reason')->nullable();
            $table->timestamp('credentials_rotated_at')->nullable();
            $table->timestamps();
            $table->unique(['festival_edition_id', 'festival_participant_id'], 'festival_entrance_pass_participant_unique');
            $table->index(['festival_edition_id', 'status', 'is_checked_in'], 'festival_entrance_passes_scan_state_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_entrance_passes');
    }
};
