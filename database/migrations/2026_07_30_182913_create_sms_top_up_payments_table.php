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
        Schema::create('sms_top_up_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('account_sms_wallet_id')
                ->constrained()
                ->restrictOnDelete();
            $table->foreignId('account_subscription_payment_method_id')
                ->nullable()
                ->constrained(
                    table: 'account_subscription_payment_methods',
                    indexName: 'sms_topups_payment_method_idx',
                )
                ->nullOnDelete();
            $table->string('provider')->default('monopay');
            $table->string('kind')->default('manual');
            $table->string('order_id')->unique();
            $table->string('gateway_invoice_id')->nullable()->unique();
            $table->string('gateway_payment_id')->nullable()->unique();
            $table->string('gateway_status')->nullable();
            $table->string('status')->default('payment_started');
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('UAH');
            $table->string('idempotency_key', 191)->unique();
            $table->text('gateway_checkout_payload')->nullable();
            $table->text('last_callback_payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['account_id', 'status', 'created_at'],
                'sms_topups_account_status_created_idx',
            );
            $table->index(
                ['account_id', 'kind', 'paid_at'],
                'sms_topups_account_kind_paid_idx',
            );
            $table->index(
                ['kind', 'status'],
                'sms_topups_kind_status_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_top_up_payments');
    }
};
