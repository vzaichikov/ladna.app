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
        Schema::create('account_subscription_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id');
            $table->foreignId('account_subscription_id');
            $table->string('provider')->default('monopay');
            $table->text('provider_wallet_id');
            $table->text('provider_card_token')->nullable();
            $table->string('masked_pan')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('status')->default('pending_verification');
            $table->string('verification_reference');
            $table->string('verification_invoice_id')->nullable();
            $table->text('last_callback_payload')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('account_id', 'saas_payment_methods_account_fk')
                ->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('account_subscription_id', 'saas_payment_methods_subscription_fk')
                ->references('id')->on('account_subscriptions')->cascadeOnDelete();
            $table->unique('account_id', 'saas_payment_methods_account_unique');
            $table->unique('account_subscription_id', 'saas_payment_methods_subscription_unique');
            $table->index('provider', 'saas_payment_methods_provider_idx');
            $table->index('status', 'saas_payment_methods_status_idx');
            $table->unique('verification_reference', 'saas_payment_methods_verify_ref_unique');
            $table->unique('verification_invoice_id', 'saas_payment_methods_verify_invoice_unique');
            $table->index('verified_at', 'saas_payment_methods_verified_idx');
            $table->index('revoked_at', 'saas_payment_methods_revoked_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_subscription_payment_methods');
    }
};
