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
        if (! Schema::hasTable('festival_tariff_packages')) {
            Schema::create('festival_tariff_packages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
                $table->string('name');
                $table->unsignedInteger('price_cents');
                $table->char('currency', 3)->default('UAH');
                $table->unsignedInteger('max_participants');
                $table->unsignedInteger('max_tickets');
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['subscription_plan_id', 'name']);
                $table->index(['subscription_plan_id', 'is_active', 'sort_order'], 'festival_tariff_packages_active_idx');
            });
        }

        if (! Schema::hasTable('festival_edition_purchases')) {
            Schema::create('festival_edition_purchases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
                $table->foreignId('festival_tariff_package_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('account_subscription_payment_method_id')->nullable()->constrained(indexName: 'festival_purchase_method_fk')->nullOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users', indexName: 'festival_purchase_creator_fk')->nullOnDelete();
                $table->foreignId('festival_edition_id')->nullable()->constrained(indexName: 'festival_purchase_edition_fk')->nullOnDelete();
                $table->string('provider')->nullable();
                $table->string('status');
                $table->string('order_id')->nullable();
                $table->string('gateway_invoice_id')->nullable();
                $table->string('gateway_payment_id')->nullable();
                $table->string('gateway_status')->nullable();
                $table->unsignedInteger('amount_cents');
                $table->char('currency', 3);
                $table->string('tariff_name_snapshot');
                $table->string('package_name_snapshot');
                $table->unsignedInteger('max_participants');
                $table->unsignedInteger('max_tickets');
                $table->string('idempotency_key');
                $table->text('gateway_checkout_payload')->nullable();
                $table->text('last_callback_payload')->nullable();
                $table->text('failure_reason')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('expired_at')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->timestamp('redeemed_at')->nullable();
                $table->timestamps();

                $table->unique('festival_edition_id', 'festival_purchase_edition_unique');
                $table->unique('order_id', 'festival_purchase_order_unique');
                $table->unique('idempotency_key', 'festival_purchase_idempotency_unique');
                $table->index('status', 'festival_purchase_status_idx');
                $table->index('gateway_invoice_id', 'festival_purchase_invoice_idx');
                $table->index('gateway_payment_id', 'festival_purchase_payment_idx');
                $table->index(['account_id', 'status', 'created_at'], 'festival_edition_purchases_account_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_edition_purchases');
        Schema::dropIfExists('festival_tariff_packages');
    }
};
