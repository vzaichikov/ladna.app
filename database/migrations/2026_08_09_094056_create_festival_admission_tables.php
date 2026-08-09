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
        Schema::create('festival_admission_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('inventory');
            $table->unsignedBigInteger('price_cents');
            $table->unsignedBigInteger('early_bird_price_cents')->nullable();
            $table->timestamp('early_bird_ends_at')->nullable();
            $table->unsignedInteger('early_bird_quota')->nullable();
            $table->timestamp('sales_starts_at')->nullable();
            $table->timestamp('sales_ends_at')->nullable();
            $table->unsignedSmallInteger('max_per_order')->default(10);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['festival_edition_id', 'is_active', 'sort_order'], 'festival_admission_types_active_idx');
        });

        Schema::create('festival_ticket_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_portal_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->nullable();
            $table->string('order_id')->unique();
            $table->string('status')->default('pending')->index();
            $table->string('buyer_name');
            $table->string('buyer_email');
            $table->string('buyer_phone')->nullable();
            $table->string('locale', 5)->default('uk');
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            $table->text('access_token_encrypted');
            $table->char('access_token_hash', 64)->unique();
            $table->string('gateway_invoice_id')->nullable();
            $table->string('gateway_payment_id')->nullable();
            $table->string('gateway_status')->nullable();
            $table->json('gateway_checkout_payload')->nullable();
            $table->json('last_callback_payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('terms_accepted_at');
            $table->char('terms_hash', 64);
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();
            $table->text('refund_reason')->nullable();
            $table->timestamps();
            $table->index(['festival_edition_id', 'status', 'created_at'], 'festival_ticket_orders_state_idx');
        });

        Schema::create('festival_ticket_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_ticket_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_admission_type_id')->constrained()->restrictOnDelete();
            $table->string('admission_name');
            $table->text('admission_description')->nullable();
            $table->string('price_tier')->default('regular');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('total_cents');
            $table->timestamps();
        });

        Schema::create('festival_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_ticket_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_ticket_order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_admission_type_id')->constrained()->restrictOnDelete();
            $table->string('code')->unique();
            $table->text('token_encrypted');
            $table->char('token_hash', 64)->unique();
            $table->string('status')->default('valid')->index();
            $table->boolean('is_checked_in')->default(false)->index();
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->index(['festival_edition_id', 'status', 'is_checked_in'], 'festival_tickets_scan_state_idx');
        });

        Schema::create('festival_ticket_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('source')->default('qr');
            $table->string('request_ip')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['festival_ticket_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_ticket_scans');
        Schema::dropIfExists('festival_tickets');
        Schema::dropIfExists('festival_ticket_order_items');
        Schema::dropIfExists('festival_ticket_orders');
        Schema::dropIfExists('festival_admission_types');
    }
};
