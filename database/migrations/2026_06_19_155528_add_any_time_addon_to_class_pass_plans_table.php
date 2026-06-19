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
        Schema::table('class_pass_plans', function (Blueprint $table) {
            $table->boolean('allows_any_time')->default(false)->after('available_until_time');
            $table->unsignedInteger('any_time_addon_price_cents')->nullable()->after('allows_any_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_pass_plans', function (Blueprint $table) {
            $table->dropColumn([
                'allows_any_time',
                'any_time_addon_price_cents',
            ]);
        });
    }
};
