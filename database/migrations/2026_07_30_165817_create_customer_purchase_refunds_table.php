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
        Schema::create('customer_purchase_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('method');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('UAH');
            $table->timestamp('refunded_at')->index();
            $table->uuid('idempotency_key')->unique();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_trainer_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role')->nullable();
            $table->text('reason');
            $table->timestamps();

            $table->index(['account_id', 'refunded_at'], 'purchase_refunds_account_time_index');
            $table->index(['customer_purchase_id', 'refunded_at'], 'purchase_refunds_payment_time_index');
            $table->index(['account_id', 'method', 'refunded_at'], 'purchase_refunds_method_time_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_purchase_refunds');
    }
};
