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
        Schema::table('studio_promo_codes', function (Blueprint $table) {
            $table->unsignedInteger('buy_quantity')->nullable()->after('discount_value');
            $table->unsignedInteger('free_quantity')->nullable()->after('buy_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('studio_promo_codes', function (Blueprint $table) {
            $table->dropColumn(['buy_quantity', 'free_quantity']);
        });
    }
};
