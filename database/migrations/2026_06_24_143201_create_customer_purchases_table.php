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
        Schema::create('customer_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_pass_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_class_pass_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('order_id')->unique();
            $table->string('gateway_invoice_id')->nullable();
            $table->string('gateway_payment_id')->nullable();
            $table->string('gateway_status')->nullable();
            $table->string('status')->default('payment_started');
            $table->string('plan_name');
            $table->string('plan_slug')->nullable();
            $table->string('schedule_kind');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('UAH');
            $table->unsignedSmallInteger('sessions_count');
            $table->unsignedSmallInteger('validity_days');
            $table->text('gateway_checkout_payload')->nullable();
            $table->text('last_callback_payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['account_id', 'customer_id', 'created_at']);
            $table->index(['account_id', 'provider', 'status']);
            $table->index(['class_pass_plan_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_purchases');
    }
};
