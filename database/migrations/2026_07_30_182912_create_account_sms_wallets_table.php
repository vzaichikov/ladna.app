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
        Schema::create('account_sms_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')
                ->unique()
                ->constrained()
                ->restrictOnDelete();
            $table->unsignedBigInteger('balance_cents')->default(0);
            $table->unsignedBigInteger('reserved_cents')->default(0);
            $table->unsignedBigInteger('outstanding_cents')->default(0);
            $table->char('currency', 3)->default('UAH');
            $table->boolean('auto_top_up_enabled')->default(false);
            $table->unsignedBigInteger('auto_top_up_threshold_cents')->nullable();
            $table->unsignedBigInteger('auto_top_up_target_cents')->nullable();
            $table->unsignedBigInteger('auto_top_up_monthly_cap_cents')->nullable();
            $table->unsignedBigInteger('auto_top_up_monthly_spent_cents')->default(0);
            $table->date('auto_top_up_monthly_period')->nullable();
            $table->timestamp('auto_top_up_suspended_at')->nullable();
            $table->timestamp('last_low_balance_warning_at')->nullable();
            $table->timestamp('last_auto_top_up_failure_warning_at')->nullable();
            $table->timestamp('last_outstanding_warning_at')->nullable();
            $table->timestamps();

            $table->index(
                ['auto_top_up_enabled', 'auto_top_up_suspended_at'],
                'sms_wallets_auto_top_up_idx',
            );
            $table->index('outstanding_cents', 'sms_wallets_outstanding_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_sms_wallets');
    }
};
