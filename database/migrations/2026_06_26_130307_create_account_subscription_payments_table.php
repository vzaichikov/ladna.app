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
        Schema::create('account_subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('account_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_signup_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('monopay')->index();
            $table->string('payment_type')->index();
            $table->string('order_id')->unique();
            $table->string('gateway_invoice_id')->nullable()->index();
            $table->string('gateway_payment_id')->nullable()->index();
            $table->string('gateway_subscription_id')->nullable()->index();
            $table->string('gateway_status')->nullable();
            $table->string('status')->default('payment_started')->index();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('UAH');
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->text('gateway_checkout_payload')->nullable();
            $table->text('last_callback_payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index(['account_subscription_id', 'status'], 'sub_payments_subscription_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_subscription_payments');
    }
};
