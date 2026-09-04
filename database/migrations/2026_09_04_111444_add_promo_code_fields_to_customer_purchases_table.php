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
        Schema::table('customer_purchases', function (Blueprint $table) {
            $table->foreignId('studio_promo_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('promo_name')->nullable();
            $table->string('promo_code', 64)->nullable();
            $table->string('promo_discount_type', 16)->nullable();
            $table->unsignedInteger('promo_discount_value')->nullable();
            $table->unsignedInteger('subtotal_cents')->nullable();
            $table->unsignedInteger('discount_cents')->default(0);
            $table->char('promo_email_hash', 64)->nullable();
            $table->char('promo_phone_hash', 64)->nullable();

            $table->index(['studio_promo_code_id', 'status', 'expires_at'], 'customer_purchases_promo_status_expiry_idx');
            $table->index(['studio_promo_code_id', 'customer_id', 'status'], 'customer_purchases_promo_customer_idx');
            $table->index(['studio_promo_code_id', 'promo_email_hash', 'status'], 'customer_purchases_promo_email_idx');
            $table->index(['studio_promo_code_id', 'promo_phone_hash', 'status'], 'customer_purchases_promo_phone_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_purchases', function (Blueprint $table) {
            $table->dropIndex('customer_purchases_promo_status_expiry_idx');
            $table->dropIndex('customer_purchases_promo_customer_idx');
            $table->dropIndex('customer_purchases_promo_email_idx');
            $table->dropIndex('customer_purchases_promo_phone_idx');
            $table->dropConstrainedForeignId('studio_promo_code_id');
            $table->dropColumn([
                'promo_name',
                'promo_code',
                'promo_discount_type',
                'promo_discount_value',
                'subtotal_cents',
                'discount_cents',
                'promo_email_hash',
                'promo_phone_hash',
            ]);
        });
    }
};
