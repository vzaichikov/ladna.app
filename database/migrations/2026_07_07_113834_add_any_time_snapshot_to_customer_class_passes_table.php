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
        Schema::table('customer_class_passes', function (Blueprint $table) {
            $table->time('available_from_time')->nullable()->after('total_validity_days');
            $table->time('available_until_time')->nullable()->after('available_from_time');
            $table->boolean('allows_any_time')->default(false)->after('available_until_time');
            $table->unsignedInteger('any_time_addon_price_cents')->nullable()->after('allows_any_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_class_passes', function (Blueprint $table) {
            $table->dropColumn([
                'available_from_time',
                'available_until_time',
                'allows_any_time',
                'any_time_addon_price_cents',
            ]);
        });
    }
};
