<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('festival_ticket_orders', function (Blueprint $table) {
            $table->foreignId('festival_promo_code_id')->nullable()->after('festival_edition_id')->constrained()->restrictOnDelete();
            $table->string('promo_name')->nullable()->after('locale');
            $table->string('promo_code', 64)->nullable()->after('promo_name');
            $table->string('promo_discount_type')->nullable()->after('promo_code');
            $table->unsignedBigInteger('promo_discount_value')->nullable()->after('promo_discount_type');
            $table->unsignedBigInteger('subtotal_cents')->nullable()->after('promo_discount_value');
            $table->unsignedBigInteger('discount_cents')->default(0)->after('subtotal_cents');
            $table->char('promo_email_hash', 64)->nullable()->after('discount_cents');
            $table->char('promo_phone_hash', 64)->nullable()->after('promo_email_hash');

            $table->index(['festival_promo_code_id', 'status', 'expires_at'], 'festival_ticket_orders_promo_usage_idx');
            $table->index(['festival_promo_code_id', 'promo_email_hash'], 'festival_ticket_orders_promo_email_idx');
            $table->index(['festival_promo_code_id', 'promo_phone_hash'], 'festival_ticket_orders_promo_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::table('festival_ticket_orders', function (Blueprint $table) {
            $table->dropIndex('festival_ticket_orders_promo_usage_idx');
            $table->dropIndex('festival_ticket_orders_promo_email_idx');
            $table->dropIndex('festival_ticket_orders_promo_phone_idx');
            $table->dropConstrainedForeignId('festival_promo_code_id');
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
