<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('festival_ticket_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('subtotal_cents')->nullable()->after('total_cents');
            $table->unsignedBigInteger('discount_cents')->default(0)->after('subtotal_cents');
            $table->unsignedBigInteger('final_total_cents')->nullable()->after('discount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('festival_ticket_order_items', function (Blueprint $table) {
            $table->dropColumn(['subtotal_cents', 'discount_cents', 'final_total_cents']);
        });
    }
};
