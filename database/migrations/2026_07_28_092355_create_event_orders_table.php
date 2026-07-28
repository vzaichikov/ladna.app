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
        Schema::create('event_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('provider')->nullable()->index();
            $table->string('order_id')->unique();
            $table->string('status')->default('pending')->index();
            $table->string('buyer_name');
            $table->string('buyer_email')->index();
            $table->string('buyer_phone')->nullable();
            $table->string('locale', 8)->default('uk');
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->char('currency', 3);
            $table->text('access_token_encrypted');
            $table->char('access_token_hash', 64)->unique();
            $table->string('gateway_invoice_id')->nullable();
            $table->string('gateway_payment_id')->nullable();
            $table->string('gateway_status')->nullable();
            $table->text('gateway_checkout_payload')->nullable();
            $table->text('last_callback_payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->dateTime('expires_at')->nullable()->index();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('terms_accepted_at');
            $table->char('terms_hash', 64);
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('refunded_at')->nullable();
            $table->text('refund_reason')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'event_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_orders');
    }
};
