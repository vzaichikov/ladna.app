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
        Schema::create('event_cash_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_order_id')->constrained()->restrictOnDelete();
            $table->string('source_key', 191)->unique();
            $table->string('direction');
            $table->string('purpose');
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role')->nullable();
            $table->text('reason');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['event_order_id', 'purpose'], 'event_cash_entries_order_purpose_unique');
            $table->index(['event_id', 'currency', 'id'], 'event_cash_entries_balance_index');
            $table->index(['event_id', 'occurred_at'], 'event_cash_entries_history_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_cash_entries');
    }
};
