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
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_epoch_id')->nullable()->constrained('finance_epochs')->nullOnDelete();
            $table->foreignId('supersedes_payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->string('cadence', 32);
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->string('status', 32)->default('closed');
            $table->json('totals');
            $table->boolean('incomplete')->default(false);
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'period_starts_on', 'period_ends_on'], 'payroll_runs_period_idx');
            $table->index(['account_id', 'status', 'closed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
