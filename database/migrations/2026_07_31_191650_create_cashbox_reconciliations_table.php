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
        if (Schema::hasTable('cashbox_reconciliations')) {
            Schema::table('cashbox_reconciliations', function (Blueprint $table) {
                $table->foreign('finance_epoch_id')->references('id')->on('finance_epochs')->cascadeOnDelete();
                $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
                $table->foreign('cutoff_cash_entry_id')->references('id')->on('studio_cash_entries')->nullOnDelete();
                $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('actor_trainer_id')->references('id')->on('trainers')->nullOnDelete();
                $table->unique('idempotency_key');
                $table->index(['account_id', 'finance_epoch_id', 'location_id', 'currency', 'id'], 'cashbox_reconciliation_balance_idx');
                $table->index(['account_id', 'occurred_at']);
            });

            return;
        }

        Schema::create('cashbox_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_epoch_id')->constrained('finance_epochs')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('cutoff_cash_entry_id')->nullable()->constrained('studio_cash_entries')->nullOnDelete();
            $table->string('kind', 32);
            $table->char('currency', 3)->default('UAH');
            $table->bigInteger('expected_before_cents');
            $table->bigInteger('actual_counted_cents');
            $table->bigInteger('variance_cents');
            $table->uuid('idempotency_key')->unique();
            $table->timestamp('occurred_at');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actor_trainer_id')->nullable()->constrained('trainers')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role')->nullable();
            $table->text('reason');
            $table->timestamps();

            $table->index(['account_id', 'finance_epoch_id', 'location_id', 'currency', 'id'], 'cashbox_reconciliation_balance_idx');
            $table->index(['account_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cashbox_reconciliations');
    }
};
