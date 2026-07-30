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
        Schema::create('ai_usage_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('last_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedSmallInteger('consecutive_out_of_scope_count')->default(0);
            $table->unsignedTinyInteger('cooldown_level')->default(0);
            $table->string('blocked_reason')->nullable();
            $table->timestamp('blocked_until')->nullable()->index();
            $table->timestamp('last_out_of_scope_at')->nullable();
            $table->timestamp('last_blocked_at')->nullable();
            $table->string('last_channel')->nullable();
            $table->timestamp('manually_unblocked_at')->nullable();
            $table->foreignId('manually_unblocked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['last_account_id', 'blocked_until'], 'ai_usage_restrictions_account_block_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage_restrictions');
    }
};
