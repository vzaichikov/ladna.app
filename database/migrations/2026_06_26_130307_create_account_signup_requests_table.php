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
        Schema::create('account_signup_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending_payment')->index();
            $table->string('provider')->default('monopay')->index();
            $table->string('order_id')->unique();
            $table->string('gateway_invoice_id')->nullable()->index();
            $table->string('gateway_status')->nullable();
            $table->string('studio_name');
            $table->string('account_slug')->unique();
            $table->string('owner_name');
            $table->string('owner_email')->index();
            $table->string('owner_phone')->nullable();
            $table->string('owner_password');
            $table->string('default_language', 5)->default('uk');
            $table->string('timezone')->default('Europe/Kyiv');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('UAH');
            $table->text('gateway_checkout_payload')->nullable();
            $table->text('last_callback_payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_signup_requests');
    }
};
