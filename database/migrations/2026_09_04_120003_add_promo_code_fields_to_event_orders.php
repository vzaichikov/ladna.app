<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_orders', function (Blueprint $table) {
            $table->foreignId('event_promo_code_id')->nullable()->after('event_id')->constrained()->restrictOnDelete();
            $table->string('promo_name')->nullable()->after('currency');
            $table->string('promo_code', 64)->nullable()->after('promo_name');
            $table->string('promo_discount_type', 16)->nullable()->after('promo_code');
            $table->unsignedBigInteger('promo_discount_value')->nullable()->after('promo_discount_type');
            $table->unsignedBigInteger('subtotal_cents')->nullable()->after('promo_discount_value');
            $table->unsignedBigInteger('discount_cents')->default(0)->after('subtotal_cents');
            $table->char('promo_email_hash', 64)->nullable()->after('discount_cents');
            $table->char('promo_phone_hash', 64)->nullable()->after('promo_email_hash');

            $table->index(['event_promo_code_id', 'status', 'expires_at'], 'event_orders_promo_status_expires_index');
            $table->index(['event_promo_code_id', 'promo_email_hash'], 'event_orders_promo_email_index');
            $table->index(['event_promo_code_id', 'promo_phone_hash'], 'event_orders_promo_phone_index');
        });

    }

    public function down(): void
    {
        Schema::table('event_orders', function (Blueprint $table) {
            $table->dropIndex('event_orders_promo_status_expires_index');
            $table->dropIndex('event_orders_promo_email_index');
            $table->dropIndex('event_orders_promo_phone_index');
            $table->dropConstrainedForeignId('event_promo_code_id');
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
